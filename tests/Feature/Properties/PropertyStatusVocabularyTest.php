<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Rules\AllowedPropertyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-307 — server-side property-status membership guard.
 *
 * `properties.status` is a free-text VARCHAR; the UI picks from the settings list,
 * but non-UI paths (mobile API, imports, jobs, crafted requests) could persist any
 * string (the 2903 mobile-withdraw class). This proves the guard at both layers:
 * the model/observer chokepoint (every write) and the request rule (clean 422).
 */
final class PropertyStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agentId = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ])->id;

        Property::clearAllowedStatusCache();
    }

    // ── model / vocabulary layer ────────────────────────────────────────────

    public function test_system_statuses_pass_case_insensitively(): void
    {
        foreach (['active', 'draft', 'sold', 'withdrawn', 'expired', 'under_offer', 'let_out', 'rented'] as $s) {
            $this->assertTrue(Property::isAllowedStatus($s, $this->agencyId), "system status '{$s}' must pass");
        }
        // properties.status is genuinely mixed-case (P24 writes 'Active'/'Sold'/'Rented').
        foreach (['Sold', 'RENTED', 'Active'] as $s) {
            $this->assertTrue(Property::isAllowedStatus($s, $this->agencyId), "capitalised '{$s}' must pass");
        }
    }

    public function test_active_settings_status_passes_inactive_or_unknown_rejected(): void
    {
        PropertySettingItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'group' => PropertySettingItem::GROUP_STATUS,
            'name' => 'On Show', 'active' => true, 'sort_order' => 1,
        ]);
        PropertySettingItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'group' => PropertySettingItem::GROUP_STATUS,
            'name' => 'Sales Listing', 'active' => false, 'sort_order' => 2, // de-activated item
        ]);
        Property::clearAllowedStatusCache();

        $this->assertTrue(Property::isAllowedStatus('on_show', $this->agencyId), 'an ACTIVE settings status derives in');
        $this->assertFalse(Property::isAllowedStatus('sales_listing', $this->agencyId), 'a de-activated, non-system status is rejected');
        $this->assertFalse(Property::isAllowedStatus('totally_made_up', $this->agencyId), 'garbage rejected');
    }

    public function test_blank_and_null_are_not_out_of_vocab(): void
    {
        $this->assertTrue(Property::isAllowedStatus('', $this->agencyId));
        $this->assertTrue(Property::isAllowedStatus(null, $this->agencyId));
    }

    // ── observer layer (the universal chokepoint) ───────────────────────────

    public function test_observer_rejects_out_of_vocab_status_on_save(): void
    {
        $p = $this->property(['status' => 'active']);

        $p->status = 'not_a_real_status';
        try {
            $p->save();
            $this->fail('observer should reject an out-of-vocabulary status');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame('active', DB::table('properties')->where('id', $p->id)->value('status'),
            'the bad status must not have persisted');
    }

    public function test_observer_allows_valid_status_change(): void
    {
        $p = $this->property(['status' => 'active']);
        $p->status = 'Sold'; // capitalised system status
        $p->save();
        $this->assertSame('Sold', $p->fresh()->status);
    }

    public function test_observer_is_dirty_only_legacy_row_unrelated_save_succeeds(): void
    {
        // A legacy row with an out-of-vocab status, inserted RAW (bypasses the observer).
        $id = DB::table('properties')->insertGetId([
            'external_id' => 'AT307-' . Str::random(6), 'title' => 'Legacy', 'suburb' => 'Uvongo',
            'price' => 1_000_000, 'property_type' => 'house', 'beds' => 2, 'status' => 'legacy_junk',
            'is_demo' => false, 'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'agent_id' => $this->agentId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $p = Property::withoutGlobalScopes()->findOrFail($id);
        $p->price = 1_250_000; // status NOT dirty
        $p->save();            // must NOT throw

        $this->assertSame(1_250_000, (int) $p->fresh()->price);
        $this->assertSame('legacy_junk', $p->fresh()->status, 'the untouched legacy status is left alone');
    }

    // ── request layer (clean 422 via the rule) ──────────────────────────────

    public function test_request_rule_rejects_garbage_passes_valid_and_blank(): void
    {
        $mk = fn ($val) => Validator::make(
            ['status' => $val],
            ['status' => ['nullable', new AllowedPropertyStatus($this->agencyId)]]
        );

        $this->assertTrue($mk('garbage_status')->fails(), 'garbage → 422');
        $this->assertFalse($mk('active')->fails(), 'system status passes');
        $this->assertFalse($mk('Sold')->fails(), 'capitalised passes');
        $this->assertFalse($mk('')->fails(), 'blank passes (nullable governs emptiness)');
    }

    // ── helper ──────────────────────────────────────────────────────────────

    private function property(array $attrs): Property
    {
        $p = new Property();
        foreach (array_merge([
            'external_id' => 'AT307-' . Str::random(8), 'title' => 'Test', 'suburb' => 'Uvongo',
            'price' => 1_500_000, 'property_type' => 'house', 'beds' => 3, 'status' => 'active',
            'is_demo' => false, 'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'agent_id' => $this->agentId,
        ], $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p->fresh();
    }
}
