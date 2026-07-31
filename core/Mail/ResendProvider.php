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
                'Starts with re_. Create one at resend.com → API Keys.'
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

        // Listing domains proves the key is valid without sending anything.
        // A test that actually emailed someone would be a rude thing to wire
        // to a button an admin might press repeatedly.
        try {
            $response = Http::get('https://api.resend.com/domains', [
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Accept'        => 'application/json',
            ]);
        } catch (Throwable $e) {
            return TestResult::fail('Could not reach Resend.', $e->getMessage());
        }

        if ($response->transportFailed()) {
            return TestResult::fail(
                'Could not reach api.resend.com. Your host may be blocking outbound HTTPS.',
                $response->transportError
            );
        }

        if ($response->status === 401 || $response->status === 403) {
            return TestResult::fail('Resend rejected that API key.');
        }

        if ($response->failed()) {
            return TestResult::fail('Resend returned an error.', $response->errorMessage());
        }

        // Check the From domain is actually verified — the overwhelmingly most
        // common cause of "the key works but nothing arrives".
        $fromDomain = $this->extractDomain($this->fromAddress());
        $verified = [];
        foreach ($response->json()['data'] ?? [] as $domain) {
            if (is_array($domain) && ($domain['status'] ?? '') === 'verified') {
                $verified[] = strtolower((string) ($domain['name'] ?? ''));
            }
        }

        if ($fromDomain !== '' && $verified !== [] && !in_array($fromDomain, $verified, true)) {
            return TestResult::fail(sprintf(
                'The API key works, but "%s" is not a verified domain in Resend. Sends will be rejected. Verified: %s.',
                $fromDomain,
                implode(', ', $verified)
            ));
        }

        return TestResult::pass('Connected to Resend.');
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
