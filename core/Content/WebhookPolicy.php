<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * What may be called, what it is told, and how it knows the message is ours.
 *
 * Pure, and every decision in it is a security decision, so most of this class
 * is refusals.
 *
 * The threat that shapes it is not the obvious one. A webhook URL is typed by
 * an administrator, so "an attacker sets a malicious URL" already assumes an
 * attacker with admin. The real problem is that this server will make an
 * outbound request to an address of somebody's choosing, from INSIDE whatever
 * network it is hosted on — which is how a form that looks like configuration
 * becomes a way to read a cloud provider's metadata service, probe a database
 * on localhost, or reach an internal admin panel that trusts its own network.
 * That is server-side request forgery, and an admin who pastes a URL a
 * supplier gave them has done nothing wrong.
 */
final class WebhookPolicy
{
    /**
     * Events an endpoint can subscribe to.
     *
     * Named here rather than discovered, so the settings screen can list them
     * and a typo in a subscription is caught when it is saved instead of
     * quietly matching nothing forever.
     *
     * @return array<string, string> event => what it means
     */
    public static function events(): array
    {
        return [
            'video.published'   => 'A video became visible on the site.',
            'video.updated'     => 'A video was edited.',
            'video.deleted'     => 'A video was moved to the trash.',
            'share.created'     => 'A share link was issued.',
            'share.viewed'      => 'Somebody opened a share link.',
            'share.revoked'     => 'A share link was withdrawn.',
            'comment.posted'    => 'A comment was left, whether or not it is visible yet.',
            'user.authorized'   => 'An account was approved to watch.',
        ];
    }

    /** The wildcard an endpoint uses to say "everything". */
    public const ALL_EVENTS = '*';

    /** How many times a delivery is tried before it is given up on. */
    public const MAX_ATTEMPTS = 6;

    /**
     * Consecutive failures before an endpoint is switched off.
     *
     * An endpoint that has gone away permanently — a decommissioned server, a
     * cancelled account — would otherwise be retried forever, and every one of
     * those attempts is a request this site waits on. Switching it off is
     * recorded with a reason, because an endpoint that silently stopped being
     * tried is indistinguishable from one that never worked.
     */
    public const FAILURES_BEFORE_DISABLING = 20;

    /** How long to wait for an endpoint before giving up on one attempt. */
    public const TIMEOUT_SECONDS = 10;

    // ------------------------------------------------------------------ URLs

