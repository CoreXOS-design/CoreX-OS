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
 * Properties index — "unit N" / "erf N" search keywords.
 *
 * Guards AT-274: searching "unit 14" tokenized into ["unit", "14"] ANDed
 * together, but each token was OR'd across ALL 11 address columns. So
 * "unit" could match one property's title while "14" independently matched
 * an UNRELATED column (street_number, property_number, p24_ref, ...) on
 * that same row — a false-positive cross-column match with no relevance
 * signal to rank it below the true "Unit 14" match. The fix binds a
 * "unit"/"erf" keyword's value token to the semantically correct column(s)
 * only, with a narrow fallback to street_number for legacy UNIT-AS-NUMBER
 * rows (PropertyAddressReconciler) where unit_number is still empty.
 */
final class PropertySearchKeywordTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_keyword_does_not_false_positive_on_an_unrelated_column(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Unit 13's OWN street number happens to be 14 — a perfectly ordinary,
        // unrelated fact that must NOT make it match a search for "unit 14".
        $this->property($agency, $admin, 'ZZZ-Unit-Thirteen', [
            'unit_number'   => '13',
            'street_number' => '14',
            'street_name'   => 'Marine Drive',
        ]);
        $this->property($agency, $admin, 'ZZZ-Unit-Fourteen', [
            'unit_number'   => '14',
            'street_number' => '9',
            'street_name'   => 'Ocean Road',
        ]);

        $this->get(route('corex.properties.index', ['search' => 'unit 14']))
            ->assertOk()
            ->assertSee('ZZZ-Unit-Fourteen')
            ->assertDontSee('ZZZ-Unit-Thirteen');
    }

    public function test_unit_keyword_still_falls_back_to_street_number_for_legacy_unit_as_number_rows(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Legacy drift row: unit_number was never populated, and the unit lives
        // in street_number instead (PropertyAddressReconciler's UNIT-AS-NUMBER
        // pattern). "unit 14" must still surface it.
        $this->property($agency, $admin, 'ZZZ-Legacy-Unit', [
            'unit_number'   => null,
            'street_number' => '14',
            'street_name'   => 'Casa Montana',
        ]);

        $this->get(route('corex.properties.index', ['search' => 'unit 14']))
            ->assertOk()
            ->assertSee('ZZZ-Legacy-Unit');
    }

    public function test_erf_keyword_does_not_false_positive_on_an_unrelated_column(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // p24_ref coincidentally contains "442" — must not satisfy "erf 442".
        $this->property($agency, $admin, 'ZZZ-Wrong-Erf', [
            'erf_number' => '100',
            'p24_ref'    => 'P24-442',
        ]);
        $this->property($agency, $admin, 'ZZZ-Right-Erf', [
            'erf_number' => '442',
        ]);

        $this->get(route('corex.properties.index', ['search' => 'erf 442']))
            ->assertOk()
            ->assertSee('ZZZ-Right-Erf')
            ->assertDontSee('ZZZ-Wrong-Erf');
    }

    // ── helpers (mirrors PropertyFilterPersistenceTest) ─────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();
        return [$agencyId, $this->agencyUser($agencyId, 'admin')];
    }

    private function agencyUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
        ], $attrs));
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
