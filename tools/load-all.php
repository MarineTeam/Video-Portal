<?php
/**
 * Load every application class.
 *
 * php -l parses a file in isolation and never resolves its parent, so it
 * cannot see an inheritance error. A method that collides with an
 * incompatible one on the base class is a FATAL at class-resolution time, and
 * it reached a live host looking like this:
 *
 *   Declaration of AdminController::themes(Request $request): Response must be
 *   compatible with Controller::themes(): ThemeManager
 *
 * Every file parsed cleanly. The whole test suite passed, because no test
 * happened to instantiate that particular controller.
 *
 * This loads each class for real, which forces PHP to resolve the hierarchy.
 *
 *   php tools/load-all.php
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

$failures = [];
$loaded = 0;

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PORTAL_CORE, FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(PORTAL_CORE) + 1));

    // helpers.php declares functions, not a class.
    if (!preg_match('/^[A-Z]/', basename($relative))) {
        continue;
    }

    $class = 'Portal\\' . str_replace('/', '\\', substr($relative, 0, -4));

    try {
        // class_exists triggers the autoloader, which resolves parents,
        // interfaces, and traits — the step php -l skips.
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            $failures[] = "{$class}: file exists but the symbol was not declared";
            continue;
        }
        $loaded++;
    } catch (Throwable $e) {
        $failures[] = sprintf('%s: %s: %s', $class, $e::class, $e->getMessage());
    }
}

/*
 * Bundled plugins, too.
 *
 * Their classes extend core ones — a plugin admin page extends PluginPage
 * extends Controller — so they are exposed to exactly the inheritance fatal
 * this script exists to catch, and they are the LEAST likely to be caught any
 * other way: no test instantiates an admin screen, and a plugin that fatals on
 * load is silently deactivated rather than failing loudly.
 *
 * Files are required directly rather than autoloaded, because plugins are not
 * on the PSR-4 path. That is also what a plugin's own plugin.php does.
 */
foreach ((array) glob(PORTAL_PLUGINS . '/*/src/*.php') as $file) {
    if (!is_string($file)) {
        continue;
    }

    $slug = basename(dirname(dirname($file)));
    $class = 'Portal\\Plugins\\' . ucfirst($slug) . '\\' . basename($file, '.php');

    try {
        require_once $file;

        if (!class_exists($class, false) && !interface_exists($class, false)) {
            $failures[] = sprintf(
                '%s: %s does not declare it. Bundled plugin classes must be namespaced '
                . 'Portal\\Plugins\\<Slug> and named after their file.',
                $class,
                str_replace(PORTAL_ROOT . DIRECTORY_SEPARATOR, '', $file)
            );
            continue;
        }

        $loaded++;
    } catch (Throwable $e) {
        $failures[] = sprintf('%s: %s: %s', $class, $e::class, $e->getMessage());
    }
}

/*
 * Controllers are the specific place this bites, because they inherit shared
 * plumbing and their public methods are route handlers. Check every public
 * method that a route could dispatch to.
 */
foreach (get_declared_classes() as $class) {
    if (!str_starts_with($class, 'Portal\\Controllers\\')) {
        continue;
    }

    try {
        $reflection = new ReflectionClass($class);
    } catch (Throwable $e) {
        $failures[] = "{$class}: {$e->getMessage()}";
        continue;
    }

    if ($reflection->isAbstract()) {
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        // A route handler takes (Request) or (Request, array) and returns a
        // Response. Anything else on a controller is a naming accident waiting
        // to shadow a helper on the base class.
        $returnType = $method->getReturnType();
        $returnName = $returnType instanceof ReflectionNamedType ? $returnType->getName() : '(none)';

        if ($returnName !== 'Portal\Http\Response') {
            $failures[] = sprintf(
                '%s::%s() is public on a controller but returns %s, not a Response. '
                . 'Public controller methods are route handlers; make it protected or private.',
                $class,
                $method->getName(),
                $returnName
            );
        }
    }
}

/*
 * Test classes too, when PHPUnit is available.
 *
 * They inherit from a base class with a large surface, and a helper named
 * after one of its final methods is the same fatal in a different costume —
 * a private helper called callback() collides with PHPUnit's final
 * Assert::callback(), and the whole file becomes unloadable. PHPUnit reports
 * that as a zero-byte output and exit 255, with no message at all.
 */
if (class_exists(\PHPUnit\Framework\TestCase::class)) {
    $testRoot = PORTAL_ROOT . '/tests';

    if (is_dir($testRoot)) {
        $testFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($testFiles as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php' || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($testRoot) + 1));
            $class = 'Portal\\Tests\\' . str_replace('/', '\\', substr($relative, 0, -4));

            try {
                if (!class_exists($class)) {
                    $failures[] = "{$class}: file exists but the class was not declared";
                    continue;
                }
                $loaded++;
            } catch (Throwable $e) {
                $failures[] = sprintf('%s: %s: %s', $class, $e::class, $e->getMessage());
            }
        }
    }
}

echo "Loaded {$loaded} class(es).\n";

if ($failures !== []) {
    echo "\n";
    foreach ($failures as $failure) {
        echo "  FAIL {$failure}\n";
    }
    echo "\n" . count($failures) . " problem(s).\n";
    exit(1);
}

echo "Every class resolves.\n";
