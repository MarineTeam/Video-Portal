<?php
/**
 * Uninstalling drops the ratings.
 *
 * The irreversible half of the lifecycle. Deactivating is what an admin reaches
 * for when they want the stars gone but the opinions kept — that path leaves
 * every row untouched, so reactivating brings the whole history back with its
 * averages intact.
 *
 * Totals go first. They hold a foreign key into {videos} and not into
 * {ratings}, so the order does not strictly matter here — but dropping derived
 * data before the data it derives from is the habit that keeps mattering as
 * schemas change.
 */

declare(strict_types=1);

/** @var \Portal\Db $db */

$db->execute('DROP TABLE IF EXISTS {rating_totals}');
$db->execute('DROP TABLE IF EXISTS {ratings}');
