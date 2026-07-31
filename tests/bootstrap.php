<?php
/**
 * Test bootstrap.
 *
 * Loads the application the same way a real request does, so the tests exercise
 * the actual autoloader and constants rather than a parallel arrangement that
 * could drift from production.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

// Deterministic time zone and locale, so date formatting assertions don't
// depend on whose machine is running them.
date_default_timezone_set('UTC');

/*
 * Send error_log() to a file rather than stderr.
 *
 * Several tests deliberately trigger logged warnings — a plugin callback that
 * throws, a filter that forgets to return — because containing those is the
 * behaviour under test. Left on stderr they bury the actual test results in
 * noise that looks like failure. The log is still written, so it can be read
 * when a test fails for real.
 */
$logDirectory = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDirectory)) {
    @mkdir($logDirectory, 0775, true);
}
ini_set('error_log', $logDirectory . '/test.log');
