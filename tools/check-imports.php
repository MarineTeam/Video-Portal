<?php
/**
 * Find class references a file cannot resolve.
 *
 *   php tools/check-imports.php
 *
 * THE BUG THIS EXISTS FOR
 *
 * A class named without an import resolves against the CURRENT namespace, so
 * `DownloadPolicy::allows(...)` written in a controller means
 * `Portal\Controllers\DownloadPolicy` — a class that does not exist. PHP does
 * not complain until that line runs.
 *
 *   php -l parses the file and never resolves a name.
 *   tools/load-all.php loads the class and never executes a method body.
 *
 * So both pass, and the failure surfaces as a 500 on whichever screen calls the
 * method — or, worse, silently: `$x instanceof BunnyStreamProvider` written
 * without the import is not an error at all. It is simply always FALSE, and the
 * code takes its fallback path forever while every check in the project stays
 * green.
 *
 * That has now happened three times here: the instanceof in FeedController, the
 * static call in AdminController that fataled the video edit screen and took
 * 117 downstream smoke checks with it, and the interface written between them
 * partly to avoid it. Three is enough to stop remembering and start checking.
 *
 * HOW IT WORKS
 *
 * The tokenizer, not a regular expression. `Foo::` appears inside the HTML
 * heredocs in AdminView and inside plenty of strings and comments, and a regex
 * cannot tell those from code — a checker with false positives is one people
 * turn off. token_get_all hands back strings, heredocs and comments as their
 * own token types, so they are skipped for free.
 *
 * Each name is then resolved the way PHP resolves it — fully qualified, via an
 * import, via an import of its first segment, or against the current namespace
 * — and the result is looked up with class_exists, which runs the real
 * autoloader. So this asks the same question the runtime asks, at the point the
 * runtime cannot be made to ask it early.
 *
 * WHAT IT DOES NOT COVER
 *
 * Parameter, property and return TYPES. They resolve the same way and can be
 * just as wrong, but a wrong one is far less quiet: it fails the moment
 * anything passes an argument, which a test usually does. The three forms
 * checked here are the ones that hide.
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

/**
 * Names that are legal and resolve to nothing a lookup can confirm.
 *
 * `self`, `static` and `parent` are keywords. `class` appears as `Foo::class`
 * on the right of the operator, never the left.
 */
const RELATIVE_KEYWORDS = ['self', 'static', 'parent', 'class'];

/*
 * Bundled plugin classes are not on the PSR-4 path — a plugin's own plugin.php
 * requires them, and so does tools/load-all.php. Without doing the same here,
 * every reference inside every plugin resolves to nothing and the whole report
 * is false positives, which is how a checker gets switched off in its first
 * week.
 *
 * Failures are swallowed: a plugin class that cannot even be required is
 * exactly what load-all.php reports, with a stack trace, and two tools
 * shouting about one file is noise.
 */
foreach ((array) glob(PORTAL_PLUGINS . '/*/src/*.php') as $pluginClass) {
    if (is_string($pluginClass)) {
        try {
            require_once $pluginClass;
        } catch (Throwable) {
            // load-all.php's problem, not this script's.
        }
    }
}

/** @var list<string> $problems */
$problems = [];
$scanned = 0;
$references = 0;

$roots = [PORTAL_CORE, PORTAL_PLUGINS, PORTAL_ROOT . DIRECTORY_SEPARATOR . 'tests'];

$paths = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }
}

/*
 * Everything these files DECLARE, gathered before anything is checked.
 *
 * class_exists runs the autoloader, which maps a name to a file of that name —
 * so a class declared at the bottom of a file named after a different class is
 * invisible to it. Two test doubles are written that way, and without this pass
 * the tool reports both as broken while they work perfectly.
 *
 * A checker whose first run is wrong about working code teaches people that
 * its output is noise, which is worse than not having it.
 */
$declared = [];
foreach ($paths as $path) {
    foreach (declarationsIn($path) as $fqn) {
        $declared[strtolower($fqn)] = true;
    }
}

