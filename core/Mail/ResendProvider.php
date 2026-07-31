<?php

declare(strict_types=1);

namespace Portal\Mail;

use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Http;
use Portal\Support\Str;
use Throwable;

/**
 * Resend, over its REST API.
 *
 * No SDK: the entire integration is one POST. A vendored SDK would be more
 * code to security-patch through app releases than the feature is worth.
 */
final class ResendProvider implements MailProvider
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials)
    {
    }

    public static function slug(): string
    {
        return 'resend';
    }

    public static function label(): string
    {
        return 'Resend';
    }

    public static function description(): string
    {
        return 'Modern transactional email API. Good deliverability; needs a verified sending domain.';
    }

    public static function requiredExtensions(): array
    {
        return ['curl'];
    }

    public static function fields(): array
    {
        return [
            SettingField::secret(
                'api_key',
                'Resend API Key',
                'Starts with re_. Create one at resend.com under API Keys. '
                . '"Sending access" is enough and is the safer choice — this app only ever sends. '
                . 'A full-access key also works and additionally lets the test confirm your '
                . 'sending domain is verified.'
            ),
            SettingField::text(
                'from',
                'From address',
                'For example: Video Portal <videos@yourdomain.com>. The domain must be verified in Resend or every send fails.'
            ),
            SettingField::email(
                'reply_to',
                'Reply-To address',
                'Optional. Where replies from recipients should go.',
                required: false
            ),
        ];
    }

    private function apiKey(): string
    {
        return trim($this->credentials['api_key'] ?? '');
    }

    public function fromAddress(): string
    {
        return trim($this->credentials['from'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->fromAddress() !== '';
    }

    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): SendResult {
        if (!$this->isConfigured()) {
            return SendResult::failure('Resend is not fully configured (API key and From address are both required).');
        }

        $to = Str::normalizeEmail($to);
        if (!Str::isEmail($to)) {
            return SendResult::failure("'{$to}' is not a valid email address.");
        }

        $payload = [
            'from'    => $this->fromAddress(),
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];

        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }

        $replyTo = trim((string) ($options['replyTo'] ?? $this->credentials['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        try {
            $response = Http::postJson(self::ENDPOINT, $payload, [
                'Authorization' => 'Bearer ' . $this->apiKey(),
            ]);
        } catch (Throwable $e) {
            // Never propagate: the caller has a share link to preserve.
            return SendResult::failure('Could not reach Resend: ' . $e->getMessage());
        }

        if ($response->failed()) {
            return SendResult::failure($response->errorMessage());
        }

        return SendResult::success((string) ($response->json()['id'] ?? null) ?: null);
    }

    public function test(): TestResult
    {
        if ($this->apiKey() === '') {
            return TestResult::fail('An API key is required.');
        }
        if ($this->fromAddress() === '') {
            return TestResult::fail('A From address is required.');
        }
        if (!function_exists('curl_init')) {
            return TestResult::unavailable('The curl PHP extension is not enabled, so Resend cannot be reached.');
        }

        // Listing domains is the richer check — it can also confirm the From
        // domain is verified — but it needs a FULL ACCESS key. Requiring one
        // would force an unnecessarily privileged credential on every install,
        // when all this app ever does is send. So a restricted key is expected
        // here, not an error, and we fall through to a sending-scoped probe.
        try {
            $domains = Http::get('https://api.resend.com/domains', [
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Accept'        => 'application/json',
            ]);
        } catch (Throwable $e) {
            return TestResult::fail('Could not reach Resend.', $e->getMessage());
        }

        if ($domains->transportFailed()) {
            return TestResult::fail(
                'Could not reach api.resend.com. Your host may be blocking outbound HTTPS.',
                $domains->transportError
            );
        }

        if ($domains->ok()) {
            return $this->checkVerifiedDomains($domains->json());
        }

        // A sending-only key is refused here and nowhere else. Prove it works
        // by the one thing it is allowed to do.
        if ($domains->status === 401 || $domains->status === 403) {
            return $this->testSendingScope();
        }

        return TestResult::fail('Resend returned an error.', $domains->errorMessage());
    }

    /**
     * Validate a sending-scoped key without sending anything.
     *
     * Posts a deliberately invalid message — an empty recipient list, which
     * cannot reach anybody — and reads the status. Resend answers 422 for a
     * payload problem and 401/403 for a credential problem, so the two are
     * cleanly distinguishable and no mail is ever generated. A test wired to a
     * button an admin might press repeatedly must not email anyone.
     */
    private function testSendingScope(): TestResult
    {
        try {
            $probe = Http::postJson(
                self::ENDPOINT,
                ['from' => $this->fromAddress(), 'to' => [], 'subject' => '', 'html' => ''],
                ['Authorization' => 'Bearer ' . $this->apiKey()]
            );
        } catch (Throwable $e) {
            return TestResult::fail('Could not reach Resend.', $e->getMessage());
        }

        if ($probe->status === 401) {
            return TestResult::fail('Resend rejected that API key.');
        }

        if ($probe->status === 403) {
            return TestResult::fail(
                'That API key is not allowed to send email.',
                'In Resend, give the key "Sending access" (or full access). ' . $probe->errorMessage()
            );
        }

        // 422 means the credentials were accepted and only the payload was
        // rejected, which is exactly what we engineered.
        if ($probe->status === 422 || $probe->ok()) {
            return TestResult::pass(
                'Connected to Resend with a sending-scoped key.',
                'This key cannot list domains, so the From domain could not be verified here. '
                . 'If messages do not arrive, check that "' . $this->extractDomain($this->fromAddress())
                . '" is verified in Resend.'
            );
        }

        return TestResult::fail('Resend returned an unexpected response.', $probe->errorMessage());
    }

    /**
     * With a full-access key, confirm the From domain is actually verified.
     *
     * An unverified sending domain is the overwhelmingly most common cause of
     * "the key works but nothing arrives", and it is worth catching at install
     * time rather than when the first share link fails to reach anyone.
     *
     * @param array<string, mixed> $payload
     */
    private function checkVerifiedDomains(array $payload): TestResult
    {
        $fromDomain = $this->extractDomain($this->fromAddress());

        $verified = [];
        foreach ($payload['data'] ?? [] as $domain) {
            if (is_array($domain) && ($domain['status'] ?? '') === 'verified') {
                $verified[] = strtolower((string) ($domain['name'] ?? ''));
            }
        }

        if ($fromDomain !== '' && $verified !== [] && !in_array($fromDomain, $verified, true)) {
            return TestResult::fail(sprintf(
                'The API key works, but "%s" is not a verified domain in Resend, so sends will be rejected. Verified: %s.',
                $fromDomain,
                implode(', ', $verified)
            ));
        }

        return TestResult::pass(
            'Connected to Resend.',
            'This is a full-access key. A key with only "Sending access" would work here too, '
            . 'and is the safer choice since this application only ever sends.'
        );
    }

    private function extractDomain(string $from): string
    {
        // Handles both "foo@bar.com" and "Name <foo@bar.com>".
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }
        $at = strrpos($from, '@');
        return $at === false ? '' : strtolower(trim(substr($from, $at + 1)));
    }
}
