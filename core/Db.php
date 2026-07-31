<?php

declare(strict_types=1);

namespace Portal;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper.
 *
 * Three jobs beyond "run SQL":
 *
 *  1. Table prefixing. Shared hosts sometimes give you one database for
 *     everything, so table names are written as `{videos}` and expanded to
 *     `wp_videos` (or just `videos`) here. Braces are never valid SQL, so the
 *     rewrite can't collide with real syntax.
 *  2. Query logging, which the query-monitor plugin reads. Always collected —
 *     it is a few microseconds and an array push — but only ever displayed
 *     when the plugin is active.
 *  3. Turning PDO's default silence into exceptions, and never letting a
 *     connection failure print credentials to the page.
 */
final class Db
{
    private static ?self $instance = null;

    private ?PDO $pdo = null;

    /** @var list<array{sql: string, ms: float, rows: int}> */
    private array $log = [];

    private int $queryCount = 0;
    private float $queryMs = 0.0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        private readonly string $prefix = ''
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('The database has not been configured yet.');
        }
        return self::$instance;
    }

    public static function setInstance(?self $db): void
    {
        self::$instance = $db;
    }

    /** Build from the values the installer wrote into config.php. */
    public static function fromConfig(Config $config): self
    {
        $host = $config->str('db_host', '127.0.0.1');
        $port = $config->int('db_port', 3306);
        $name = $config->str('db_name');
        $socket = $config->str('db_socket');

        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new self(
            $dsn,
            $config->str('db_user'),
            (string) $config->get('db_pass', ''),
            $config->str('db_prefix')
        );
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** Expand `{table}` tokens to real, prefixed, backtick-quoted names. */
    public function expand(string $sql): string
    {
        if (!str_contains($sql, '{')) {
            return $sql;
        }
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            fn (array $m): string => '`' . $this->prefix . $m[1] . '`',
            $sql
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements: emulation would let a crafted
                // parameter change the statement's meaning in edge cases.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // $this->dsn contains the host and database name and the exception
            // message can contain the user — neither belongs on a public page.
            throw new RuntimeException(
                'Could not connect to the database. Check the credentials in config.php.',
                0,
                $e
            );
        }

        // Strict mode surfaces truncation and bad dates as errors instead of
        // silently corrupting data. Shared hosts vary wildly in their default.
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");

        return $this->pdo;
    }

    /**
     * Run a statement and hand back the PDOStatement.
     *
     * @param list<mixed>|array<string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $sql = $this->expand($sql);
        $start = microtime(true);

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            $this->record($sql, $start, 0);
            throw $e;
        }

        $this->record($sql, $start, $stmt->rowCount());
        return $stmt;
    }

    /** @param list<mixed>|array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * First column of the first row, or null.
     *
     * @param list<mixed>|array<string, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * First column of every row.
     *
     * @param list<mixed>|array<string, mixed> $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Insert a row and return its id.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO {%s} (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->query($sql, array_values($data));
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Update rows matched by $where.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(
            static fn (string $c): string => "`{$c}` = ?",
            array_keys($data)
        ));
        $cond = implode(' AND ', array_map(
            static fn (string $c): string => "`{$c}` = ?",
            array_keys($where)
        ));
        $sql = sprintf('UPDATE {%s} SET %s WHERE %s', $table, $set, $cond);
        return $this->execute($sql, [...array_values($data), ...array_values($where)]);
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(
            static fn (string $c): string => "`{$c}` = ?",
            array_keys($where)
        ));
        return $this->execute(sprintf('DELETE FROM {%s} WHERE %s', $table, $cond), array_values($where));
    }

    /**
     * Run $fn inside a transaction, rolling back on any throwable.
     *
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        // Nested calls join the outer transaction rather than failing.
        if ($this->pdo()->inTransaction()) {
            return $fn($this);
        }

        $this->pdo()->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo()->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Escape the wildcards in a value used as a LIKE prefix.
     *
     * Parameter binding protects against injection but does nothing about `%`
     * and `_`, which are still wildcards inside a bound LIKE value. The
     * category tree matches on a path prefix, so an unescaped `_` in a path
     * would silently match siblings — a subtle wrong-results bug rather than a
     * loud failure.
     */
    public function escapeLike(string $value, string $escape = '\\'): string
    {
        return str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $value
        );
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->pdo()->query('SELECT 1 FROM ' . $this->expand("{{$table}}") . ' LIMIT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    // ------------------------------------------------------------ query log

    private function record(string $sql, float $start, int $rows): void
    {
        $ms = (microtime(true) - $start) * 1000;
        $this->queryCount++;
        $this->queryMs += $ms;

        // Cap the log so a pathological page can't exhaust memory.
        if (count($this->log) < 500) {
            $this->log[] = ['sql' => $sql, 'ms' => round($ms, 3), 'rows' => $rows];
        }
    }

    /** @return list<array{sql: string, ms: float, rows: int}> */
    public function log(): array
    {
        return $this->log;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function queryMs(): float
    {
        return round($this->queryMs, 2);
    }
}
