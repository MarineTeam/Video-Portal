<?php
/**
 * Uninstalling drops the comments.
 *
 * This is the irreversible half of the lifecycle and the screen says so.
 * Deactivating is what an admin reaches for when they want the feature gone but
 * the conversation kept — that path leaves every row untouched, so reactivating
 * brings the whole archive back.
 *
 * Reports go first: they hold a foreign key into comments, and while the
 * cascade would handle it, depending on cascade order to get a drop right is
 * the kind of thing that works until somebody edits the schema.
 */

declare(strict_types=1);

/** @var \Portal\Db $db */

$db->execute('DROP TABLE IF EXISTS {comment_reports}');
$db->execute('DROP TABLE IF EXISTS {comments}');
