<?php

namespace Tests\Feature\Syndication;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression 1: PropertyObserver::saved() must use getChanges(), not getDirty(),
 * to detect a status change on a P24-syndicated property. saved()'s first call
 * (onPropertyUpdated → updateQuietly) runs a nested save that syncs original, so
 * getDirty() is empty by the time the P24 block runs — which previously made all
 * P24 status/field auto-sync dead code (sold/withdrawn never reached P24).
 * Audit: .ai/audits/mandate-expiry-desyndication-2026-06-20.md
 *
 * Regression 2: a 'Sold' push must NOT mark the row 'deactivated'. P24 keeps sold
 * stock ON the portal, but 'deactivated' is what every delist guard reads as
 * "already removed" — so the listing stayed live forever while CoreX reported it
 * off. Only Withdrawn/Expired/Cancelled remove the listing.
 * Audit: .ai/audits/p24-sold-not-delisted-2026-07-10.md (property #2142)
 */
class Property24ObserverStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeSyndicatedProperty(string $syndicationStatus = 'active'): Property
    {
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
        ]);

        $p->forceFill([
            'p24_syndication_enabled' => true,
            'p24_syndication_status'  => $syndicationStatus,
            'p24_ref'                 => '99887766',
        ])->save();

        return $p;
    }

    public function test_marking_a_syndicated_property_sold_pushes_status_to_p24(): void
    {
        Queue::fake();                 // isolate from MatchPropertyJob etc.
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeSyndicatedProperty();

        $p->update(['status' => 'sold']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '99887766')
            && str_contains($request->url(), 'listingStatus=Sold'));
    }

    /**
     * The core of the #2142 bug: Sold leaves the listing ON the portal, so the row
     * must stay delistable. 'deactivated' would make every delist path skip it.
     */
    public function test_sold_push_does_not_mark_the_listing_off_the_portal(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeSyndicatedProperty();
        $p->update(['status' => 'sold']);

        $fresh = $p->fresh();
        $this->assertSame('sold', $fresh->p24_syndication_status);
        $this->assertNotSame(Property::PORTAL_OFF_STATUS, $fresh->p24_syndication_status);
        $this->assertTrue($fresh->mayBeLiveOnP24(), 'A sold listing is still on P24 and must remain delistable.');
    }

    /** Withdrawn genuinely removes the listing — that one may mark it off-portal. */
    public function test_withdrawn_push_marks_the_listing_off_the_portal(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeSyndicatedProperty();
        $p->update(['status' => 'withdrawn']);

        $fresh = $p->fresh();
        $this->assertSame(Property::PORTAL_OFF_STATUS, $fresh->p24_syndication_status);
        $this->assertFalse($fresh->mayBeLiveOnP24());
    }

    /**
     * A property already marked sold must still be dispatched to the desync job
     * when it later goes off-market — the old $onPortal check ignored P24.
     */
    public function test_off_market_transition_dispatches_desync_for_a_sold_but_listed_property(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $p = $this->makeSyndicatedProperty('sold');
        $p->forceFill(['p24_syndication_enabled' => false])->saveQuietly();

        $p->update(['status' => 'withdrawn']);

        Queue::assertPushed(DesyndicatePropertyFromPortalsJob::class);
    }
}
