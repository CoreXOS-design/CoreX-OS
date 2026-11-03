<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingClaim;
use App\Models\Prospecting\TrackedPropertyAgencyWork as Work;
use App\Models\Prospecting\TrackedPropertyAgencyWorkEvent;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\User;
use App\Services\Prospecting\AgencyWorkService;
use App\Services\Prospecting\OnMarketStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Lane B step 2 (property-identity.md section 12): each agency has its OWN work record on a property. Agency 1 setting a status, claiming or
 * owning the stock never shows in agency 2's record, and a work record cannot be reached through another agency's id.
 * Agency A = the fixture agency; agency B = a second agency holding the SAME advert and the same street address.
 */
final class AgencyWorkLayerTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Agency $b;
    private User $agentB;
    private AgencyWorkService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        $branchB = Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->agentB = $this->makeAgent($this->b, $branchB);
        $this->svc = new AgencyWorkService(new OnMarketStockService());
    }

    private function sameHouse(): array
    {
        $a = $this->tp(['street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        $b = $this->tp(['street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo'], $this->b);

        return [$a, $b];
    }

    public function test_a_status_set_by_agency_a_is_invisible_to_agency_b_and_each_agency_keeps_its_own_history(): void
    {
        [$a, $b] = $this->sameHouse();

        $this->svc->setStatus($this->agency->id, $a->id, 'not_selling', 'Owner says no for two years', $this->agent->id);
        $this->assertNull($this->svc->find($this->b->id, $b->id), 'agency B has no record and no status');

        $this->svc->setStatus($this->b->id, $b->id, 'interested', null, $this->agentB->id);

        $this->assertSame('not_selling', $this->svc->find($this->agency->id, $a->id)->prospecting_status);
        $this->assertSame('interested', $this->svc->find($this->b->id, $b->id)->prospecting_status);
        $this->assertSame(['Owner says no for two years'], $this->svc->history($this->agency->id, $a->id)->pluck('reason')->all());
        $this->assertSame(['interested'], $this->svc->history($this->b->id, $b->id)->pluck('to_value')->all());
        $this->assertSame(0, TrackedPropertyAgencyWorkEvent::withoutGlobalScopes()->where('agency_id', $this->b->id)->where('reason', 'Owner says no for two years')->count());
    }

    public function test_a_work_record_cannot_be_reached_or_made_through_another_agencys_tracked_property(): void
    {
        [$a] = $this->sameHouse();

        $this->expectException(\DomainException::class);
        $this->svc->setStatus($this->b->id, $a->id, 'not_selling', null, $this->agentB->id);
    }

    public function test_the_scope_hides_other_agencies_work_records_and_history_from_a_signed_in_user(): void
    {
        [$a, $b] = $this->sameHouse();
        $this->svc->setStatus($this->agency->id, $a->id, 'do_not_contact', 'Asked us to stop', $this->agent->id);
        $this->svc->setStatus($this->b->id, $b->id, 'contacted', null, $this->agentB->id);

        $this->actingAs($this->agentB);
        $this->assertSame(['contacted'], Work::query()->pluck('prospecting_status')->all());
        $this->assertSame(['contacted'], TrackedPropertyAgencyWorkEvent::query()->pluck('to_value')->all());
        $this->assertNull(Work::query()->where('tracked_property_id', $a->id)->first(), 'a direct lookup by the other agency\'s property finds nothing');
    }

    public function test_a_status_must_be_one_of_the_known_plain_statuses_and_can_be_cleared(): void
    {
        [$a] = $this->sameHouse();
        $this->svc->setStatus($this->agency->id, $a->id, 'mandate_elsewhere', 'Seen on a rival board', $this->agent->id);
        $w = $this->svc->setStatus($this->agency->id, $a->id, null, 'ignored', $this->agent->id);
        $this->assertNull($w->prospecting_status);
        $this->assertNull($w->prospecting_reason, 'clearing the status clears its reason');
        $this->assertCount(2, $this->svc->history($this->agency->id, $a->id));

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setStatus($this->agency->id, $a->id, 'banana', null, null);
    }

    public function test_setting_the_same_status_twice_writes_one_history_line(): void
    {
        [$a] = $this->sameHouse();
        $this->svc->setStatus($this->agency->id, $a->id, 'contacted', null, $this->agent->id);
        $this->svc->setStatus($this->agency->id, $a->id, 'contacted', null, $this->agent->id);
        $this->assertCount(1, $this->svc->history($this->agency->id, $a->id));
        $this->assertSame(1, Work::withoutGlobalScopes()->where('tracked_property_id', $a->id)->count(), 'one record per agency per property');
    }

    public function test_own_stock_is_flagged_for_the_agency_that_holds_the_property_and_not_for_the_other_agency(): void
    {
        [$a, $b] = $this->sameHouse();
        $ref = 'P24-117740465';
        $listingA = $this->listing(['portal_ref' => $ref], $a);
        $listingB = $this->listing(['portal_ref' => $ref], $b, $this->b);
        // agency A holds this advert as its own live stock
        $this->property($this->agent->id, ['p24_ref' => '117740465', 'status' => 'active']);
        OnMarketStockService::flushCache();

        $wa = $this->svc->syncOwnStock($this->agency->id, $a->id);
        $wb = $this->svc->syncOwnStock($this->b->id, $b->id);

        $this->assertTrue($wa->is_own_stock);
        $this->assertSame('advert', $wa->own_stock_source);
        $this->assertFalse((bool) $wb->is_own_stock, 'for agency B the same advert is another agency\'s stock');
        $this->assertNull($wb->own_stock_property_id);
        $this->assertSame(['own stock'], $this->svc->history($this->agency->id, $a->id)->pluck('to_value')->all());
        $this->assertSame([], $this->svc->history($this->b->id, $b->id)->all());
        $this->assertNotNull($listingA);
        $this->assertNotNull($listingB);
    }

    public function test_a_promoted_property_flags_own_stock_and_dropping_it_to_not_selling_clears_the_flag(): void
    {
        [$a] = $this->sameHouse();
        $propertyId = $this->property($this->agent->id, ['status' => 'active']);
        $a->forceFill(['promoted_to_property_id' => $propertyId])->save();

        $w = $this->svc->syncOwnStock($this->agency->id, $a->id);
        $this->assertTrue($w->is_own_stock);
        $this->assertSame($propertyId, (int) $w->own_stock_property_id);
        $this->assertSame('promoted', $w->own_stock_source);

        DB::table('properties')->where('id', $propertyId)->update(['status' => 'not_selling']);
        $w = $this->svc->syncOwnStock($this->agency->id, $a->id);
        $this->assertFalse((bool) $w->is_own_stock);
    }

    public function test_a_claim_shows_on_the_claiming_agencys_record_only(): void
    {
        [$a, $b] = $this->sameHouse();
        $listingA = $this->listing([], $a);
        $listingB = $this->listing([], $b, $this->b);

        ProspectingClaim::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingA, 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true,
        ]);

        $wa = $this->svc->syncClaim($this->agency->id, $a->id);
        $wb = $this->svc->syncClaim($this->b->id, $b->id);

        $this->assertSame($this->agent->id, (int) $wa->claimed_by_user_id);
        $this->assertNotNull($wa->claimed_at);
        $this->assertNull($wb->claimed_by_user_id, 'agency 1 claiming never changes agency 2\'s view');
        $this->assertNull($wb->claimed_at);
        $this->assertNotNull($listingB);

        // released claim -> cleared, with a history line
        ProspectingClaim::withoutGlobalScopes()->where('prospecting_listing_id', $listingA)->update(['released_at' => now(), 'is_active' => false]);
        $this->assertNull($this->svc->syncClaim($this->agency->id, $a->id)->claimed_by_user_id);
        $this->assertCount(2, $this->svc->history($this->agency->id, $a->id));
    }

    public function test_a_new_claim_and_a_promotion_update_the_record_without_anyone_running_the_backfill(): void
    {
        [$a, $b] = $this->sameHouse();
        $listingA = $this->listing([], $a);

        $claim = ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingA, 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true,
        ]);
        $this->assertSame($this->agent->id, (int) $this->svc->find($this->agency->id, $a->id)->claimed_by_user_id, 'a claim shows on the record at once');
        $this->assertNull($this->svc->find($this->b->id, $b->id));

        $claim->forceFill(['released_at' => now(), 'is_active' => false])->save();
        $this->assertNull($this->svc->find($this->agency->id, $a->id)->claimed_by_user_id, 'releasing the claim clears it');

        $propertyId = $this->property($this->agent->id, ['status' => 'active']);
        $a->forceFill(['promoted_to_property_id' => $propertyId])->save();
        $this->assertTrue((bool) $this->svc->find($this->agency->id, $a->id)->is_own_stock, 'promotion flags own stock at once');

        \App\Models\Property::withoutGlobalScopes()->whereKey($propertyId)->first()->forceFill(['status' => 'not_selling'])->save();
        $this->assertFalse((bool) $this->svc->find($this->agency->id, $a->id)->is_own_stock, 'a property that is no longer ours stops being flagged');
        $this->assertNull($this->svc->find($this->b->id, $b->id), 'none of it ever reaches agency B\'s record');
    }

    public function test_an_owner_row_carries_the_agency_of_its_tracked_property_and_is_scoped(): void
    {
        [$a, $b] = $this->sameHouse();
        $ownerA = TrackedPropertyOwner::create(['tracked_property_id' => $a->id, 'name' => 'Pieter Botha', 'id_number' => '7001015009087']);
        $ownerB = TrackedPropertyOwner::create(['tracked_property_id' => $b->id, 'name' => 'Someone Else', 'id_number' => '8001015009087']);

        $this->assertSame($this->agency->id, (int) $ownerA->agency_id);
        $this->assertSame($this->b->id, (int) $ownerB->agency_id);

        $this->actingAs($this->agentB);
        $this->assertSame(['Someone Else'], TrackedPropertyOwner::query()->pluck('name')->all(), 'the other agency\'s paid-for owner names are not readable');
    }

    public function test_the_backfill_fills_claims_own_stock_and_not_selling_for_each_agency_and_is_idempotent(): void
    {
        [$a, $b] = $this->sameHouse();
        $listingA = $this->listing([], $a);
        ProspectingClaim::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingA, 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true,
        ]);
        $held = $this->tp(['street_name' => 'Held Road']);
        $held->forceFill(['promoted_to_property_id' => $this->property($this->agent->id, ['status' => 'active'])])->save();
        $dead = $this->tp(['street_name' => 'Dead Road']);
        $dead->forceFill(['promoted_to_property_id' => $this->property($this->agent->id, ['status' => 'not_selling'])])->save();
        OnMarketStockService::flushCache();

        $before = Work::withoutGlobalScopes()->count();
        $beforeEvents = TrackedPropertyAgencyWorkEvent::withoutGlobalScopes()->count();
        $this->artisan('agency-work:backfill', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, Work::withoutGlobalScopes()->count(), 'a dry run writes nothing');
        $this->assertSame($beforeEvents, TrackedPropertyAgencyWorkEvent::withoutGlobalScopes()->count());

        $this->artisan('agency-work:backfill')->assertSuccessful();
        $count = Work::withoutGlobalScopes()->count();
        $events = TrackedPropertyAgencyWorkEvent::withoutGlobalScopes()->count();

        $this->assertSame($this->agent->id, (int) $this->svc->find($this->agency->id, $a->id)->claimed_by_user_id);
        $this->assertTrue((bool) $this->svc->find($this->agency->id, $held->id)->is_own_stock);
        $this->assertSame('not_selling', $this->svc->find($this->agency->id, $dead->id)->prospecting_status);
        $this->assertNull($this->svc->find($this->b->id, $b->id), 'agency B had nothing to fill');

        $this->artisan('agency-work:backfill')->assertSuccessful();
        $this->assertSame($count, Work::withoutGlobalScopes()->count());
        $this->assertSame($events, TrackedPropertyAgencyWorkEvent::withoutGlobalScopes()->count(), 're-running adds no history');
    }
}
