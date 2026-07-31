<?php

declare(strict_types=1);

namespace Portal\Mail;

use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Str;

/**
 * PHP's built-in mail() function.
 *
 * The last resort, and labelled as such in the UI. It needs no credentials and
 * works on nearly every host, which makes it a good default for getting an
 * install finished — but mail() hands the message to a local sendmail with no
 * authentication, so messages routinely land in spam or vanish silently, and
 * there is no delivery receipt to tell you which.
 *
 * Included because "no email at all" is worse: without it, an admin whose host
 * blocks outbound HTTPS and gives no SMTP credentials could not send a single
 * share link.
 */
final class PhpMailProvider implements MailProvider
{
    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials)
    {
    }

    public static function slug(): string
    {
        return 'php_mail';
    }

    public static function label(): string
    {
        return 'PHP mail() — last resort';
    }

    public static function description(): string
    {
        return 'Uses the server\'s own mail command. No setup, but poor deliverability and no way to tell whether a message arrived.';
    }

    public static function requiredExtensions(): array
    {
        return [];
    }

    public static function fields(): array
    {
        return [
            SettingField::text(
                'from',
                'From address',
                'Use an address at this site\'s own domain. Anything else will almost certainly be treated as spam.'
            ),
            SettingField::email('reply_to', 'Reply-To address', 'Optional.', required: false),
        ];
    }

    public function fromAddress(): string
    {
        return trim($this->credentials['from'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->fromAddress() !== '' && function_exists('mail');
    }

    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): SendResult {
        if (!function_exists('mail')) {
            return SendResult::failure('The mail() function is disabled on this server.');
        }
        if ($this->fromAddress() === '') {
            return SendResult::failure('A From address is required.');
        }

        $to = Str::normalizeEmail($to);
        if (!Str::isEmail($to)) {
            return SendResult::failure("'{$to}' is not a valid email address.");
        }

        // Header injection guard. $subject is built from a video title, which
        // an admin controls but which still passes through here — a newline
        // would let it inject Bcc.
        $subject = str_replace(["\r", "\n"], ' ', $subject);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . str_replace(["\r", "\n"], '', $this->fromAddress()),
        ];

        $replyTo = trim((string) ($options['replyTo'] ?? $this->credentials['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . str_replace(["\r", "\n"], '', $replyTo);
        }

        // Encode the subject so non-ASCII titles don't arrive as mojibake.
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $ok = @mail($to, $encodedSubject, $html, implode("\r\n", $headers));

        if (!$ok) {
            return SendResult::failure(
                'The server\'s mail() call failed. There is no further detail available — '
                . 'this is the main reason to prefer Resend or SMTP.'
            );
        }

        // Deliberately no message id: mail() returning true means "handed to
        // the local MTA", not "delivered". Claiming otherwise would be a lie.
        return SendResult::success();
    }

    public function test(): TestResult
    {
        if (!function_exists('mail')) {
            return TestResult::unavailable('The mail() function is disabled on this server.');
        }
        if ($this->fromAddress() === '') {
            return TestResult::fail('A From address is required.');
        }

        // There is genuinely nothing to test. Saying so honestly is better than
        // a green tick that means nothing.
        return TestResult::pass(
            'mail() is available.',
            'This cannot be verified without sending a real message, and mail() gives no delivery feedback. '
            . 'Send yourself a test share before relying on it.'
        );
    }
}
