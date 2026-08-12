<?php
/**
 * Uninstalling drops the reactions.
 *
 * The irreversible half of the lifecycle. Deactivating is what an admin reaches
 * for when they want the buttons gone but the responses kept — that path leaves
 * every row untouched, so reactivating brings the whole history back.
 *
 * One table, because there is no derived data to drop first. See the migration
 * for why a count is queried rather than cached.
 */

declare(strict_types=1);

/** @var \Portal\Db $db */

$db->execute('DROP TABLE IF EXISTS {reactions}');
