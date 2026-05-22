<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Part A3 — agency-level snapshot-link defaults.
 *
 * Existing pattern (see Phase 2 / 3e migrations): agency settings live as
 * columns on the agencies table. SnapshotLinkService reads these to pick
 * the default expiry + decide whether to mask IPs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'snapshot_link_default_expiry_days')) {
                $table->unsignedSmallInteger('snapshot_link_default_expiry_days')
                    ->default(21)
                    ->after('presentations_default_opportunity_cost_pct')
                    ->comment('Default expiry window for /p/{token} share links.');
            }
            if (!Schema::hasColumn('agencies', 'snapshot_link_ip_masking')) {
                $table->boolean('snapshot_link_ip_masking')
                    ->default(true)
                    ->after('snapshot_link_default_expiry_days')
                    ->comment('When true, store IPs masked to /24 (POPIA-respectful). Opt-out only when fraud investigation requires it.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['snapshot_link_ip_masking', 'snapshot_link_default_expiry_days'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
