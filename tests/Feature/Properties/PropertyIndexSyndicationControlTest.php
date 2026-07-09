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
 * Properties index — the per-property syndication control (AT-208).
 *
 * The control (card top-right, and before "View" in the list) opens a viewer
 * showing where the listing is published. It is present ONLY when both hold:
 *
 *   - the listing is allowed to market (compliance snapshot taken, or every
 *     readiness gate passed) — mirrors $isMarketable on the property page, and
 *   - the listing actually reaches at least one portal.
 *
 * Either condition failing removes the control entirely — a blocked listing,
 * or one syndicated nowhere, has no syndication to look at.
 */
final class PropertyIndexSyndicationControlTest extends TestCase
{
    use RefreshDatabase;

    /** The button carries this aria-label/title prefix — the marker we assert on. */
    private const MARKER = 'Syndication — live on';

    public function test_control_shows_for_a_marketable_listing_that_is_live_on_a_portal(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $p = $this->property($agencyId, $admin, 'ZZZ-Syndicated-House', [
            // Snapshot taken → marketing_status 'live' → marketable.
            'compliance_snapshot_at'  => now(),
            // Live on Property24 → portalLinks() reports a live portal + URL.
            'p24_ref'                 => '112233445',
            'p24_syndication_status'  => 'active',
        ]);

        $res = $this->get(route('corex.properties.index'))->assertOk();

        $res->assertSee('ZZZ-Syndicated-House');
        $res->assertSee(self::MARKER, false);
        $res->assertSee('Property24', false);
        // The viewer links straight out to the live listing.
        $res->assertSee('www.property24.com', false);
        $this->assertNotNull($p->fresh()->portalLinks()[1]['url']);
    }

    public function test_control_is_hidden_when_the_listing_reaches_no_portal(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Marketable, but published nowhere.
        $this->property($agencyId, $admin, 'ZZZ-Nowhere-House', [
            'compliance_snapshot_at' => now(),
        ]);

        $this->get(route('corex.properties.index'))
            ->assertOk()
            ->assertSee('ZZZ-Nowhere-House')
            ->assertDontSee(self::MARKER, false);
    }

    public function test_control_is_hidden_when_the_listing_may_not_be_published_yet(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Live on a portal, but no compliance snapshot and gates unmet → blocked.
        $this->property($agencyId, $admin, 'ZZZ-Blocked-House', [
            'p24_ref'                => '556677889',
            'p24_syndication_status' => 'active',
        ]);

        $res = $this->get(route('corex.properties.index'))->assertOk();
        $res->assertSee('ZZZ-Blocked-House');
        $res->assertSee('Blocked', false);
        $res->assertDontSee(self::MARKER, false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'admin',
        ])];
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
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
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
