<?php

declare(strict_types=1);

namespace Portal\Http;

use RuntimeException;

/**
 * Throwable that carries an HTTP status.
 *
 * Lets a model deep in a call stack refuse ("this video is in a category you
 * can't see") without every intermediate layer having to plumb a Response back
 * up. The router converts it, choosing HTML or JSON based on what the client
 * asked for.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'You do not have access to that.'): self
    {
        return new self(403, $message);
    }

    public static function unauthorized(string $message = 'Login required'): self
    {
        return new self(401, $message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    /**
     * 502 for a failing external dependency.
     *
     * Kept distinct from 500 on purpose: the predecessor apps used this to
     * make "bunny.net is down" visibly different from "our code is broken",
     * and the troubleshooting docs are written around that distinction.
     */
    public static function upstream(string $message): self
    {
        return new self(502, $message);
    }

    public static function tooManyRequests(string $message = 'Too many requests — slow down a little.'): self
    {
        return new self(429, $message);
    }

    public function toResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error($this->getMessage(), $this->status, $this->extra);
        }

        return Response::html(
            ErrorPage::render($this->status, $this->title(), $this->getMessage(), $this->detail),
            $this->status
        );
    }

    private function title(): string
    {
        return match ($this->status) {
            400     => 'That request did not make sense',
            401     => 'Please sign in',
            403     => 'No access',
            404     => 'Page not found',
            429     => 'Slow down',
            502     => 'A service we depend on is not responding',
            default => 'Something went wrong',
        };
    }
}
