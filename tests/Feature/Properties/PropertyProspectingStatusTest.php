<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Http\Controllers\Concerns\EnforcesMarketingReadiness;
use App\Http\Controllers\CoreX\PropertyController;
use App\Http\Controllers\SellerOutreach\EntryPointController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\Syndication\DraftListingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — property-status
 * "Prospecting" build (2026-08-21).
 *
 * Covers exactly what Johan asked for: ingest paths create Prospecting,
 * agent-created paths are unaffected (regression), both one-click
 * transitions, the tile count matches the filtered list, and a
 * Prospecting property is refused by the ONE existing syndication guard
 * (no second mechanism — enforceListingNotDraft() now reads isOnMarket()).
 */
final class PropertyProspectingStatusTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
    }

    // ──────────────────────── ingest → Prospecting ──────────────────────────

    public function test_deeds_style_promote_to_stock_creates_prospecting(): void
    {
        $service = new TrackedPropertyMatchOrCreateService();

        $tp = $service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '7', 'street_name' => 'Deeds Rd', 'suburb' => 'Margate'],
            ['type' => 'deeds_capture', 'ref' => 'DC-1']
        );

        $property = $service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame(Property::STATUS_PROSPECTING, $property->status);
        $this->assertTrue($property->fresh()->isProspecting());
    }

    public function test_manual_promote_to_stock_still_creates_draft(): void
    {
        $service = new TrackedPropertyMatchOrCreateService();

        $tp = $service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '1', 'street_name' => 'Manual Rd', 'suburb' => 'Margate'],
            ['type' => 'manual_prospect_entry', 'ref' => 'MN-1']
        );

        $property = $service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame('draft', $property->status, 'a purely-manual TP must still promote to Draft, unchanged');
    }

    public function test_mic_promote_listing_to_property_creates_prospecting(): void
    {
        $listing = ProspectingListing::create([
            'agency_id'           => $this->agency->id,
            'captured_by_user_id' => $this->user->id,
            'portal_source'       => 'p24',
            'portal_ref'          => 'P24-MIC-1',
            'portal_url'          => 'https://www.property24.com/listing/P24-MIC-1',
            'address'             => '10 MIC Ave, Uvongo',
            'suburb'              => 'Uvongo',
            'price'               => 1_800_000,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'is_active'           => true,
        ]);

        $controller = app(EntryPointController::class);
        $method = new \ReflectionMethod(EntryPointController::class, 'promoteListingToProperty');
        $method->setAccessible(true);
        $property = $method->invoke($controller, $this->agency->id, $listing, $this->user, null, null);

        $this->assertInstanceOf(Property::class, $property);
        $this->assertSame(Property::STATUS_PROSPECTING, $property->status, 'the MIC entry-point path bypasses TrackedProperty entirely and must set Prospecting directly');
    }

    // ──────────────────────── transitions ────────────────────────────────────

    public function test_convert_from_prospecting_moves_to_draft(): void
    {
        $this->actingAs($this->user);
        $property = $this->prospectingProperty();

        $controller = app(PropertyController::class);
        $response = $controller->convertFromProspecting(Request::create('/x', 'POST'), $property);

        $this->assertSame('draft', $property->fresh()->status);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function test_convert_from_prospecting_rejects_when_not_prospecting(): void
    {
        $this->actingAs($this->user);
        $property = $this->prospectingProperty(['status' => 'draft']);

        $controller = app(PropertyController::class);
        $controller->convertFromProspecting(Request::create('/x', 'POST'), $property);

        $this->assertSame('draft', $property->fresh()->status, 'a non-Prospecting property must be left untouched');
    }

    public function test_mark_not_selling_moves_from_prospecting(): void
    {
        $this->actingAs($this->user);
        $property = $this->prospectingProperty();

        $controller = app(PropertyController::class);
        $controller->markNotSelling(Request::create('/x', 'POST'), $property);

        $this->assertSame(Property::STATUS_NOT_SELLING, $property->fresh()->status);
        $this->assertTrue($property->fresh()->isNotSelling());
    }

    public function test_mark_not_selling_rejects_when_not_prospecting(): void
    {
        $this->actingAs($this->user);
        $property = $this->prospectingProperty(['status' => 'active']);

        $controller = app(PropertyController::class);
        $controller->markNotSelling(Request::create('/x', 'POST'), $property);

        $this->assertSame('active', $property->fresh()->status, 'only a Prospecting property may be marked Not selling');
    }

    // ──────────────────────── tile / filtered-list parity ────────────────────

    public function test_prospecting_tile_count_matches_filtered_list_count(): void
    {
        $this->prospectingProperty();
        $this->prospectingProperty();
        $this->prospectingProperty(['status' => 'active']);

        $this->actingAs($this->user);
        $request = Request::create('/corex/properties', 'GET', ['status' => Property::STATUS_PROSPECTING]);
        $request->setUserResolver(fn () => $this->user);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        $controller = app(PropertyController::class);
        $view = $controller->index($request);

        $stats = $view->getData()['stats'];
        $listCount = $view->getData()['properties']->total();

        $this->assertSame(2, $stats['prospecting'], 'tile count must reflect only this agency\'s prospecting rows');
        $this->assertSame($stats['prospecting'], $listCount, 'the tile count and the filtered list count must always agree');
    }

    // ──────────────────────── syndication guard ───────────────────────────────

    public function test_syndication_guard_refuses_a_prospecting_property(): void
    {
        $property = $this->prospectingProperty();

        $harness = new class {
            use EnforcesMarketingReadiness;
            public function run(Property $property): void
            {
                $this->enforceListingNotDraft($property, 'Property24');
            }
        };

        $this->expectException(DraftListingException::class);
        $harness->run($property);
    }

    public function test_syndication_guard_allows_an_active_property(): void
    {
        $property = $this->prospectingProperty(['status' => 'active']);

        $harness = new class {
            use EnforcesMarketingReadiness;
            public function run(Property $property): void
            {
                $this->enforceListingNotDraft($property, 'Property24');
            }
        };

        $harness->run($property); // must not throw
        $this->addToAssertionCount(1);
    }

    // ──────────────────────── helper ──────────────────────────────────────────

    private function prospectingProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agency->id,
            'branch_id'     => $this->branch->id,
            'agent_id'      => $this->user->id,
            'address'       => 'Test Street',
            'suburb'        => 'Margate',
            'property_type' => 'house',
            'beds'          => 3,
            'baths'         => 2,
            'garages'       => 1,
            'price'         => 1_000_000,
            'title'         => 'Test Property',
            'listing_type'  => 'sale',
            'status'        => Property::STATUS_PROSPECTING,
        ], $attrs));
    }
}
