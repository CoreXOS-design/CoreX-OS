<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use App\Models\Property;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The extent contract (.ai/specs/deeds-capture.md §6 Part A, Johan 2026-08-19):
 * three distinct values, three distinct homes, never substituted.
 *   erf_extent_m2       (freehold "Extent")           -> tracked_properties.erf_size_m2
 *   cadastral_extent_m2 (freehold "Cadastral extent") -> tracked_properties.cadastral_extent
 *   section_extent_m2   (sectional "Section extent")  -> tracked_properties.section_extent_m2
 *
 * Root cause fixed: section_extent_m2 used to overload cadastral_extent, and
 * promoteToStock() copied cadastral_extent straight into a Property's
 * erf_size_m2 regardless of title type — a sectional unit's floor area
 * landing in a freehold erf-size column (observed live: properties 6166,
 * 6186). Proves the fix end to end: ingest routes each value to its own
 * column, and promoteToStock() routes each TP column to its own Property
 * column (erf_size_m2 -> erf_size_m2, section_extent_m2 -> size_m2).
 */
final class DeedsCaptureExtentContractTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function ingest(string $ref, array $property): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('v1.deeds-capture'), [
            'source' => 'cmainfo',
            'captures' => [['source_ref' => $ref, 'property' => $property]],
        ]);
    }

    public function test_freehold_extent_and_cadastral_extent_land_in_separate_columns(): void
    {
        $ref = 'ERF-' . Str::random(8);
        $this->ingest($ref, [
            'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach', 'erf_number' => '234',
            'erf_extent_m2' => 950, 'cadastral_extent_m2' => 947.25,
        ])->assertOk();

        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(950.0, (float) $tp->erf_size_m2, 0.01);
        $this->assertSame('947.25', $tp->cadastral_extent);
        $this->assertNull($tp->section_extent_m2, 'a freehold capture must never populate section_extent_m2');
    }

    public function test_sectional_extent_lands_in_its_own_column_never_erf_size(): void
    {
        $ref = 'SS-' . Str::random(8);
        $this->ingest($ref, [
            'scheme_name' => 'Topanga', 'scheme_number' => '357/2008', 'section_number' => '1',
            'suburb' => 'Uvongo Beach', 'section_extent_m2' => 88.4,
        ])->assertOk();

        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(88.4, (float) $tp->section_extent_m2, 0.01);
        $this->assertNull($tp->erf_size_m2, 'a sectional capture must never populate erf_size_m2');
        $this->assertNull($tp->cadastral_extent, 'section_extent_m2 must not also land in cadastral_extent');
    }

    public function test_promote_routes_freehold_erf_size_to_property_erf_size(): void
    {
        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach',
            'erf_number' => '234', 'erf_size_m2' => 950, 'first_seen_at' => now(),
        ]);

        $property = app(TrackedPropertyMatchOrCreateService::class)->promoteToStock($tp->id, $this->user->id, []);

        $this->assertEqualsWithDelta(950.0, (float) $property->erf_size_m2, 0.01);
        $this->assertNull($property->size_m2);
    }

    public function test_promote_routes_sectional_extent_to_property_size_m2_not_erf_size(): void
    {
        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'scheme_name' => 'Topanga', 'scheme_number' => '357/2008',
            'section_number' => '1', 'suburb' => 'Uvongo Beach', 'section_extent_m2' => 88.4,
            'first_seen_at' => now(),
        ]);

        $property = app(TrackedPropertyMatchOrCreateService::class)->promoteToStock($tp->id, $this->user->id, []);

        $this->assertEqualsWithDelta(88.4, (float) $property->size_m2, 0.01);
        $this->assertNull(
            $property->erf_size_m2,
            'the root-cause bug: a sectional unit size must NEVER land in the erf-size column (properties 6166/6186)'
        );
    }

    public function test_backward_compatible_section_extent_m2_field_name_still_accepted(): void
    {
        // An older extension build predating erf_extent_m2/cadastral_extent_m2
        // still sends section_extent_m2 under its original name — must keep
        // working, and now lands in the CORRECT column (not cadastral_extent).
        $ref = 'SS-' . Str::random(8);
        $this->ingest($ref, [
            'scheme_name' => 'Corona Del Mar', 'section_number' => '2',
            'suburb' => 'Uvongo Beach', 'section_extent_m2' => 192,
        ])->assertOk();

        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(192.0, (float) $tp->section_extent_m2, 0.01);
    }
}
