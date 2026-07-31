<?php

declare(strict_types=1);

namespace Portal\Support;

use RuntimeException;

/**
 * Minimal outbound HTTP client built directly on curl.
 *
 * Guzzle would be the obvious choice, but every vendored package is a package
 * that can only be security-patched by shipping a whole new app release — and
 * the total surface we need is "POST some JSON, GET some JSON, with a timeout".
 * curl is present on effectively every PHP host and this stays under 150 lines.
 *
 * Timing is recorded for the query-monitor plugin, which reports outbound calls
 * alongside database queries; a slow page is far more often bunny.net than SQL.
 */
final class Http
{
    /** @var list<array{method: string, url: string, status: int, ms: float}> */
    private static array $log = [];

    private static int $callCount = 0;
    private static float $totalMs = 0.0;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public static function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        array $options = []
    ): HttpResponse {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'The curl PHP extension is not available. Most hosts enable it from the control panel.'
            );
        }

        $timeout = (int) ($options['timeout'] ?? 15);
        $start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            // Redirects are off by default: an API that redirects is either
            // misconfigured or hostile, and silently following one can leak an
            // Authorization header to a different host.
            CURLOPT_FOLLOWLOCATION => (bool) ($options['follow'] ?? false),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'VideoPortal/' . PORTAL_VERSION,
            CURLOPT_ENCODING       => '',
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($headers !== []) {
            $formatted = [];
            foreach ($headers as $name => $value) {
                // Header injection guard — values here can come from config.
                $formatted[] = str_replace(["\r", "\n"], '', "{$name}: {$value}");
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $ms = (microtime(true) - $start) * 1000;
        self::$callCount++;
        self::$totalMs += $ms;
        if (count(self::$log) < 200) {
            self::$log[] = [
                'method' => strtoupper($method),
                // Never log the query string: signed URLs carry tokens.
                'url'    => (string) (parse_url($url, PHP_URL_SCHEME) . '://'
                            . parse_url($url, PHP_URL_HOST)
                            . parse_url($url, PHP_URL_PATH)),
                'status' => $status,
                'ms'     => round($ms, 2),
            ];
        }

        if ($errno !== 0 || !is_string($raw)) {
            return new HttpResponse(0, '', [], self::explainCurlError($errno, $error, $url));
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return new HttpResponse($status, $responseBody, self::parseHeaders($rawHeaders));
    }

    /** @param array<string, string> $headers */
    public static function get(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return self::request('GET', $url, null, $headers, $options);
    }

    /**
     * POST a JSON body. The Content-Type is set here so no caller can forget it
     * — bunny.net and Resend both fail unhelpfully without it.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function postJson(string $url, array $payload, array $headers = [], array $options = []): HttpResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Could not encode the request body as JSON.');
        }
        return self::request('POST', $url, $body, $headers + [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $options);
    }

    /**
     * Turn a curl error number into something the person reading it can act on.
     *
     * These arrive at an admin through a provider's connection test, and the
     * raw text sends people to the wrong place. A missing CA bundle in
     * particular reports as a generic failure, and the natural reading is
     * "my credentials or URL are wrong" — so they re-check both, repeatedly,
     * while the actual fix is one line of php.ini on a badly-maintained host.
     */
    private static function explainCurlError(int $errno, string $error, string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return match ($errno) {
            // CURLE_SSL_CACERT, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT_BADFILE
            60, 51, 77 => sprintf(
                'Could not verify the TLS certificate for %s. This is usually a missing or outdated '
                . 'CA bundle on the server rather than a problem with the site being contacted. '
                . 'Ask your host to set curl.cainfo in php.ini. (%s)',
                $host,
                $error
            ),

            // CURLE_COULDNT_RESOLVE_HOST
            6 => sprintf(
                'Could not resolve %s. Check the address for a typo, and whether this server is '
                . 'allowed to make outbound DNS queries. (%s)',
                $host,
                $error
            ),

            // CURLE_COULDNT_CONNECT
            7 => sprintf(
                'Could not connect to %s. Many shared hosts block outbound connections by default; '
                . 'your host may need to allow them. (%s)',
                $host,
                $error
            ),

            // CURLE_OPERATION_TIMEDOUT
            28 => sprintf('Timed out contacting %s. (%s)', $host, $error),

            default => $error !== '' ? $error : 'Request failed.',
        };
    }

    /** @return array<string, string> */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $headers[$name] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    /** @return list<array{method: string, url: string, status: int, ms: float}> */
    public static function log(): array
    {
        return self::$log;
    }

    public static function callCount(): int
    {
        return self::$callCount;
    }

    public static function totalMs(): float
    {
        return round(self::$totalMs, 2);
    }
}
