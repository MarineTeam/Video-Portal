<?php
/**
 * Uninstalling forgets every subscription.
 *
 * The irreversible half. Deactivating is what an admin reaches for when they
 * want notifications to stop — that path leaves every row alone, so switching
 * it back on resumes without anybody having to allow notifications again.
 *
 * The ledger goes first. It is derived data: it records which videos have been
 * announced, and dropping derived data before the thing it derives from is the
 * habit that keeps mattering as schemas change.
 *
 * The VAPID keys go with the plugin's settings row, which the lifecycle removes
 * on its own — and that is correct rather than incidental. A key pair left
 * behind would be re-adopted on reinstall, and every browser that had
 * unsubscribed in between would start receiving notifications again.
 */

declare(strict_types=1);

/** @var \Portal\Db $db */

$db->execute('DROP TABLE IF EXISTS {pushed_videos}');
$db->execute('DROP TABLE IF EXISTS {push_subscriptions}');
