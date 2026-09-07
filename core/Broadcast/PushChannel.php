<?php

declare(strict_types=1);

namespace Portal\Broadcast;

/**
 * Sending one push notification, without core knowing how.
 *
 * PUSH IS A PLUGIN. Its tables, its VAPID keys and its RFC 8291 encryption all
 * live in plugins/push, and core has no business reaching into them — a site
 * that never activated it must still be able to write and send a broadcast.
 *
 * So core declares the shape it needs and the plugin supplies it. When nothing
 * has, the sender says "push is not switched on for this site" against those
 * rows, which is a true sentence somebody can act on, rather than throwing on a
 * missing class.
 */
interface PushChannel
{
    /**
     * @param string $endpoint the push service URL for one device
     * @return string|null null on success, or why it failed
     */
    public function send(string $endpoint, string $title, string $body): ?string;
}
