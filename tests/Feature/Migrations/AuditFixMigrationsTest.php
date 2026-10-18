<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Release audit 2026-10-18, batch 7 (migrations). These migrations have already run on QA1/QA2/Staging/live-testing; the
 * real live server still has to run all of them, so each test drives a migration's up() against the data that would
 * have broken or mis-set it on a first run.
 */
class AuditFixMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const DEAL_UNIQUE = 'deals_agency_id_deal_no_unique';

    private function migration(string $file): object
    {
        return require base_path('database/migrations/' . $file . '.php');
    }

    private function insertDeal(?int $agencyId, ?int $dealNo): void
    {
        DB::table('deals')->insert([
            'agency_id' => $agencyId, 'deal_no' => $dealNo, 'period' => '2026-10',
            'deal_date' => '2026-10-01', 'property_value' => 1000000, 'total_commission' => 50000,
        ]);
    }

    private function dealUniqueExists(): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', 'deals')
            ->where('index_name', self::DEAL_UNIQUE)->exists();
    }

    public function test_deal_no_unique_index_migration_skips_instead_of_throwing_on_duplicates(): void
    {
        $this->assertFalse($this->dealUniqueExists(), 'precondition: 203059 has dropped the index in the migrated schema');

        $this->insertDeal(1, 1001);
        $this->insertDeal(1, 1001);   // duplicate (agency_id, deal_no) - what live is expected to hold (#1826/#1827)
        $this->insertDeal(2, 1001);   // same number in another agency is fine

        $this->migration('2026_09_29_174606_add_unique_index_to_deals_deal_no')->up();

        $this->assertFalse($this->dealUniqueExists(), 'index must not be created while duplicates exist');
        $this->assertSame(3, DB::table('deals')->count(), 'no data may be changed');
    }

    public function test_deal_no_unique_index_migration_still_adds_the_index_when_clean_and_drop_migration_removes_it(): void
    {
        $this->insertDeal(1, 1001);
        $this->insertDeal(1, 1002);

        $add = $this->migration('2026_09_29_174606_add_unique_index_to_deals_deal_no');
        try {
            $add->up();
            $this->assertTrue($this->dealUniqueExists());

            $add->up();   // re-run on a database that already has the index: no error
            $this->assertTrue($this->dealUniqueExists());

            // 203059 must run cleanly with the index present ...
            $this->migration('2026_09_29_203059_drop_duplicate_deal_fix_objects')->up();
            $this->assertFalse($this->dealUniqueExists());

            // ... and without it.
            $this->migration('2026_09_29_203059_drop_duplicate_deal_fix_objects')->up();
            $this->assertFalse($this->dealUniqueExists());
        } finally {
            if ($this->dealUniqueExists()) {
                $add->down();
            }
        }
    }

    public function test_sequence_counter_and_create_token_migrations_are_safe_to_run_twice(): void
    {
        // The migrated schema has had these objects dropped by 203059. Recreate them with the originals, run each original
        // a second time and expect a no-op instead of "already exists" / "duplicate column".
        $this->migration('2026_09_29_174604_create_sequence_counters_table')->up();
        $this->migration('2026_09_29_174604_create_sequence_counters_table')->up();
        $this->assertTrue(Schema::hasTable('sequence_counters'));

        $this->migration('2026_09_29_174605_add_create_token_to_deals_table')->up();
        $this->migration('2026_09_29_174605_add_create_token_to_deals_table')->up();
        $this->migration('2026_09_29_174605_add_create_token_to_deals_v2_table')->up();
        $this->migration('2026_09_29_174605_add_create_token_to_deals_v2_table')->up();
        $this->assertTrue(Schema::hasColumn('deals', 'create_token'));
        $this->assertTrue(Schema::hasColumn('deals_v2', 'create_token'));

        // Leave the schema as the migration chain left it.
        $this->migration('2026_09_29_203059_drop_duplicate_deal_fix_objects')->up();
        $this->assertFalse(Schema::hasTable('sequence_counters'));
    }

    public function test_fica_copy_migration_carries_customised_values_to_the_new_columns_only(): void
    {
        $this->assertTrue(Schema::hasColumn('agencies', 'fica_link_days'), 'restored 2026_10_18_000100 provides the old columns');

        $mk = fn (string $name, array $cols) => DB::table('agencies')->insertGetId(['name' => $name, 'slug' => 'fica-' . strtolower($name)] + $cols);

        $custom = $mk('Custom', ['fica_link_days' => 30, 'fica_expiring_after_months' => 6]);
        $defaults = $mk('Defaults', ['fica_link_days' => 14, 'fica_expiring_after_months' => 11]);
        $alreadySet = $mk('Already', ['fica_link_days' => 30, 'fica_expiring_after_months' => 6,
            'fica_link_expiry_days' => 21, 'fica_current_months' => 9]);
        $outOfRange = $mk('OutOfRange', ['fica_link_days' => 200, 'fica_expiring_after_months' => 70]);

        $migration = $this->migration('2026_11_16_100000_copy_fica_request_settings_to_fica_windows');
        $migration->up();
        $migration->up();   // idempotent

        $row = fn (int $id) => DB::table('agencies')->where('id', $id)->first();

        $this->assertEquals(30, $row($custom)->fica_link_expiry_days);
        $this->assertEquals(6, $row($custom)->fica_current_months);

        $this->assertNull($row($defaults)->fica_link_expiry_days, 'default values stay NULL = code default');
        $this->assertNull($row($defaults)->fica_current_months);

        $this->assertEquals(21, $row($alreadySet)->fica_link_expiry_days, 'a value already on the new column is never overwritten');
        $this->assertEquals(9, $row($alreadySet)->fica_current_months);

        $this->assertNull($row($outOfRange)->fica_link_expiry_days, 'values FicaWindows would ignore are not copied');
        $this->assertNull($row($outOfRange)->fica_current_months);
    }

    public function test_cloned_outreach_templates_are_neutralised_by_slug_not_by_agency_id_one(): void
    {
        DB::table('agencies')->insertGetId(['name' => 'Placeholder', 'slug' => 'placeholder']);   // so HFC is not id 1
        $hfc = DB::table('agencies')->insertGetId(['name' => 'Home Finders Coastal', 'slug' => 'hfc-coastal']);
        $other = DB::table('agencies')->insertGetId(['name' => 'Cape Rentals', 'slug' => 'cape-rentals']);
        $this->assertNotSame(1, $hfc, 'HFC deliberately is not agency id 1 in this scenario');

        $first = 'Hi, this is Sam from Agency — a registered estate agency on the KZN South Coast.';
        $second = 'My name is Sam from Agency, a PPRA-registered agency on the KZN South Coast. Kind regards';

        foreach ([$hfc, $other] as $agencyId) {
            foreach ([$first, $second] as $i => $body) {
                DB::table('seller_outreach_templates')->insert($this->templateRow($agencyId, "T{$agencyId}-{$i}", $body));
            }
        }

        $this->migration('2026_10_14_150000_neutralise_cloned_hfc_outreach_template')->up();
        $this->migration('2026_11_16_100100_neutralise_remaining_cloned_hfc_outreach_phrases')->up();

        $bodies = fn (int $agencyId) => DB::table('seller_outreach_templates')->where('agency_id', $agencyId)->orderBy('id')->pluck('body')->all();

        $this->assertSame([$first, $second], $bodies($hfc), "HFC's own templates are untouched");
        $this->assertSame([
            'Hi, this is Sam from Agency, a registered estate agency working in {property_town}.',
            'My name is Sam from Agency, a PPRA-registered agency working in {property_town} Kind regards',
        ], array_map(fn ($b) => str_replace('{property_town}. Kind', '{property_town} Kind', $b), $bodies($other)));
    }

    private function templateRow(int $agencyId, string $name, string $body): array
    {
        $columns = Schema::getColumnListing('seller_outreach_templates');
        $row = ['agency_id' => $agencyId, 'name' => $name, 'body' => $body, 'created_at' => now(), 'updated_at' => now()];
        if (in_array('channel', $columns, true)) {
            $row['channel'] = 'email';
        }
        if (in_array('email_subject', $columns, true)) {
            $row['email_subject'] = 'Subject';
        }

        return array_intersect_key($row, array_flip($columns));
    }

    public function test_rts_settings_audit_gaps_migration_tolerates_a_column_that_already_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('rts_settings', 'add_to_day_max'));
        $this->migration('2026_10_29_100300_rts_settings_audit_gaps')->up();   // every column already there: no error
        $this->assertTrue(Schema::hasColumn('rts_settings', 'deleted_at'));
    }

    public function test_resolve_faults_catch_up_leaves_archived_work_orders_alone(): void
    {
        // Pure static check of the guard: the migration source must exclude soft-deleted rows on both queries.
        $source = file_get_contents(base_path('database/migrations/2026_10_21_100000_resolve_faults_left_waiting_on_tenant_check.php'));
        $this->assertSame(2, substr_count($source, "whereNull('deleted_at')"));
    }

    public function test_down_methods_no_longer_hard_delete(): void
    {
        foreach ([
            '2026_10_05_230600_seed_rental_vat_types_for_existing_agencies' => ['truncate'],
            '2026_10_10_100500_grant_rental_job_cards_share_permission' => ['forceDelete'],
            '2026_10_11_100800_grant_maintenance_flow_permissions' => ['forceDelete'],
            '2026_10_14_000200_seed_buyer_notes_view_permission' => ['->delete()'],
            '2026_10_13_100240_backfill_attendance_from_not_present_signatures' => ['->delete()'],
            '2026_09_29_174606_backfill_sequence_counters_for_deal_numbering' => ['->delete()'],
        ] as $file => $banned) {
            $source = file_get_contents(base_path("database/migrations/{$file}.php"));
            $down = preg_replace('#^\s*//.*$#m', '', substr($source, strpos($source, 'function down')));
            foreach ($banned as $needle) {
                $this->assertStringNotContainsString($needle, $down, "{$file} down() still contains {$needle}");
            }
        }
    }
}