foreach ($paths as $path) {
    $scanned++;
    $found = checkFile($path, $declared);
    $references += $found['references'];

    foreach ($found['problems'] as $problem) {
        $problems[] = $problem;
    }
}

/**
 * Every class, interface, trait and enum one file declares, fully qualified.
 *
 * @return list<string>
 */
function declarationsIn(string $path): array
{
    $source = @file_get_contents($path);
    if ($source === false) {
        return [];
    }

    $tokens = token_get_all($source);
    $namespace = '';
    $out = [];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = readName($tokens, $i);
            continue;
        }

        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        // `Foo::class` and `new class {` name nothing. readName() answers ''
        // for both, because neither is followed by an identifier.
        $name = readName($tokens, $i);
        if ($name !== '') {
            $out[] = $namespace === '' ? $name : $namespace . '\\' . $name;
        }
    }

    return $out;
}

/**
 * @param array<string, true> $declared
 * @return array{problems: list<string>, references: int}
 */
function checkFile(string $path, array $declared): array
{
    $source = @file_get_contents($path);
    if ($source === false) {
        return ['problems' => ["{$path}: could not be read"], 'references' => 0];
    }

    $tokens = token_get_all($source);

    $namespace = '';
    /** @var array<string, string> $imports alias (lowercased) => fully qualified */
    $imports = [];
    /** @var list<array{name: string, line: int}> $refs */
    $refs = [];

    $depth = 0;

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
            continue;
        }

        [$id] = $token;

        if ($id === T_NAMESPACE) {
            $namespace = readName($tokens, $i);
            continue;
        }

        /*
         * `use` means three different things. At the top level it is an import.
         * Inside a class body it is a trait, which is a real class reference and
         * is collected as one below. After a closure's parameter list it is a
         * variable capture, which names nothing.
         */
        if ($id === T_USE) {
            if ($depth > 0) {
                continue;
            }

            $next = peek($tokens, $i);
            if ($next === '(') {
                continue;
            }

            foreach (readImports($tokens, $i) as $alias => $fqn) {
                $imports[$alias] = $fqn;
            }
            continue;
        }

        // new Foo, new Foo\Bar, new \Foo — but not `new class`, `new $var`.
        if ($id === T_NEW) {
            $name = readName($tokens, $i);
            if ($name !== '') {
                $refs[] = ['name' => $name, 'line' => $token[2]];
            }
            continue;
        }

        if ($id === T_INSTANCEOF) {
            $name = readName($tokens, $i);
            if ($name !== '') {
                $refs[] = ['name' => $name, 'line' => $token[2]];
            }
            continue;
        }

        // Foo::something — the name sits to the LEFT of the operator.
        if (
            ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED)
            && peek($tokens, $i) === '::'
        ) {
            $refs[] = ['name' => $token[1], 'line' => $token[2]];
        }
    }

    $problems = [];
    $counted = 0;

    foreach ($refs as $ref) {
        $name = ltrim($ref['name'], '\\');
        if ($name === '' || in_array(strtolower($name), RELATIVE_KEYWORDS, true)) {
            continue;
        }

        $counted++;

        if (resolves($name, $ref['name'], $namespace, $imports, $declared)) {
            continue;
        }

        $problems[] = sprintf(
            "%s:%d\n    %s  ->  %s\n    Not imported, and no such class in %s.",
            relative($path),
            $ref['line'],
            $ref['name'],
            candidate($ref['name'], $namespace, $imports),
            $namespace === '' ? 'the global namespace' : $namespace
        );
    }

    return ['problems' => $problems, 'references' => $counted];
}

/**
 * Can PHP find this name from here?
 *
 * @param array<string, string> $imports
 * @param array<string, true> $declared
 */
