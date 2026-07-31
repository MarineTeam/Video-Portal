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
