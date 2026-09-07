<?php

declare(strict_types=1);

namespace Portal\Sms;

use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Http;

/**
 * Vonage, formerly Nexmo.
 *
 * The second implementation, and the reason the interface can be trusted: one
 * implementation is a guess about what varies, and two is a measurement.
 *
 * Two things it measured, both of which would have been baked into a
 * Twilio-shaped interface otherwise:
 *
 *   Vonage answers HTTP 200 for a REFUSED message and puts the failure in the
 *   body. An interface that took `$response->ok()` as the answer would record
 *   every rejected text as delivered — the exact failure the whole
 *   recipient-row design exists to make visible.
 *
 *   Its "from" may be a sender NAME rather than a number, which is why the
 *   interface says from(): string rather than from(): PhoneNumber.
 */
final class VonageProvider implements SmsProvider
{
    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials = [])
    {
    }

    public static function slug(): string
    {
        return 'vonage';
    }

    public static function label(): string
    {
        return 'Vonage (Nexmo)';
    }

    public static function description(): string
    {
        return 'Texts can come from a name rather than a number in many countries.';
    }

    /** @return list<SettingField> */
    public static function fields(): array
    {
        return [
            new SettingField('api_key', 'API key', SettingField::TYPE_TEXT),
            new SettingField('api_secret', 'API secret', SettingField::TYPE_SECRET),
            new SettingField('from', 'From', SettingField::TYPE_TEXT, true,
                'A number in +44... form, or a short sender name where your country allows one.'),
        ];
    }

    /** @return list<string> */
    public static function requiredExtensions(): array
    {
        return ['curl'];
    }

    public function isConfigured(): bool
    {
        return $this->credential('api_key') !== ''
            && $this->credential('api_secret') !== ''
            && $this->from() !== '';
    }

    public function from(): string
    {
        return $this->credential('from');
    }

    public function send(string $to, string $message): SmsResult
    {
        if (!$this->isConfigured()) {
            return SmsResult::failure('Vonage is not configured.');
        }

        $response = Http::request(
            'POST',
            'https://rest.nexmo.com/sms/json',
            http_build_query([
                'api_key'    => $this->credential('api_key'),
                'api_secret' => $this->credential('api_secret'),
                // Vonage wants the number without the leading plus.
                'to'         => ltrim($to, '+'),
                'from'       => $this->from(),
                'text'       => $message,
            ]),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            ['timeout' => 15]
        );

        if ($response->transportFailed()) {
            return SmsResult::failure($response->errorMessage());
        }

        return self::read($response->json());
    }

    /**
     * Vonage's answer, which is 200 whether or not it sent anything.
     *
     * Public so the rule can be tested without a network: "status 0 means
     * delivered, anything else is the reason it was not" is a property of the
     * API rather than of the transport, and taking HTTP 200 as success here
     * would record every rejected text as delivered.
     *
     * @param array<string, mixed> $body
     */
    public static function read(array $body): SmsResult
    {
        $message = $body['messages'][0] ?? null;

        if (!is_array($message)) {
            return SmsResult::failure('Vonage sent an answer this could not read.');
        }

        // '0' is the only success. It arrives as a string.
        if ((string) ($message['status'] ?? '') === '0') {
            return SmsResult::success((string) ($message['message-id'] ?? ''));
        }

        return SmsResult::failure(sprintf(
            '%s (status %s)',
            (string) ($message['error-text'] ?? 'Vonage refused it'),
            (string) ($message['status'] ?? '?')
        ));
    }

    public function test(): TestResult
    {
        if (!$this->isConfigured()) {
            return TestResult::fail('Fill in the API key, secret and From first.');
        }

        // Checks the balance, so the test costs nothing and needs no number to
        // send to.
        $response = Http::get(
            'https://rest.nexmo.com/account/get-balance?api_key='
                . rawurlencode($this->credential('api_key'))
                . '&api_secret=' . rawurlencode($this->credential('api_secret')),
            [],
            ['timeout' => 15]
        );

        if ($response->transportFailed()) {
            return TestResult::fail('This site could not reach Vonage: ' . $response->errorMessage());
        }

        return $response->ok()
            ? TestResult::pass('Vonage answered.')
            : TestResult::fail('Vonage refused those credentials: ' . $response->errorMessage());
    }

    private function credential(string $key): string
    {
        return trim((string) ($this->credentials[$key] ?? ''));
    }
}