function resolves(
    string $bare,
    string $written,
    string $namespace,
    array $imports,
    array $declared
): bool {
    $candidate = candidate($written, $namespace, $imports);

    return isset($declared[strtolower($candidate)])
        || isset($declared[strtolower($bare)])
        || exists($candidate)
        // A name PHP would treat as namespace-relative may still be a global
        // class if the file declares no namespace at all, which candidate()
        // already covers — this second chance is for a bare name in a
        // namespaced file that genuinely means a global class, which PHP does
        // NOT fall back to for classes. Checked anyway and reported as fine,
        // because a checker that flags working code gets switched off.
        || exists($bare);
}

/**
 * Where PHP would look for this name.
 *
 * @param array<string, string> $imports
 */
function candidate(string $written, string $namespace, array $imports): string
{
    // Already fully qualified: PHP looks nowhere else.
    if (str_starts_with($written, '\\')) {
        return ltrim($written, '\\');
    }

    $segments = explode('\\', $written);
    $first = strtolower($segments[0]);

    // An import of the first segment rewrites the head of the name.
    if (isset($imports[$first])) {
        $segments[0] = $imports[$first];

        return implode('\\', $segments);
    }

    return $namespace === '' ? $written : $namespace . '\\' . $written;
}

/** Repo-relative, forward slashes, so the path is clickable on any platform. */
function relative(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', PORTAL_ROOT) . '/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

function exists(string $fqn): bool
{
    try {
        return class_exists($fqn)
            || interface_exists($fqn)
            || trait_exists($fqn)
            || enum_exists($fqn);
    } catch (Throwable) {
        /*
         * Autoloading it threw. That is a real problem, and it is the one
         * tools/load-all.php exists to report — with a stack trace this script
         * cannot improve on. Treated as resolved here so the two tools do not
         * both shout about the same file.
         */
        return true;
    }
}

/** The next meaningful token after $i, as a string. */
function peek(array $tokens, int $from): string
{
    for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($token) ? $token[1] : $token;
    }

    return '';
}

/** Read the class name following `new` / `instanceof` / `namespace`. */
function readName(array $tokens, int $from): string
{
    for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (!is_array($token)) {
            return '';
        }

        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return $token[1];
        }

        // `new class {`, `new $factory`, `namespace {` — nothing named.
        return '';
    }

    return '';
}

/**
 * Read one `use` statement, which may be a group or carry aliases.
 *
 * @return array<string, string> alias (lowercased) => fully qualified
 */
function readImports(array $tokens, int $from): array
{
    $text = '';

    for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === ';') {
                break;
            }
            $text .= $token;
            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        // `use function foo;` and `use const BAR;` import no class.
        if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
            return [];
        }

        $text .= $token[1];
    }

    $text = trim($text);
    if ($text === '') {
        return [];
    }

    // Group syntax: Foo\Bar\{Baz, Qux as Quux}
    $prefix = '';
    if (preg_match('/^(.*?)\\\\?\{(.*)\}$/s', $text, $m) === 1) {
        $prefix = rtrim($m[1], '\\') . '\\';
        $text = $m[2];
    }

    $out = [];
    foreach (explode(',', $text) as $clause) {
        $clause = trim($clause);
        if ($clause === '') {
            continue;
        }

        if (preg_match('/^(.*?)\s+as\s+(\S+)$/is', $clause, $m) === 1) {
            $fqn = $prefix . trim($m[1]);
            $alias = trim($m[2]);
        } else {
            $fqn = $prefix . $clause;
            $segments = explode('\\', $fqn);
            $alias = end($segments);
        }

        $out[strtolower($alias)] = ltrim($fqn, '\\');
    }

    return $out;
}

// ------------------------------------------------------------------ report

if ($problems === []) {
    printf(
        "Checked %d reference(s) across %d file(s).\nEvery class reference resolves.\n",
        $references,
        $scanned
    );
    exit(0);
}

printf("Checked %d reference(s) across %d file(s).\n\n", $references, $scanned);

foreach ($problems as $problem) {
    echo $problem . "\n\n";
}

printf(
    "%d unresolvable class reference(s).\n\nEach one is a fatal error the moment that line runs, or — for "
    . "instanceof — a comparison that is silently always false.\n",
    count($problems)
);

exit(1);
