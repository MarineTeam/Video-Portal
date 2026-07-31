<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * Everything the browser needs to upload a file straight to the provider.
 *
 * The point of this object is what it does NOT contain: the provider's API
 * key. The signature is computed server-side from the key and hands the browser
 * a time-limited capability to upload one specific video and nothing else.
 * The API key never reaches the client, and the file never touches the app
 * server — which matters enormously on shared hosting, where a 2GB upload
 * through PHP would hit the memory limit, the POST size limit, and the request
 * timeout, in that order.
 *
 * @see BunnyStreamProvider::createUploadTicket() for the bunny.net signature.
 */
final class UploadTicket
{
    /** @param array<string, string> $headers Sent by the browser with the upload. */
    public function __construct(
        public readonly string $providerId,
        public readonly string $endpoint,
        public readonly array $headers,
        public readonly int $expiresAt,
        /** TUS, or a plain PUT for providers that don't do resumable uploads. */
        public readonly string $protocol = 'tus',
        /** @var array<string, string> Arbitrary metadata the client echoes back. */
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> Shape consumed by the admin upload JS. */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'endpoint'   => $this->endpoint,
            'headers'    => $this->headers,
            'expiresAt'  => $this->expiresAt,
            'protocol'   => $this->protocol,
            'metadata'   => $this->metadata,
        ];
    }
}
