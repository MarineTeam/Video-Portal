<?php

declare(strict_types=1);

namespace Portal\Http;

/**
 * An immutable snapshot of the incoming request.
 *
 * Note what this class deliberately does NOT expose: a "current base URL"
 * derived from Host. Anything that builds a URL for an email or a redirect
 * target reads Config::baseUrl() instead. $_SERVER['HTTP_HOST'] is attacker-
 * controlled on most shared hosts and has already caused one poisoning bug in
 * a predecessor app.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers  lower-cased names
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly array $server = [],
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Browsers can only send GET and POST from a form, so honour the
        // conventional override field for DELETE/PATCH/PUT.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self(
            method:  $method,
            path:    self::resolvePath(),
            query:   $_GET,
            post:    $_POST,
            headers: self::captureHeaders(),
            cookies: $_COOKIE,
            files:   $_FILES,
            server:  $_SERVER,
        );
    }

    /**
     * The path portion of the request, normalized to a leading slash and no
     * trailing slash, with any install subdirectory stripped.
     */
    private static function resolvePath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rawurldecode($path);

        // When the app lives in a subdirectory, SCRIPT_NAME is
        // /sub/index.php and every path arrives prefixed with /sub.
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        // dirname('/index.php') is '/', which we normalized to '' above.
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        // Deployment (B) rewrites into public/; don't let that leak into routes.
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        }

        $path = '/' . trim($path, '/');
        return $path;
    }

    /** @return array<string, string> */
    private static function captureHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    /** Query-string value as a trimmed string. */
    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** Form field as a trimmed string. */
    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function inputBool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * A form field that is expected to be an array (checkbox groups, id lists).
     *
     * @return list<string>
     */
    public function inputArray(string $key): array
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = trim((string) $item);
            }
        }
        return $out;
    }

    public function rawBody(): string
    {
        return $this->rawBody ?? (string) file_get_contents('php://input');
    }

    /**
     * Decoded JSON body, or an empty array when the body isn't JSON.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Merged view of JSON body and form fields, so an endpoint can accept
     * either without branching. Form fields win on collision.
     */
    public function data(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        return $json[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('accept', '') ?? '');
        $contentType = strtolower($this->header('content-type', '') ?? '');

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || $this->isAjax()
            || str_starts_with($this->path, '/api/');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '') ?? '') === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off') {
            return true;
        }
        // Shared hosts terminate TLS at a proxy and forward this instead.
        return strtolower($this->header('x-forwarded-proto', '') ?? '') === 'https';
    }

    /**
     * Best-effort client IP, used only for rate limiting.
     *
     * Proxy headers are trusted here because on shared hosting the app is
     * always behind the host's front-end. That trust is acceptable precisely
     * because nothing security-critical depends on it — a spoofed IP can only
     * let someone evade their own rate limit, never gain access.
     */
    public function ip(): string
    {
        foreach (['cf-connecting-ip', 'true-client-ip', 'x-real-ip'] as $header) {
            $value = $this->header($header);
            if ($value !== null && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }

        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== null) {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return $remote !== '' ? $remote : '0.0.0.0';
    }

    /**
     * ISO-3166-1 alpha-2 country, if the host or CDN told us. Empty when
     * unknown — the geo check treats unknown as "allow" (fails open) so a
     * host that doesn't send the header doesn't lock everyone out.
     */
    public function country(): string
    {
        foreach (['cf-ipcountry', 'x-vercel-ip-country', 'x-geo-country', 'x-country-code'] as $header) {
            $value = strtoupper(trim($this->header($header, '') ?? ''));
            if ($value !== '' && $value !== 'XX' && strlen($value) === 2) {
                return $value;
            }
        }
        return '';
    }

    public function userAgent(): string
    {
        return $this->header('user-agent', '') ?? '';
    }

    /**
     * A same-origin path safe to redirect to after login.
     *
     * Anything absolute, protocol-relative, or backslash-prefixed is rejected
     * outright: an open redirect on the login flow is how phishing links get
     * laundered through a trusted domain.
     */
    public function safeReturnTo(string $default = '/'): string
    {
        $candidate = $this->input('returnTo') ?? $this->query('returnTo') ?? '';
        return self::sanitizeReturnTo($candidate, $default);
    }

    public static function sanitizeReturnTo(string $candidate, string $default = '/'): string
    {
        $candidate = trim($candidate);

        if ($candidate === '' || $candidate[0] !== '/') {
            return $default;
        }
        // "//evil.com" and "/\evil.com" are both browser-honoured absolute URLs.
        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return $default;
        }
        if (str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
            return $default;
        }
        return $candidate;
    }

    /** Replace the path, keeping everything else. Used when routing internally. */
    public function withPath(string $path): self
    {
        return new self(
            $this->method,
            '/' . trim($path, '/'),
            $this->query,
            $this->post,
            $this->headers,
            $this->cookies,
            $this->files,
            $this->server,
            $this->rawBody,
        );
    }
}
