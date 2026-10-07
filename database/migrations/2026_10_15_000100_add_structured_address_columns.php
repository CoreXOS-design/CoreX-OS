<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured address matching, step 2 (.ai/specs/structured-address-matching.md §3).
 *
 * ONE address layout across properties, tracked_properties and tracked_property_addresses.
 * Everything here is NEW and nullable — no existing column is changed, dropped or rewritten
 * (the layer sits BESIDE the raw columns: street_name may still carry "19 Grindewald Drive" or a
 * "Cadastral Extent … M²" tail, street_core/street_type hold the clean parse of it).
 * Each addition is guarded with hasColumn() so the migration is safe to re-run on a database
 * that already has any of it.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── properties ──────────────────────────────────────────────────────
        // p24_suburb_id and erf_portion already exist here.
        Schema::table('properties', function (Blueprint $t) {
            if (! Schema::hasColumn('properties', 'street_core')) {
                $t->string('street_core', 120)->nullable()->after('street_name_normalised');
            }
            if (! Schema::hasColumn('properties', 'street_type')) {
                $t->string('street_type', 30)->nullable()->after('street_core');
            }
            if (! Schema::hasColumn('properties', 'scheme_number')) {
                $t->string('scheme_number', 50)->nullable()->after('complex_name');
            }
            if (! Schema::hasColumn('properties', 'township')) {
                $t->string('township', 120)->nullable();
            }
            if (! Schema::hasColumn('properties', 'lpi_code')) {
                $t->string('lpi_code', 32)->nullable();
            }
            if (! Schema::hasColumn('properties', 'address_raw')) {
                $t->text('address_raw')->nullable();
            }
            if (! Schema::hasColumn('properties', 'address_parse_status')) {
                $t->string('address_parse_status', 20)->nullable();
            }
            if (! Schema::hasColumn('properties', 'address_parse_note')) {
                $t->string('address_parse_note', 255)->nullable();
            }
        });
        $this->addIndexes('properties', [
            'idx_prop_addr_street' => ['agency_id', 'p24_suburb_id', 'street_core', 'street_number'],
            'idx_prop_addr_status' => ['agency_id', 'address_parse_status'],
            'idx_prop_addr_lpi'    => ['agency_id', 'lpi_code'],
        ]);

        // ── tracked_properties ─────────────────────────────────────────────
        // scheme_number already exists here; p24 ids, core/type, portion, township, LPI, raw, status do not.
        Schema::table('tracked_properties', function (Blueprint $t) {
            if (! Schema::hasColumn('tracked_properties', 'p24_suburb_id')) {
                $t->unsignedBigInteger('p24_suburb_id')->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'p24_city_id')) {
                $t->unsignedBigInteger('p24_city_id')->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'street_core')) {
                $t->string('street_core', 120)->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'street_type')) {
                $t->string('street_type', 30)->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'erf_portion')) {
                $t->string('erf_portion', 20)->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'township')) {
                $t->string('township', 120)->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'lpi_code')) {
                $t->string('lpi_code', 32)->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'address_raw')) {
                $t->text('address_raw')->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'address_parse_status')) {
                $t->string('address_parse_status', 20)->nullable();
            }
            if (! Schema::hasColumn('tracked_properties', 'address_parse_note')) {
                $t->string('address_parse_note', 255)->nullable();
            }
        });
        $this->addIndexes('tracked_properties', [
            'idx_tp_addr_street' => ['agency_id', 'p24_suburb_id', 'street_core', 'street_number'],
            'idx_tp_addr_erf'    => ['agency_id', 'erf_number', 'erf_portion'],
            'idx_tp_addr_status' => ['agency_id', 'address_parse_status'],
            'idx_tp_addr_lpi'    => ['agency_id', 'lpi_code'],
        ]);

        // ── tracked_property_addresses (per-capture address history) ───────
        Schema::table('tracked_property_addresses', function (Blueprint $t) {
            if (! Schema::hasColumn('tracked_property_addresses', 'p24_suburb_id')) {
                $t->unsignedBigInteger('p24_suburb_id')->nullable();
            }
            if (! Schema::hasColumn('tracked_property_addresses', 'street_core')) {
                $t->string('street_core', 120)->nullable();
            }
            if (! Schema::hasColumn('tracked_property_addresses', 'street_type')) {
                $t->string('street_type', 30)->nullable();
            }
            if (! Schema::hasColumn('tracked_property_addresses', 'address_raw')) {
                $t->text('address_raw')->nullable();
            }
        });
        $this->addIndexes('tracked_property_addresses', [
            'idx_tpa_addr_street' => ['agency_id', 'p24_suburb_id', 'street_core', 'street_number'],
        ]);
    }

    public function down(): void
    {
        // Additive-only: nothing here ever held data that existed before it, and a rollback must
        // never drop columns a later backfill has filled. Intentionally a no-op.
    }

    /** @param array<string, array<int, string>> $indexes name => columns; skipped when a column is missing or the index exists. */
    private function addIndexes(string $table, array $indexes): void
    {
        foreach ($indexes as $name => $columns) {
            foreach ($columns as $c) {
                if (! Schema::hasColumn($table, $c)) {
                    continue 2;
                }
            }
            $exists = collect(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$name]))->isNotEmpty();
            if (! $exists) {
                Schema::table($table, function (Blueprint $t) use ($columns, $name) {
                    $t->index($columns, $name);
                });
            }
        }
    }
};
