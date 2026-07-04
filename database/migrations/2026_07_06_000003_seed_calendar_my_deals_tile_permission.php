<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-164 §15.5 — the "My Deals" Deck tile capability.
 *
 * The tile surfaces DR2 (DealV2) pipeline attention. DR2 is HELD behind its own
 * programme; there are no live deals_v2 rows and no DR2 UI. So the tile ships
 * FLAGGED HIDDEN: the capability is seeded but granted to NO role (default OFF),
 * so it is absent for every non-owner role. When DR2 goes live, granting this
 * capability in Role Manager lights the tile up with no rebuild (§15.5).
 *
 * Config `config/corex-permissions.php` carries the same row (source of truth for
 * `corex:sync-permissions`); this migration is the deploy-time backstop so the row
 * lands on a `migrate --force` deploy without anyone running the sync command
 * (BUILD_STANDARD §8 — reference data travels with the deploy). Idempotent by key.
 *
 * NOTE (deliberate, recorded): owner roles bypass all permission checks
 * (PermissionService::userHasPermission), so an owner CAN pick this tile even
 * while OFF — but DR2 has zero rows, so it renders an empty "All clear" tile.
 * The meaningful gate holds for every non-owner role. No code feature-flag is
 * introduced; gating is purely permission-based per the spec.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $key = 'calendar.tile.my_deals';

        $existing = DB::table('nexus_permissions')->where('key', $key)->first();
        $payload = [
            'label'      => 'Calendar Deck — My Deals Tile',
            'section'    => 'command-center',
            'type'       => 'action',
            'module'     => 'command_center_calendar',
            'sort_order' => 18,
            'updated_at' => $now,
        ];

        if ($existing) {
            $update = $payload;
            if ($existing->deleted_at !== null) {
                $update['deleted_at'] = null; // restore if previously soft-deleted
            }
            DB::table('nexus_permissions')->where('id', $existing->id)->update($update);
        } else {
            DB::table('nexus_permissions')->insert(array_merge(
                ['key' => $key, 'created_at' => $now],
                $payload
            ));
        }

        // Intentionally NO role_permissions rows — the tile is OFF for every role
        // until DR2 ships and an admin grants it.
    }

    public function down(): void
    {
        // Soft-delete the permission (no hard deletes — CLAUDE.md non-negotiable #1).
        DB::table('nexus_permissions')
            ->where('key', 'calendar.tile.my_deals')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
