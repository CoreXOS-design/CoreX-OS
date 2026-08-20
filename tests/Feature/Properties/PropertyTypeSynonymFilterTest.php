<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-08-20 properties-filter audit — the Property Type filter compared
 * property_type by exact string only. Live data showed ~2,600 properties
 * (a P24 bulk import batch plus older manual entries) stored under a
 * shorter/older label for the same type than today's PropertySettingItem
 * name — e.g. 'Apartment' instead of 'Apartment / Flat' — so selecting
 * "Apartment / Flat" hid every one of those 2,102 properties.
 *
 * Fix: Property::propertyTypeSynonyms() additionally matches the known old
 * labels for a canonical type, without touching what's stored on the row.
 */
final class PropertyTypeSynonymFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_type_filter_matches_legacy_synonym_labels(): void
    {
        $agencyId = $this->makeAgency();
        $admin    = $this->agencyUser($agencyId, 'admin');

        $canonical = $this->property($agencyId, $admin, 'Canonical-Flat', 'Apartment / Flat');
        $legacy    = $this->property($agencyId, $admin, 'Legacy-Apartment', 'Apartment');
        $unrelated = $this->property($agencyId, $admin, 'Unrelated-House', 'House');

        $this->actingAs($admin)
            ->get(route('corex.properties.index', ['property_type' => 'Apartment / Flat', 'agent_ids' => 'all']))
            ->assertOk()
            ->assertSee('Canonical-Flat')
            ->assertSee('Legacy-Apartment')
            ->assertDontSee('Unrelated-House');
    }

    // ── Helpers (mirrors PropertyFilterPersistenceTest) ─────────────────────

    private function agencyUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, User $agent, string $title, string $propertyType): Property
    {
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => $propertyType,
        ]);
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }
}
