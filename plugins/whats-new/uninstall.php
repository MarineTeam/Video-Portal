<?php
/**
 * Uninstalling forgets when everybody was last here.
 *
 * The irreversible half of the lifecycle. Deactivating is what an admin reaches
 * for when they want the badges gone — that path leaves every marker where it
 * is, so reactivating carries on rather than treating the whole site as
 * first-time visitors and badging nothing for a week.
 *
 * One table. Nothing was added to {users}, so there is nothing to un-add.
 */

declare(strict_types=1);

/** @var \Portal\Db $db */

$db->execute('DROP TABLE IF EXISTS {whats_new_visits}');
