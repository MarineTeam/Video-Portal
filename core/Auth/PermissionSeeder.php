<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;

/**
 * Creates the default roles and capabilities.
 *
 * Idempotent by design: it runs at install, and again after any upgrade that
 * introduces a new capability. Re-running must never disturb what an admin has
 * customized, so it only ever inserts what is missing — it does not reset a
 * role whose capabilities have been edited, and it never removes a capability
 * that code no longer references, because a grant may still point at it.
 */
final class PermissionSeeder
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array{capabilities: int, roles: int} counts of newly created rows
     */
    public function seed(): array
    {
        $newCapabilities = 0;
        $newRoles = 0;

        foreach (Capability::all() as $slug => $description) {
            $affected = $this->db->execute(
                'INSERT INTO {capabilities} (slug, description) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE description = VALUES(description)',
                [$slug, $description]
            );
            // MySQL reports 1 for an insert and 2 for an update-in-place.
            if ($affected === 1) {
                $newCapabilities++;
            }
        }

        $position = 0;
        foreach (Capability::defaultRoles() as $slug => $definition) {
            $position += 10;

            $existing = $this->db->value('SELECT id FROM {roles} WHERE slug = ?', [$slug]);

            if ($existing === null) {
                $roleId = $this->db->insert('roles', [
                    'slug'        => $slug,
                    'name'        => $definition['name'],
                    'description' => $definition['description'],
                    'is_system'   => 1,
                    'position'    => $position,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $newRoles++;

                // Only attach capabilities when creating the role. On a re-run
                // an admin may have deliberately removed one, and silently
                // restoring it would undo their decision without telling them.
                foreach ($definition['capabilities'] as $capability) {
                    $this->attach($roleId, $capability);
                }
            }
        }

        return ['capabilities' => $newCapabilities, 'roles' => $newRoles];
    }

    private function attach(int $roleId, string $capabilitySlug): void
    {
        $capabilityId = $this->db->value('SELECT id FROM {capabilities} WHERE slug = ?', [$capabilitySlug]);
        if ($capabilityId === null) {
            return;
        }

        $this->db->execute(
            'INSERT IGNORE INTO {role_capabilities} (role_id, capability_id) VALUES (?, ?)',
            [$roleId, (int) $capabilityId]
        );
    }

    /**
     * Register a capability declared by a plugin.
     *
     * Tagged with the owning plugin so uninstalling cleans up after itself.
     */
    public function registerPluginCapability(string $pluginSlug, string $capabilitySlug, string $description): void
    {
        $this->db->execute(
            'INSERT INTO {capabilities} (slug, description, owner_plugin) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), owner_plugin = VALUES(owner_plugin)',
            [$capabilitySlug, $description, $pluginSlug]
        );
    }

    /**
     * Remove capabilities a plugin owned.
     *
     * The foreign keys cascade to role_capabilities, group_capabilities, and
     * grants — which is correct: a grant of a capability nothing can check any
     * more is noise that would otherwise accumulate forever.
     */
    public function removePluginCapabilities(string $pluginSlug): int
    {
        return $this->db->execute('DELETE FROM {capabilities} WHERE owner_plugin = ?', [$pluginSlug]);
    }
}
