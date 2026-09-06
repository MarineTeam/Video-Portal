<?php
/**
 * Find `$this->something()` calls where the method does not exist.
 *
 *   php tools/check-calls.php
 *
 * THE BUG THIS EXISTS FOR
 *
 * `$this->can($capability)` in a controller, where the base class offers
 * `require()` and `guard()->can()` and nothing called `can()`. PHP does not
 * complain until that line runs.
 *
 *   php -l                  parses the file; never resolves a method.
 *   tools/load-all.php      loads the class; never executes a method body.
 *   tools/check-imports.php resolves class NAMES; says nothing about methods.
 *
 * So all three pass and the failure is a 500 on whichever screen reaches the
 * line. That happened twice in one afternoon: PrayerController::can() took out
 * the whole prayer wall and seven checks with it, and FormController::canManage()
 * had the identical bug on a branch NOTHING drove — a closed form viewed by
 * somebody who can edit it. The smoke run found the first and would never have
 * found the second.
 *
 * Two is enough to stop remembering and start checking.
 *
 * HOW IT WORKS
 *
 * The tokenizer, like check-imports.php and for the same reason: `$this->x(`
 * appears inside the HTML heredocs the admin views are built from, and a regex
 * cannot tell those from code. A checker with false positives is one people
 * turn off.
 *
 * Each class is loaded — the autoloader is real, so parents and traits come
 * with it — and every method called on `$this`, `self::` or `static::` is
 * looked up with method_exists, which sees inherited and trait methods too.
 *
 * WHAT IT DELIBERATELY SKIPS
 *
 * - Classes declaring __call or __callStatic. A missing method there is not
 *   missing.
 * - Dynamic names: `$this->$name()`, `$this->{$x}()`. Nothing static can say.
 * - Anonymous classes and closures rebound with Closure::bind, where `$this` is
 *   something else entirely. Both are found by looking for `function (` with a
 *   `use` clause is not reliable, so instead: a call is only reported when the
 *   file declares exactly ONE class, which is true of every file in core/ and
 *   plugins/ and is the conservative reading.
 *
 * It under-reports rather than over-reports, which is the right direction: a
 * missed call costs what it costs today, and a false positive costs the tool.
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

$roots = [
    dirname(__DIR__) . '/core',
    dirname(__DIR__) . '/plugins',
];

$problems = [];
$checked = 0;
$files = 0;

foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $source = (string) file_get_contents($path);
        $tokens = @token_get_all($source);

        if ($tokens === false) {
            continue;
        }

        $class = classIn($tokens);

        if ($class === null || !class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        // A class that answers anything is not a class this can check.
        if ($reflection->hasMethod('__call') || $reflection->hasMethod('__callStatic')) {
            continue;
        }

        $files++;

        foreach (callsIn($tokens) as [$method, $line]) {
            $checked++;

            if (!method_exists($class, $method)) {
                $problems[] = sprintf(
                    '%s:%d  %s::%s() does not exist',
                    str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $path),
                    $line,
                    $reflection->getShortName(),
                    $method
                );
            }
        }
    }
}

/**
 * The one class a file declares, or null if it declares none or several.
 *
 * Several means anonymous classes or a test double living beside the subject,
 * and then `$this` inside the file is ambiguous — so the file is skipped rather
 * than guessed at.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function classIn(array $tokens): ?string
{
    $namespace = '';
    $names = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = '';

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $tokens[$j][1];
                    break;
                }

                if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                    break;
                }
            }

            continue;
        }

        if ($token[0] !== T_CLASS) {
            continue;
        }

        /*
         * `Foo::class` is not a declaration, and neither is `new class {}`.
         *
         * The `::` arrives as an ARRAY token — [T_DOUBLE_COLON, '::'] — not as
         * the string '::'. Comparing against the string matched nothing, so
         * every file containing `Something::class` looked like it declared two
         * classes, classIn() returned null, and the file was skipped in
         * silence. That is most of core/: the first version of this tool
         * checked 176 files and reported "every call resolves" while never
         * looking at the file holding the bug it was written for.
         */
        for ($back = $i - 1; $back >= 0; $back--) {
            if (is_array($tokens[$back]) && $tokens[$back][0] === T_WHITESPACE) {
                continue;
            }

            $isDoubleColon = $tokens[$back] === '::'
                || (is_array($tokens[$back]) && $tokens[$back][0] === T_DOUBLE_COLON);

            if ($isDoubleColon || (is_array($tokens[$back]) && $tokens[$back][0] === T_NEW)) {
                continue 2;
            }

            break;
        }

        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $names[] = $namespace === '' ? $tokens[$j][1] : $namespace . '\\' . $tokens[$j][1];
                break;
            }
        }
    }

    return count($names) === 1 ? $names[0] : null;
}

/**
 * Every method called on $this, self:: or static::, with its line.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @return list<array{0: string, 1: int}>
 */
function callsIn(array $tokens): array
{
    $out = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            continue;
        }

        $onSelf = $token[0] === T_VARIABLE && $token[1] === '$this';
        $onClass = ($token[0] === T_STRING && in_array(strtolower($token[1]), ['self', 'static'], true))
            || $token[0] === T_STATIC;

        if (!$onSelf && !$onClass) {
            continue;
        }

        $arrow = next_significant($tokens, $i + 1);

        if ($arrow === null) {
            continue;
        }

        [$arrowToken, $arrowIndex] = $arrow;

        $isArrow = is_array($arrowToken)
            && in_array($arrowToken[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
        $isPaamayim = is_array($arrowToken) && $arrowToken[0] === T_DOUBLE_COLON;

        if ((!$isArrow && !$isPaamayim) || ($onSelf && $isPaamayim) || ($onClass && $isArrow)) {
            continue;
        }

        $name = next_significant($tokens, $arrowIndex + 1);

        // A dynamic name — $this->$method() — is not something to guess about.
        if ($name === null || !is_array($name[0]) || $name[0][0] !== T_STRING) {
            continue;
        }

        $paren = next_significant($tokens, $name[1] + 1);

        // A property read, not a call.
        if ($paren === null || $paren[0] !== '(') {
            continue;
        }

        $out[] = [$name[0][1], $name[0][2]];
    }

    return $out;
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @return array{0: array{0: int, 1: string, 2: int}|string, 1: int}|null
 */
function next_significant(array $tokens, int $from): ?array
{
    $count = count($tokens);

    for ($i = $from; $i < $count; $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return [$tokens[$i], $i];
    }

    return null;
}

printf("Checked %d call(s) across %d file(s).\n", $checked, $files);

if ($problems === []) {
    echo "Every call resolves.\n";
    exit(0);
}

foreach ($problems as $problem) {
    echo '  ' . $problem . "\n";
}

printf("\n%d call(s) go nowhere. Each is a fatal the moment that line runs.\n", count($problems));
exit(1);
