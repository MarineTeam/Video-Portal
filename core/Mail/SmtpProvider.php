<?php

declare(strict_types=1);

namespace Portal\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Str;
use Throwable;

/**
 * SMTP via PHPMailer.
 *
 * The pragmatic choice on shared hosting: every such host gives you a mailbox
 * and SMTP credentials, and many block outbound HTTPS to third-party APIs while
 * happily allowing port 587. This is often the only provider that works.
 */
final class SmtpProvider implements MailProvider
{
    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials)
    {
    }

    public static function slug(): string
    {
        return 'smtp';
    }

    public static function label(): string
    {
        return 'SMTP';
    }

    public static function description(): string
    {
        return 'Send through any SMTP server, including the mailbox your web host already gives you.';
    }

    public static function requiredExtensions(): array
    {
        return ['openssl'];
    }

    public static function fields(): array
    {
        return [
            SettingField::text('host', 'SMTP host', 'For example smtp.dreamhost.com or smtp.gmail.com.'),
            SettingField::number('port', 'Port', '587 for STARTTLS, 465 for implicit TLS.', default: '587'),
            SettingField::select(
                'encryption',
                'Encryption',
                ['tls' => 'STARTTLS (usually port 587)', 'ssl' => 'Implicit TLS (usually port 465)', 'none' => 'None (not recommended)'],
                'Must match the port. Mismatching these two is the usual cause of a connection that hangs then times out.',
                default: 'tls'
            ),
            SettingField::text('username', 'Username', 'Usually the full email address.'),
            SettingField::secret('password', 'Password', 'For Gmail and similar, an app-specific password, not your account password.'),
            SettingField::text('from', 'From address', 'For example: Video Portal <videos@yourdomain.com>. Many servers require this to match the username.'),
            SettingField::email('reply_to', 'Reply-To address', 'Optional.', required: false),
        ];
    }

    private function host(): string
    {
        return trim($this->credentials['host'] ?? '');
    }

    private function port(): int
    {
        $port = (int) ($this->credentials['port'] ?? 587);
        return $port > 0 ? $port : 587;
    }

    private function encryption(): string
    {
        return strtolower(trim($this->credentials['encryption'] ?? 'tls'));
    }

    public function fromAddress(): string
    {
        return trim($this->credentials['from'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->host() !== '' && $this->fromAddress() !== '';
    }

    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): SendResult {
        if (!$this->isConfigured()) {
            return SendResult::failure('SMTP is not fully configured (host and From address are both required).');
        }

        $to = Str::normalizeEmail($to);
        if (!Str::isEmail($to)) {
            return SendResult::failure("'{$to}' is not a valid email address.");
        }

        if (!class_exists(PHPMailer::class)) {
            return SendResult::failure(
                'PHPMailer is not installed. Reinstall the application from the official ZIP, which bundles it.'
            );
        }

        try {
            $mailer = $this->makeMailer();

            [$fromEmail, $fromName] = $this->splitAddress($this->fromAddress());
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($to);

            $replyTo = trim((string) ($options['replyTo'] ?? $this->credentials['reply_to'] ?? ''));
            if ($replyTo !== '') {
                [$replyEmail, $replyName] = $this->splitAddress($replyTo);
                $mailer->addReplyTo($replyEmail, $replyName);
            }

            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body = $html;
            if ($text !== null && $text !== '') {
                $mailer->AltBody = $text;
            }

            $mailer->send();

            return SendResult::success($mailer->getLastMessageID() ?: null);
        } catch (PHPMailerException $e) {
            return SendResult::failure($e->getMessage());
        } catch (Throwable $e) {
            return SendResult::failure('SMTP send failed: ' . $e->getMessage());
        }
    }

    private function makeMailer(): PHPMailer
    {
        // true = throw on error, which is what lets send() turn every failure
        // into a SendResult rather than a silent false.
        $mailer = new PHPMailer(true);

        $mailer->isSMTP();
        $mailer->Host = $this->host();
        $mailer->Port = $this->port();
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;

        // Shared hosts are slow to answer. The default 300s would hold a web
        // request open long enough for the host to kill it.
        $mailer->Timeout = 20;

        $username = trim($this->credentials['username'] ?? '');
        $password = (string) ($this->credentials['password'] ?? '');
        if ($username !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = $password;
        }

        $mailer->SMTPSecure = match ($this->encryption()) {
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            'none'  => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        if ($this->encryption() === 'none') {
            $mailer->SMTPAutoTLS = false;
        }

        return $mailer;
    }

    /** @return array{0: string, 1: string} */
    private function splitAddress(string $address): array
    {
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $address, $m)) {
            return [trim($m[2]), trim($m[1], " \"'")];
        }
        return [trim($address), ''];
    }

    public function test(): TestResult
    {
        if ($this->host() === '') {
            return TestResult::fail('An SMTP host is required.');
        }
        if ($this->fromAddress() === '') {
            return TestResult::fail('A From address is required.');
        }
        if (!class_exists(PHPMailer::class)) {
            return TestResult::unavailable('PHPMailer is not installed.');
        }

        try {
            $mailer = $this->makeMailer();

            // Connect, authenticate, disconnect — proves the credentials and
            // the TLS settings without sending anything to anyone.
            if (!$mailer->smtpConnect()) {
                return TestResult::fail('Could not connect to the SMTP server.');
            }
            $mailer->smtpClose();
        } catch (PHPMailerException $e) {
            return TestResult::fail($this->explain($e->getMessage()), $e->getMessage());
        } catch (Throwable $e) {
            return TestResult::fail('SMTP connection failed.', $e->getMessage());
        }

        return TestResult::pass(sprintf('Connected to %s:%d.', $this->host(), $this->port()));
    }

    /**
     * Translate PHPMailer's terse errors into the fix.
     *
     * "SMTP connect() failed" is technically accurate and completely useless to
     * someone who has just mismatched port 465 with STARTTLS.
     */
    private function explain(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'could not authenticate')) {
            return 'The server refused those credentials. For Gmail and Outlook you need an app-specific password.';
        }
        if (str_contains($lower, 'connection refused') || str_contains($lower, 'connect() failed')) {
            return sprintf(
                'Nothing answered on %s:%d. Check the port matches the encryption setting (587 with STARTTLS, 465 with implicit TLS), and that your host allows outbound SMTP.',
                $this->host(),
                $this->port()
            );
        }
        if (str_contains($lower, 'ssl') || str_contains($lower, 'tls')) {
            return 'The TLS handshake failed. This is usually the port and encryption setting disagreeing.';
        }

        return 'Could not connect to the SMTP server.';
    }
}
