<?php

declare(strict_types=1);

namespace Portal\Http;

/**
 * A single route: pattern, handler, and the middleware chain it runs behind.
 */
final class Route
{
    /** @var list<string> */
    public array $paramNames = [];

    private string $regex;

    /**
     * @param list<string>            $methods
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<string>            $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly mixed $handler,
        public array $middleware = [],
        public readonly ?string $name = null,
    ) {
        $this->regex = $this->compile($pattern);
    }

    /**
     * Turn `/watch/{id}` into a regex, capturing named parameters.
     *
     * `{id}` matches one path segment. `{path:.*}` (an explicit pattern after
     * a colon) matches whatever you tell it to — used for the theme asset
     * route, which needs to match nested paths.
     */
    private function compile(string $pattern): string
    {
        // Substitute first, quote second. Doing it the other way round means
        // preg_quote has already escaped the braces and the placeholder
        // pattern no longer matches.
        $captures = [];

        // The marker must survive preg_quote untouched, so it can only contain
        // alphanumerics — preg_quote escapes NUL and every punctuation char.
        $marker = 'x' . bin2hex(random_bytes(6)) . 'x';

        $tokenized = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function (array $m) use (&$captures, $marker): string {
                $this->paramNames[] = $m[1];
                $index = count($captures);
                // Default: one path segment. An explicit `{name:regex}` wins,
                // which is how the theme-asset route matches nested paths.
                $captures[$index] = '(' . ($m[2] ?? '[^/]+') . ')';
                return $marker . $index . $marker;
            },
            $pattern
        ) ?? $pattern;

        $quoted = preg_quote($tokenized, '#');

        foreach ($captures as $index => $capture) {
            $quoted = str_replace($marker . $index . $marker, $capture, $quoted);
        }

        return '#^' . $quoted . '$#';
    }

    /**
     * @return array<string, string>|null Matched params, or null if no match.
     */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }
        array_shift($matches);

        $params = [];
        foreach ($this->paramNames as $index => $name) {
            $params[$name] = $matches[$index] ?? '';
        }
        return $params;
    }

    public function acceptsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }
}
