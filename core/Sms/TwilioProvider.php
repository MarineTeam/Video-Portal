<?php

declare(strict_types=1);

namespace Portal\Sms;

use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Http;

/**
 * Twilio.
 *
 * Form-encoded POST with HTTP basic auth. No SDK: the whole need is one request
 * with a timeout, which is the same reasoning that dropped Guzzle in Phase 1 —
 * a vendored SDK on the release branch can only be patched by cutting a whole
 * release.
 */
final class TwilioProvider implements SmsProvider
{
    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials = [])
    {
    }

    public static function slug(): string
    {
        return 'twilio';
    }

    public static function label(): string
    {
        return 'Twilio';
    }

    public static function description(): string
    {
        return 'Widely available and easy to buy a number from. Charged per message.';
    }

    /** @return list<SettingField> */
    public static function fields(): array
    {
        return [
            new SettingField('account_sid', 'Account SID', SettingField::TYPE_TEXT, true,
                'Starts with AC. On the Twilio console home page.'),
            new SettingField('auth_token', 'Auth token', SettingField::TYPE_SECRET, true),
            new SettingField('from', 'From', SettingField::TYPE_TEXT, true,
                'The Twilio number texts come from, in +44... form.'),
        ];
    }

    /** @return list<string> */
    public static function requiredExtensions(): array
    {
        return ['curl'];
    }

    public function isConfigured(): bool
    {
        return $this->credential('account_sid') !== ''
            && $this->credential('auth_token') !== ''
            && $this->from() !== '';
    }

    public function from(): string
    {
        return $this->credential('from');
    }

    public function send(string $to, string $message): SmsResult
    {
        if (!$this->isConfigured()) {
            return SmsResult::failure('Twilio is not configured.');
        }

        $sid = $this->credential('account_sid');

        $response = Http::request(
            'POST',
            sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($sid)),
            http_build_query(['To' => $to, 'From' => $this->from(), 'Body' => $message]),
            [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($sid . ':' . $this->credential('auth_token')),
            ],
            ['timeout' => 15]
        );

        if ($response->ok()) {
            return SmsResult::success((string) ($response->json()['sid'] ?? ''));
        }

        // Twilio's own words. It says useful things — "unverified number",
        // "not a mobile" — and a category would throw all of that away.
        return SmsResult::failure($response->errorMessage());
    }

    public function test(): TestResult
    {
        if (!$this->isConfigured()) {
            return TestResult::fail('Fill in the account SID, auth token and From number first.');
        }

        /*
         * Reads the account rather than sending anything. A test that sent a
         * real text would cost money every time somebody pressed it, and would
         * need a number to send to that this screen has no business asking for.
         */
        $response = Http::get(
            sprintf('https://api.twilio.com/2010-04-01/Accounts/%s.json', rawurlencode($this->credential('account_sid'))),
            [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->credential('account_sid') . ':' . $this->credential('auth_token')
                ),
            ],
            ['timeout' => 15]
        );

        if ($response->transportFailed()) {
            return TestResult::fail('This site could not reach Twilio: ' . $response->errorMessage());
        }

        return $response->ok()
            ? TestResult::pass('Twilio answered.')
            : TestResult::fail('Twilio refused those credentials: ' . $response->errorMessage());
    }

    private function credential(string $key): string
    {
        // trim() on every read. A stray newline pasted from a dashboard is
        // invisible and breaks basic auth, which this project has already been
        // caught by on the bunny.net keys.
        return trim((string) ($this->credentials[$key] ?? ''));
    }
}
