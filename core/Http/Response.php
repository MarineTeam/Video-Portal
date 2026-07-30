<?php

declare(strict_types=1);

namespace Portal\Http;

/**
 * A response, built up and sent once at the end of the request.
 *
 * Buffering the whole body before sending is what lets the share gate return
 * byte-for-byte identical responses for every failure case — we can compare
 * and normalize before anything reaches the wire.
 */
final class Response
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return (new self($html, $status))->header('Content-Type', 'text/html; charset=utf-8');
    }

    public static function text(string $text, int $status = 200): self
    {
        return (new self($text, $status))->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{"error":"Could not encode the response."}';
            $status = 500;
        }
        return (new self($encoded, $status))->header('Content-Type', 'application/json; charset=utf-8');
    }

    /** A JSON error in the shape every API route in this app returns. */
    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(['error' => $message] + $extra, $status);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return (new self('', $status))->header('Location', $location);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * 405 with the Allow header populated — required by the spec and relied on
     * by the API tests.
     *
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return self::error('Method not allowed', 405)
            ->header('Allow', implode(', ', $allowed));
    }

    public function header(string $name, string $value, bool $replace = true): self
    {
        $key = $this->normalizeHeaderName($name);
        // Same reasoning as the name: a CR/LF in a Location value is a
        // response-splitting hole, and Location values are frequently built
        // from user-supplied return paths.
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = [$value];
        } else {
            $this->headers[$key][] = $value;
        }
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Queue a cookie.
     *
     * Secure/HttpOnly/SameSite default to the safe choice; callers opt out
     * explicitly. The share gate needs a path-scoped cookie, which is why
     * `path` is a normal option rather than being forced to '/'.
     *
     * @param array<string, mixed> $options
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = [
            'name'    => $name,
            'value'   => $value,
            'options' => $options + [
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
        return $this;
    }

    public function clearCookie(string $name, string $path = '/'): self
    {
        return $this->cookie($name, '', ['expires' => time() - 3600, 'path' => $path]);
    }

    /** Tell shared caches and browsers this response is per-user. */
    public function private(): self
    {
        return $this
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function send(bool $secure = true): void
    {
        if (headers_sent($file, $line)) {
            // Something echoed early — usually a stray blank line after a
            // closing PHP tag in a plugin. Emit the body anyway so the page
            // isn't blank. (And note: a literal close-tag inside a // comment
            // would end PHP mode right here, which is why it's spelled out.)
            error_log("Portal: headers already sent at {$file}:{$line}");
            echo $this->body;
            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $first);
                $first = false;
            }
        }

        foreach ($this->cookies as $cookie) {
            $options = $cookie['options'];
            setcookie($cookie['name'], $cookie['value'], [
                'expires'  => (int) ($options['expires'] ?? 0),
                'path'     => (string) ($options['path'] ?? '/'),
                'domain'   => (string) ($options['domain'] ?? ''),
                'secure'   => (bool) ($options['secure'] ?? $secure),
                'httponly' => (bool) ($options['httponly'] ?? true),
                'samesite' => (string) ($options['samesite'] ?? 'Lax'),
            ]);
        }

        // 204 and 304 must not carry a body.
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }

    private function normalizeHeaderName(string $name): string
    {
        // Header injection guard: a newline in a header value or name splits
        // the response. Strip rather than throw — the caller is usually
        // passing through user data (a filename, a redirect target).
        $name = str_replace(["\r", "\n", "\0"], '', $name);
        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $name)
        ));
    }
}
