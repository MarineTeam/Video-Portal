<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Crypto;
use Portal\Support\Cron;

/**
 * The endpoint a host's scheduler calls.
 *
 * Authenticated by a secret in the query string rather than a session, because
 * cron has no browser and no cookies. The secret is generated at install,
 * stored in config.php, and shown once on the finish screen.
 */
final class CronController extends Controller
{
    public function run(Request $request): Response
    {
        $expected = $this->config()->str('cron_secret');
        $supplied = $request->query('key') ?? '';

        // An unset secret must not mean "everyone is authorised".
        if ($expected === '') {
            return Response::text('Scheduled tasks are not configured.', 503);
        }

        // Constant-time: a naive === leaks the secret one byte at a time to
        // anyone willing to measure, and this endpoint can be called freely.
        if (!Crypto::verify($expected, $supplied)) {
            // 404 rather than 403, so the endpoint's existence is not
            // confirmed to someone guessing.
            return Response::text('Not found', 404);
        }

        /** @var Cron $cron */
        $cron = $this->container->get(Cron::class);

        $results = $cron->runDue();

        if ($results === []) {
            return Response::text('Nothing was due.');
        }

        $lines = [];
        foreach ($results as $result) {
            $lines[] = sprintf('[%s] %s: %s', $result['status'], $result['slug'], $result['message']);
        }

        return Response::text(implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
