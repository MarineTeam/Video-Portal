<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Http\Response;
use RuntimeException;

/**
 * That the guard is WIRED, not merely written.
 *
 * SecretGuardTest proves the class refuses a forbidden key. It says nothing
 * about whether anything calls it — and a guard installed on a path nothing
 * reaches is this project's signature defect, indistinguishable from a working
 * one until the day it matters.
 *
 * So these go through Response::json(), which is the method every JSON endpoint
 * in the application actually uses.
 */
final class ResponseSecretGuardTest extends TestCase
{
    /**
     * The choke point refuses. Every endpoint written from here on inherits
     * this without having to know it exists — which is why the guard was built
     * before the read API and the calendar feeds rather than alongside them.
     */
    public function testTheJsonExitRefusesToHandOutASecret(): void
    {
        $this->expectException(RuntimeException::class);

        Response::json(['devices' => [['name' => 'Phone', 'auth_secret' => 'xxx']]]);
    }

    /** Including on an error payload, which is still a payload. */
    public function testEvenAnErrorResponseIsChecked(): void
    {
        $this->expectException(RuntimeException::class);

        Response::error('Something failed', 400, ['token' => 'leaked-here']);
    }

    /** And an ordinary response is untouched — it still encodes and returns. */
    public function testAnOrdinaryJsonResponseStillWorks(): void
    {
        $response = Response::json(['ok' => true, 'videos' => [['title' => 'A sermon']]]);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"ok":true', $response->body());
        // headers() nests values, so it is flattened rather than assumed flat.
        $headers = $response->headers();
        $flat = [];
        array_walk_recursive($headers, static function ($v) use (&$flat): void {
            $flat[] = (string) $v;
        });

        self::assertStringContainsString('application/json', implode(' ', $flat));
    }
}
