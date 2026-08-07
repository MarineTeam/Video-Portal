<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\WebhookPolicy;

/**
 * What may be called, and how the receiver knows it was us.
 *
 * Almost all of this is refusals, because the feature's real risk is not the
 * obvious one. A webhook URL is typed by an administrator, so "an attacker
 * configures a bad URL" already assumes an attacker with admin. The problem is
 * that this server will then make a request to an address of somebody's
 * choosing from INSIDE its own network — which turns a configuration form into
 * a way to read a cloud metadata service or reach an internal admin panel that
 * trusts its own network. The admin who pasted a URL a supplier gave them did
 * nothing wrong.
 */
final class WebhookPolicyTest extends TestCase
{
    // ------------------------------------------------------- private ranges

    /**
     * The one that matters most.
     *
     * 169.254.169.254 is the cloud metadata service on AWS, GCP, Azure and
     * DigitalOcean, and it hands out credentials to anything that can reach it.
     */
    public function testTheCloudMetadataAddressIsRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('169.254.169.254'));
    }

    /** The same address wearing a hat, which every IPv4-only check misses. */
    public function testTheMetadataAddressInItsIpv6FormIsAlsoRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('::ffff:169.254.169.254'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('::FFFF:127.0.0.1'));
    }

    public function testLoopbackIsRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('127.0.0.1'));
        // The whole /8, not just the famous one. 127.9.9.9 is also loopback,
        // and a check that only knows 127.0.0.1 is a check somebody walks past.
        self::assertTrue(WebhookPolicy::isPrivateAddress('127.9.9.9'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('::1'));
    }

    public function testTheRfc1918RangesAreRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('10.0.0.1'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('192.168.1.1'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('172.16.0.1'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('172.31.255.254'));
    }

    /**
     * 172.32.x is NOT private — the range is 172.16 through 172.31 — and a
     * check written as "starts with 172." would wrongly refuse a real customer.
     */
    public function testTheEdgesOfTheTwentyBitRangeAreRight(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('172.16.0.0'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('172.31.255.255'));
        self::assertFalse(WebhookPolicy::isPrivateAddress('172.15.255.255'));
        self::assertFalse(WebhookPolicy::isPrivateAddress('172.32.0.0'));
    }

    /** A shared host can legitimately sit behind carrier-grade NAT. */
    public function testCarrierGradeNatIsRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('100.64.0.1'));
        self::assertFalse(WebhookPolicy::isPrivateAddress('100.63.255.255'));
    }

    public function testUniqueLocalAndLinkLocalIpv6AreRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress('fd00::1'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('fc00::1'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('fe80::1'));
    }

    public function testOrdinaryPublicAddressesAreAllowed(): void
    {
        self::assertFalse(WebhookPolicy::isPrivateAddress('93.184.216.34'));
        self::assertFalse(WebhookPolicy::isPrivateAddress('1.1.1.1'));
        self::assertFalse(WebhookPolicy::isPrivateAddress('2606:4700:4700::1111'));
    }

    /** Fails closed: an address it cannot classify is one it cannot vouch for. */
    public function testSomethingThatIsNotAnAddressIsRefused(): void
    {
        self::assertTrue(WebhookPolicy::isPrivateAddress(''));
        self::assertTrue(WebhookPolicy::isPrivateAddress('not-an-ip'));
        self::assertTrue(WebhookPolicy::isPrivateAddress('999.999.999.999'));
    }

    // ------------------------------------------------------------------ URLs

    public function testAnOrdinaryHttpsUrlIsAccepted(): void
    {
        self::assertNull(WebhookPolicy::rejectionReason('https://example.com/hooks/portal'));
    }

    public function testPlainHttpIsRefused(): void
    {
        $reason = WebhookPolicy::rejectionReason('http://example.com/hook');

        self::assertNotNull($reason);
        self::assertStringContainsString('https', $reason);
    }

    public function testSomethingThatIsNotAUrlIsRefused(): void
    {
        self::assertNotNull(WebhookPolicy::rejectionReason(''));
        self::assertNotNull(WebhookPolicy::rejectionReason('   '));
        self::assertNotNull(WebhookPolicy::rejectionReason('example.com/hook'));
        self::assertNotNull(WebhookPolicy::rejectionReason('https://'));
    }

    /**
     * A scheme that is not http is refused before anything else looks at it.
     * file:// would make the server read its own disk.
     */
    public function testOnlyHttpSchemesAreAllowed(): void
    {
        self::assertNotNull(WebhookPolicy::rejectionReason('file:///etc/passwd'));
        self::assertNotNull(WebhookPolicy::rejectionReason('gopher://example.com/'));
        self::assertNotNull(WebhookPolicy::rejectionReason('ftp://example.com/'));
    }

    /**
     * Refused rather than silently stripped: they would be stored in a column
     * an admin can read, and removing them quietly produces an endpoint that
     * fails to authenticate for reasons nobody can see.
     */
    public function testCredentialsInTheUrlAreRefused(): void
    {
        $reason = WebhookPolicy::rejectionReason('https://user:pass@example.com/hook');

        self::assertNotNull($reason);
        self::assertStringContainsString('credentials', $reason);
    }

    public function testAnAbsurdlyLongUrlIsRefused(): void
    {
        self::assertNotNull(WebhookPolicy::rejectionReason('https://example.com/' . str_repeat('a', 600)));
    }

    /** A literal internal address needs no DNS to be recognised. */
    public function testAUrlPointingStraightAtLoopbackIsRefused(): void
    {
        self::assertNotNull(WebhookPolicy::rejectionReason('https://127.0.0.1/hook'));
        self::assertNotNull(WebhookPolicy::rejectionReason('https://169.254.169.254/latest/meta-data/'));
        self::assertNotNull(WebhookPolicy::rejectionReason('https://[::1]/hook'));
    }

    /**
     * The escape hatch, for a site genuinely delivering to another box on its
     * own network. It lives in config.php and not in the settings table, so an
     * admin screen cannot switch off the protection.
     */
    public function testThePrivateEscapeHatchAllowsAnInternalAddress(): void
    {
        self::assertNull(WebhookPolicy::rejectionReason('http://192.168.1.50/hook', allowPrivate: true));
    }

    // ---------------------------------------------------------------- events

    public function testKnownEventsAreKept(): void
    {
        self::assertSame(
            'video.published,share.created',
            WebhookPolicy::normalizeEvents(['video.published', 'share.created'])
        );
    }

    /**
     * Dropped rather than refused, so an endpoint configured against a newer
     * version — or one whose event was later removed — keeps working for the
     * events it does understand.
     */
    public function testAnUnknownEventIsDroppedWithoutLosingTheRest(): void
    {
        self::assertSame(
            'video.published',
            WebhookPolicy::normalizeEvents(['video.published', 'video.combusted'])
        );
    }

    /** Nobody sets up an endpoint that will never fire. */
    public function testChoosingNothingMeansEverything(): void
    {
        self::assertSame(WebhookPolicy::ALL_EVENTS, WebhookPolicy::normalizeEvents([]));
        self::assertSame(WebhookPolicy::ALL_EVENTS, WebhookPolicy::normalizeEvents(['nonsense']));
        self::assertSame(WebhookPolicy::ALL_EVENTS, WebhookPolicy::normalizeEvents(null));
    }

    public function testTheWildcardWinsOverAnythingBesideIt(): void
    {
        self::assertSame(
            WebhookPolicy::ALL_EVENTS,
            WebhookPolicy::normalizeEvents(['video.published', '*'])
        );
    }

    public function testDuplicatesCollapse(): void
    {
        self::assertSame(
            'video.published',
            WebhookPolicy::normalizeEvents(['video.published', 'video.published'])
        );
    }

    public function testAnEndpointOnlyGetsWhatItAskedFor(): void
    {
        self::assertTrue(WebhookPolicy::wants('video.published,share.created', 'share.created'));
        self::assertFalse(WebhookPolicy::wants('video.published', 'share.created'));
        self::assertTrue(WebhookPolicy::wants('*', 'anything.at.all'));
    }

    /**
     * A near-miss must not match. Without exact comparison, subscribing to
     * "share.created" would also deliver "share.created.retroactively".
     */
    public function testAPartialNameDoesNotMatch(): void
    {
        self::assertFalse(WebhookPolicy::wants('share.created', 'share.create'));
        self::assertFalse(WebhookPolicy::wants('share.created', 'share'));
    }

    public function testEveryOfferedEventSurvivesNormalisation(): void
    {
        foreach (array_keys(WebhookPolicy::events()) as $event) {
            self::assertSame(
                $event,
                WebhookPolicy::normalizeEvents([$event]),
                $event . ' is offered on the settings screen but would be dropped when saved.'
            );
        }
    }

    // ------------------------------------------------------------ signatures

    public function testASignatureVerifies(): void
    {
        $secret = WebhookPolicy::newSecret();
        $body = '{"event":"video.published"}';

        $header = WebhookPolicy::signature($secret, $body, time());

        self::assertTrue(WebhookPolicy::verify($secret, $body, $header));
    }

    public function testADifferentSecretDoesNotVerify(): void
    {
        $body = '{"event":"video.published"}';
        $header = WebhookPolicy::signature('one-secret', $body, time());

        self::assertFalse(WebhookPolicy::verify('another-secret', $body, $header));
    }

    /** The entire point: a tampered body must not pass. */
    public function testAnEditedBodyDoesNotVerify(): void
    {
        $secret = WebhookPolicy::newSecret();
        $header = WebhookPolicy::signature($secret, '{"amount":1}', time());

        self::assertFalse(WebhookPolicy::verify($secret, '{"amount":1000}', $header));
    }

    /**
     * The timestamp is signed, not merely carried alongside. A signature over
     * the body alone is replayable forever by anyone who captured one valid
     * delivery.
     */
    public function testAnOldDeliveryIsRefused(): void
    {
        $secret = WebhookPolicy::newSecret();
        $body = '{"event":"video.published"}';

        $header = WebhookPolicy::signature($secret, $body, time() - 3600);

        self::assertFalse(WebhookPolicy::verify($secret, $body, $header));
        // Still genuinely signed — it is the age that disqualifies it, which is
        // what makes this a replay defence rather than a signing failure.
        self::assertTrue(WebhookPolicy::verify($secret, $body, $header, toleranceSeconds: 7200));
    }

    /** And the timestamp cannot be edited to make an old delivery look fresh. */
    public function testMovingTheTimestampBreaksTheSignature(): void
    {
        $secret = WebhookPolicy::newSecret();
        $body = '{"event":"video.published"}';

        $header = WebhookPolicy::signature($secret, $body, time() - 3600);
        $forged = preg_replace('/^t=\d+/', 't=' . time(), $header);

        self::assertFalse(WebhookPolicy::verify($secret, $body, (string) $forged));
    }

    public function testAMalformedHeaderIsRefused(): void
    {
        $secret = WebhookPolicy::newSecret();

        self::assertFalse(WebhookPolicy::verify($secret, 'x', ''));
        self::assertFalse(WebhookPolicy::verify($secret, 'x', 'nonsense'));
        self::assertFalse(WebhookPolicy::verify($secret, 'x', 'v1=abc'));
        self::assertFalse(WebhookPolicy::verify($secret, 'x', 't=' . time() . ',v1=tooshort'));
    }

    public function testSecretsAreNotPredictable(): void
    {
        $secrets = [];
        for ($i = 0; $i < 50; $i++) {
            $secrets[WebhookPolicy::newSecret()] = true;
        }

        self::assertCount(50, $secrets);
        self::assertSame(48, strlen(array_key_first($secrets)));
    }

    // --------------------------------------------------------------- retries

    public function testBackoffGrows(): void
    {
        self::assertSame(60, WebhookPolicy::backoffSeconds(1));
        self::assertSame(120, WebhookPolicy::backoffSeconds(2));
        self::assertSame(240, WebhookPolicy::backoffSeconds(3));
        self::assertSame(1920, WebhookPolicy::backoffSeconds(6));
    }

    /**
     * Clamped at both ends. Attempt 0 or a negative would otherwise produce a
     * fractional or zero delay and retry in a tight loop; an attempt past the
     * maximum would produce a wait measured in days.
     */
    public function testBackoffIsClamped(): void
    {
        self::assertSame(60, WebhookPolicy::backoffSeconds(0));
        self::assertSame(60, WebhookPolicy::backoffSeconds(-5));
        self::assertSame(
            WebhookPolicy::backoffSeconds(WebhookPolicy::MAX_ATTEMPTS),
            WebhookPolicy::backoffSeconds(999)
        );
    }

    public function testOnlyTwoHundredsCountAsDelivered(): void
    {
        self::assertTrue(WebhookPolicy::isSuccess(200));
        self::assertTrue(WebhookPolicy::isSuccess(204));
        self::assertTrue(WebhookPolicy::isSuccess(299));
        self::assertFalse(WebhookPolicy::isSuccess(199));
        self::assertFalse(WebhookPolicy::isSuccess(400));
        self::assertFalse(WebhookPolicy::isSuccess(500));
    }

    /**
     * A redirect is NOT success. Redirects are off for these requests, because
     * following one would send a signed payload to an address that never passed
     * the private-range checks — the same hole, reopened by the receiver.
     */
    public function testARedirectIsNotADelivery(): void
    {
        self::assertFalse(WebhookPolicy::isSuccess(301));
        self::assertFalse(WebhookPolicy::isSuccess(302));
        self::assertFalse(WebhookPolicy::isSuccess(307));
    }

    /**
     * A 4xx means the receiver understood and refused. Repeating it changes
     * nothing and wastes both ends.
     */
    public function testARefusalIsNotRetried(): void
    {
        self::assertFalse(WebhookPolicy::isRetryable(400));
        self::assertFalse(WebhookPolicy::isRetryable(401));
        self::assertFalse(WebhookPolicy::isRetryable(404));
        self::assertFalse(WebhookPolicy::isRetryable(410));
    }

    /** Except the two that explicitly mean "not now". */
    public function testTimeoutsAndRateLimitsAreRetried(): void
    {
        self::assertTrue(WebhookPolicy::isRetryable(408));
        self::assertTrue(WebhookPolicy::isRetryable(429));
    }

    public function testServerErrorsAndConnectionFailuresAreRetried(): void
    {
        self::assertTrue(WebhookPolicy::isRetryable(0));
        self::assertTrue(WebhookPolicy::isRetryable(500));
        self::assertTrue(WebhookPolicy::isRetryable(503));
    }
}
