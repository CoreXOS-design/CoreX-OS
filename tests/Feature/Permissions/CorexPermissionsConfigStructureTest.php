<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use Tests\TestCase;

/**
 * Regression guard for a real bug found while building AT-432 (auctions):
 * a whole block of 20 permission definitions was appended AFTER the
 * `'permissions' => [...]` array's own closing `],` instead of inside it,
 * landing as top-level numeric-keyed siblings of `permissions` in the outer
 * config array. `php artisan corex:sync-permissions` reads
 * `config('corex-permissions.permissions')` specifically, so every one of
 * those 20 keys was invisible to it — "0 created" with no error, no
 * warning. Every `middleware('permission:auctions.*')` gate and every
 * `@permission('auctions.*')` Blade check would have silently referred to a
 * permission key that could never exist in `nexus_permissions`.
 *
 * This asserts the shape directly against the live config file, so a future
 * edit that repeats the same bracket mistake — for ANY module, not just
 * auctions — fails loudly here instead of failing silently in production.
 */
final class CorexPermissionsConfigStructureTest extends TestCase
{
    public function test_every_top_level_key_is_one_of_the_four_expected_sections(): void
    {
        $config = config('corex-permissions');

        $this->assertSame(
            ['permissions', 'role_defaults', 'scope_defaults', 'shared_scope_modules'],
            array_keys($config),
            'corex-permissions.php gained a stray top-level key — almost certainly a permission '
            .'definition block accidentally placed OUTSIDE the permissions array (check for a '
            .'misplaced closing bracket near where the new block was inserted).',
        );
    }

    public function test_every_permission_definition_is_a_well_formed_array_inside_the_permissions_key(): void
    {
        $permissions = config('corex-permissions.permissions');
        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);

        foreach ($permissions as $i => $definition) {
            $this->assertIsArray($definition, "permissions[{$i}] is not an array — check for a stray top-level entry.");
            $this->assertArrayHasKey('key', $definition, "permissions[{$i}] is missing 'key'.");
            $this->assertArrayHasKey('type', $definition, "permissions[{$i}] ({$definition['key']}) is missing 'type'.");
            $this->assertContains($definition['type'], ['access', 'action'], "permissions[{$i}] ({$definition['key']}) has an invalid type.");
        }
    }

    public function test_every_permission_key_is_unique(): void
    {
        $keys = array_column(config('corex-permissions.permissions'), 'key');

        $this->assertSame(
            array_values(array_unique($keys)),
            array_values($keys),
            'Duplicate permission key(s) found: '.implode(', ', array_diff_assoc($keys, array_unique($keys))),
        );
    }

    /** AT-432 — the specific 20 keys the bug above made invisible to corex:sync-permissions. */
    public function test_every_auctions_permission_key_is_present_inside_the_permissions_array(): void
    {
        $keys = array_column(config('corex-permissions.permissions'), 'key');

        foreach ([
            'access_auctions', 'auctions.view', 'auctions.create', 'auctions.edit', 'auctions.archive',
            'auctions.publish', 'auctions.reserve.view', 'auctions.reserve.edit', 'auctions.bidders.view',
            'auctions.bidders.view_all', 'auctions.bidders.approve', 'auctions.bidders.verify_fica',
            'auctions.bidders.deposits', 'auctions.room.operate', 'auctions.bid.record', 'auctions.bid.retract',
            'auctions.hammer', 'auctions.results.view', 'auctions.results.export', 'auctions.manage_settings',
        ] as $key) {
            $this->assertContains($key, $keys, "'{$key}' is missing from config('corex-permissions.permissions').");
        }
    }
}