    /**
     * Why this URL cannot be called, or null if it can.
     *
     * Returns a reason rather than a boolean because every one of these is
     * something the person typing needs to be told — "invalid URL" on a
     * perfectly ordinary-looking address is the kind of message that gets a
     * feature abandoned.
     *
     * @param bool $allowPrivate the config escape hatch, for a site genuinely
     *                           delivering to another box on its own network
     */
    public static function rejectionReason(string $url, bool $allowPrivate = false): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'Enter a URL to deliver to.';
        }

        if (strlen($url) > 500) {
            return 'That URL is too long.';
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'That does not look like a full URL. It needs to start with https://.';
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && $scheme !== 'http') {
            return 'Only http and https URLs can be called.';
        }

        /*
         * Credentials in the URL are refused rather than stripped.
         *
         * They would be stored in a column an admin can read and sent on every
         * delivery, and the signature already gives the receiver a better way
         * to know the request is ours. Silently removing them would produce an
         * endpoint that fails to authenticate for reasons nobody could see.
         */
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Put credentials in the receiving system rather than in the URL; '
                 . 'every delivery is signed, which is how it can tell the request is from here.';
        }

        if (!$allowPrivate && $scheme !== 'https') {
            return 'Use https. An http endpoint sends the payload across the network in the clear.';
        }

        $host = strtolower($parts['host']);

        if ($allowPrivate) {
            return null;
        }

        return self::reasonHostIsUnreachable($host);
    }

    /**
     * Why an address must not be called from this server, or null.
     *
     * Split out from the URL check because it has to run TWICE — once when the
     * endpoint is saved, and again immediately before each delivery. A name
     * that resolved to a public address on Tuesday can resolve to 127.0.0.1 on
     * Wednesday, and an attacker who controls a DNS record does not need admin
     * on this site to arrange that. Checking only at save time is checking the
     * wrong moment.
     */
    public static function reasonHostIsUnreachable(string $host): ?string
    {
        $addresses = self::resolve($host);

        if ($addresses === []) {
            return 'That hostname does not resolve to anything.';
        }

        foreach ($addresses as $address) {
            if (self::isPrivateAddress($address)) {
                return 'That address is on a private or internal network. '
                     . 'Deliveries go out from the server, so an internal address would let this '
                     . 'reach things that are not meant to be reachable from outside.';
            }
        }

        return null;
    }

    /**
     * Is this IP one the server must not be pointed at?
     *
     * The allow-list approach — "only these public ranges" — is unworkable
     * because the public internet is everything else. So this is a deny-list,
     * and it is written out rather than delegated to FILTER_FLAG_NO_PRIV_RANGE
     * alone: that flag misses the cloud metadata address on some PHP builds
     * and says nothing about IPv6 mapped forms, and getting either wrong is
     * the whole bug.
     */
    public static function isPrivateAddress(string $address): bool
    {
        $address = trim($address);

        if ($address === '') {
            return true;
        }

        /*
         * ::ffff:169.254.169.254 is the metadata service wearing a hat. An
         * IPv4 address expressed in IPv6 form passes every IPv4 range check
         * that does not know to unwrap it first.
         */
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $m) === 1) {
            $address = $m[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            if ($long === false) {
                return true;
            }

            foreach ([
                ['0.0.0.0', 8],        // "this network"
                ['10.0.0.0', 8],       // RFC 1918
                ['100.64.0.0', 10],    // carrier-grade NAT; a shared host may sit here
                ['127.0.0.0', 8],      // loopback — the database, the cache, everything
                ['169.254.0.0', 16],   // link-local, and the cloud metadata service
                ['172.16.0.0', 12],    // RFC 1918
                ['192.0.0.0', 24],
                ['192.168.0.0', 16],   // RFC 1918
                ['198.18.0.0', 15],    // benchmarking
                ['224.0.0.0', 4],      // multicast
                ['240.0.0.0', 4],      // reserved, includes 255.255.255.255
            ] as [$range, $bits]) {
                $mask = -1 << (32 - $bits);
                if ((ip2long($range) & $mask) === ($long & $mask)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $lower = strtolower($address);

            // ::1 loopback, :: unspecified, fc00::/7 unique-local, fe80::/10
            // link-local. Written as prefixes because the alternative is
            // 128-bit arithmetic for four cases.
            return $lower === '::1'
                || $lower === '::'
                || str_starts_with($lower, 'fc')
                || str_starts_with($lower, 'fd')
                || str_starts_with($lower, 'fe8')
                || str_starts_with($lower, 'fe9')
                || str_starts_with($lower, 'fea')
                || str_starts_with($lower, 'feb');
        }

        // Not an IP at all. Fails closed: an address this cannot classify is
        // one it cannot vouch for.
        return true;
    }

    // ---------------------------------------------------------------- events

    /**
     * Clean a submitted event subscription.
     *
     * Unknown names are dropped rather than refused, so that an endpoint
     * configured against a newer version of this app — or one whose event was
     * later removed — keeps working for the events it does understand.
     * Returning nothing at all means the form said nothing, and that becomes
     * the wildcard: an endpoint subscribed to no events is one that will never
     * fire, which nobody sets up on purpose.
     */
    public static function normalizeEvents(mixed $submitted): string
    {
        if (is_string($submitted)) {
            $submitted = explode(',', $submitted);
        }

        if (!is_array($submitted)) {
            return self::ALL_EVENTS;
        }

        $known = self::events();
        $chosen = [];

        foreach ($submitted as $event) {
            $event = trim((string) $event);

            if ($event === self::ALL_EVENTS) {
                return self::ALL_EVENTS;
            }

            if (isset($known[$event])) {
                $chosen[$event] = true;
            }
        }

        return $chosen === [] ? self::ALL_EVENTS : implode(',', array_keys($chosen));
    }

    /** Does this endpoint want this event? */
    public static function wants(string $subscription, string $event): bool
    {
        $subscription = trim($subscription);

        if ($subscription === self::ALL_EVENTS || $subscription === '') {
            return true;
        }

        return in_array($event, array_map('trim', explode(',', $subscription)), true);
    }

    // ------------------------------------------------------------- signatures

    /**
     * A secret for a new endpoint.
     *
     * Random, not derived. A secret computed from the URL or from APP_KEY
     * would be identical on two installs that shared either, and could not be
     * rotated without rotating the thing it came from.
     */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * The signature header value for one delivery.
     *
     * The timestamp is INSIDE the signed material, not merely alongside it. A
     * signature over the body alone is replayable forever: anyone who captured
     * one valid delivery could send it again at any point in the future and it
     * would verify. Signing "timestamp.body" lets the receiver reject anything
     * older than a few minutes, and the timestamp cannot be edited without
     * breaking the signature.
     *
     * The format is the one Stripe popularised, because a receiver written for
     * that will already know what to do with it.
     */
    public static function signature(string $secret, string $body, int $timestamp): string
    {
        return sprintf(
            't=%d,v1=%s',
            $timestamp,
            hash_hmac('sha256', $timestamp . '.' . $body, $secret)
        );
    }

    /**
     * Verify a signature, for the test endpoint and for anyone reading this to
     * work out what their receiver should do.
     *
     * hash_equals, not ===. String comparison returns early on the first
     * differing byte, and the time it takes leaks how much of a guess was
     * right — which is enough to recover a signature one byte at a time.
     */
    public static function verify(string $secret, string $body, string $header, int $toleranceSeconds = 300): bool
    {
        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', trim($header), $m) !== 1) {
            return false;
        }

        $timestamp = (int) $m[1];

        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $m[2]);
    }

    // --------------------------------------------------------------- retrying

    /**
     * How long to wait before attempt number $attempt.
     *
     * Doubling, from a minute. The first retry is soon because the commonest
     * failure is a restart that lasted seconds; the later ones are far apart
     * because an endpoint still failing after twenty minutes is not going to
     * be fixed by asking again immediately. Six attempts spans about an hour,
     * which is long enough to survive a deploy and short enough that a queue
     * cannot build up unboundedly behind a dead endpoint.
     */
    public static function backoffSeconds(int $attempt): int
    {
        $attempt = max(1, min($attempt, self::MAX_ATTEMPTS));

        return 60 * (2 ** ($attempt - 1));
    }

    /**
     * Should this response count as delivered?
     *
     * Any 2xx. Not 3xx: redirects are off for these requests, because
     * following one would send a signed payload to an address that never
     * passed the checks above — which is the SSRF hole reopened by a receiver
     * rather than by an admin.
     */
    public static function isSuccess(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Is it worth trying again?
     *
     * A 4xx that is not 408 or 429 means the receiver understood and refused;
     * repeating it changes nothing and only wastes both ends. Everything else
     * — timeouts, 5xx, connection failures — is worth another go.
     */
    public static function isRetryable(int $status): bool
    {
        if ($status === 0) {
            return true; // never connected
        }

        if ($status === 408 || $status === 429) {
            return true;
        }

        return $status < 400 || $status >= 500;
    }

    /**
     * @return list<string>
     * @codeCoverageIgnore the DNS call itself is not the logic under test
     */
    private static function resolve(string $host): array
    {
        // A literal address needs no lookup, and gethostbynamel() returns
        // false for one on some platforms.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Bracketed IPv6 literal, as it appears in a URL.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return [substr($host, 1, -1)];
        }

        $v4 = gethostbynamel($host);
        $addresses = is_array($v4) ? $v4 : [];

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
