<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingClaim;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\AgencyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * property-identity.md section 15.3, C3: ONE prospecting status per agency per property. The property side (the Not selling button, any status
 * change) and the agency's work record on the matched Market Intelligence property tell the same story, in both directions, inside one agency.
 */
final class ProspectingStatusSyncTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Agency $b;
    private Branch $branchB;
    private User $admin;
    private AgencyWorkService $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        $this->branchB = Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->work = app(AgencyWorkService::class);
    }

    /** A property of the agency, linked to a tracked property the way the door links it (properties.tracked_property_id), not promoted. */
    private function propertyOn(TrackedProperty $tp, string $status = 'prospecting', ?Agency $agency = null, ?Branch $branch = null): Property
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;
        $p = new Property();
        foreach (['external_id' => 'P-' . Str::random(8), 'title' => 'T', 'address' => '12 Bairn Street', 'suburb' => 'Uvongo Beach', 'price' => 1_500_000,
                  'property_type' => 'house', 'beds' => 3, 'status' => $status, 'is_demo' => false, 'agency_id' => $agency->id,
                  'branch_id' => $branch->id, 'agent_id' => $this->agent->id] as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();
        DB::table('properties')->where('id', $p->id)->update(['tracked_property_id' => $tp->id]);

        return $p->fresh();
    }

    private function tpPair(): array
    {
        $a = $this->tp(['street_number' => '12', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach']);
        $b = $this->tp(['street_number' => '12', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach'], $this->b);

        return [$a, $b];
    }

    private function workStatus(int $agencyId, int $tpId): ?string
    {
        return $this->work->find($agencyId, $tpId)?->prospecting_status;
    }

    public function test_the_not_selling_button_sets_the_agencys_status_with_the_reason_even_when_the_property_was_never_promoted(): void
    {
        [$tp] = $this->tpPair();
        $p = $this->propertyOn($tp);

        $this->actingAs($this->admin)->post(route('corex.properties.mark-not-selling', $p->id), ['reason' => 'Owner says two years'])->assertRedirect();

        $this->assertSame('not_selling', $this->workStatus($this->agency->id, $tp->id));
        $w = $this->work->find($this->agency->id, $tp->id);
        $this->assertSame('Owner says two years', $w->prospecting_reason);
        $this->assertSame($this->admin->id, (int) $w->status_set_by_user_id);
        $this->assertCount(1, $this->work->history($this->agency->id, $tp->id)->where('kind', 'status'), 'one history line, not two');
    }

    public function test_the_promoted_link_is_found_too(): void
    {
        [$tp] = $this->tpPair();
        $p = $this->propertyOn($tp);
        DB::table('properties')->where('id', $p->id)->update(['tracked_property_id' => null]);
        DB::table('tracked_properties')->where('id', $tp->id)->update(['promoted_to_property_id' => $p->id]);

        $this->actingAs($this->admin)->post(route('corex.properties.mark-not-selling', $p->id), [])->assertRedirect();

        $this->assertSame('not_selling', $this->workStatus($this->agency->id, $tp->id));
    }

    public function test_any_path_that_makes_a_property_not_selling_tells_the_work_record(): void
    {
        [$tp] = $this->tpPair();
        $p = $this->propertyOn($tp);

        $p->status = 'not_selling';
        $p->save();

        $this->assertSame('not_selling', $this->workStatus($this->agency->id, $tp->id));
    }

    public function test_leaving_not_selling_clears_a_not_selling_status_but_leaves_other_statuses_alone(): void
    {
        [$tp, $tp2] = [$this->tpPair()[0], $this->tp(['street_number' => '14', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach'])];
        $p = $this->propertyOn($tp, 'not_selling');
        $this->work->setStatus($this->agency->id, $tp->id, 'not_selling', null, $this->admin->id);
        $q = $this->propertyOn($tp2, 'not_selling');
        $this->work->setStatus($this->agency->id, $tp2->id, 'do_not_contact', 'Asked us to stop', $this->admin->id);

        $p->status = 'prospecting';
        $p->save();
        $q->status = 'prospecting';
        $q->save();

        $this->assertNull($this->workStatus($this->agency->id, $tp->id), 'Not selling is cleared');
        $this->assertSame('do_not_contact', $this->workStatus($this->agency->id, $tp2->id), 'a Do not contact is never cleared by a property moving');
        $this->assertSame('The property is no longer marked Not selling', $this->work->history($this->agency->id, $tp->id)->first()->reason);
    }

    public function test_a_property_that_becomes_ours_clears_not_selling_and_mandate_elsewhere_only(): void
    {
        [$tp, $tp2] = [$this->tpPair()[0], $this->tp(['street_number' => '14', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach'])];
        $p = $this->propertyOn($tp);
        $this->work->setStatus($this->agency->id, $tp->id, 'mandate_elsewhere', 'Rival board', $this->admin->id);
        $q = $this->propertyOn($tp2);
        $this->work->setStatus($this->agency->id, $tp2->id, 'interested', null, $this->admin->id);

        $p->status = 'draft';   // "mandate won"
        $p->save();
        $q->status = 'draft';
        $q->save();

        $this->assertNull($this->workStatus($this->agency->id, $tp->id));
        $this->assertSame('Now listed with us', $this->work->history($this->agency->id, $tp->id)->first()->reason);
        $this->assertSame('interested', $this->workStatus($this->agency->id, $tp2->id));
    }

    public function test_the_mic_form_marking_owner_not_selling_marks_the_property_not_selling_and_closes_its_claims(): void
    {
        [$tp] = $this->tpPair();
        $p = $this->propertyOn($tp);
        $listing = $this->listing([], $tp);
        ProspectingClaim::withoutEvents(fn () => ProspectingClaim::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true,
        ]));

        $this->actingAs($this->admin)->post(route('corex.tracked-properties.prospecting-status', $tp->id), ['prospecting_status' => 'not_selling', 'prospecting_reason' => 'Said no'])
            ->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'now marked Not selling'));

        $this->assertSame('not_selling', $p->fresh()->status);
        $this->assertSame('not_selling', $this->workStatus($this->agency->id, $tp->id));
        $this->assertSame('Said no', $this->work->find($this->agency->id, $tp->id)->prospecting_reason, 'the reason typed on the form stands');
        $this->assertCount(1, $this->work->history($this->agency->id, $tp->id)->where('kind', 'status'));
        $claim = ProspectingClaim::withoutGlobalScopes()->where('prospecting_listing_id', $listing)->first();
        $this->assertFalse((bool) $claim->is_active, 'the claim fell off My Claims, as the Not selling button does');
        $this->assertSame('not_selling', $claim->release_reason);

        // and back: clearing the status puts the property back in Prospecting
        $this->actingAs($this->admin)->post(route('corex.tracked-properties.prospecting-status', $tp->id), ['prospecting_status' => ''])
            ->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'back in Prospecting'));
        $this->assertSame('prospecting', $p->fresh()->status);
        $this->assertNull($this->workStatus($this->agency->id, $tp->id));
    }

    public function test_the_other_statuses_and_live_listings_are_left_alone_by_the_mic_form(): void
    {
        [$tp, $tp2] = [$this->tpPair()[0], $this->tp(['street_number' => '14', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach'])];
        $p = $this->propertyOn($tp);
        $live = $this->propertyOn($tp2, 'active');

        $this->actingAs($this->admin)->post(route('corex.tracked-properties.prospecting-status', $tp->id), ['prospecting_status' => 'mandate_elsewhere'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.tracked-properties.prospecting-status', $tp2->id), ['prospecting_status' => 'not_selling'])->assertRedirect();

        $this->assertSame('prospecting', $p->fresh()->status, 'no property equivalent for Mandate with another agency');
        $this->assertSame('active', $live->fresh()->status, 'a live listing of ours is never switched off by a note');
        $this->assertSame('not_selling', $this->workStatus($this->agency->id, $tp2->id), 'the note itself is still recorded');
    }

    public function test_agency_privacy_nothing_reaches_the_other_agencys_copy(): void
    {
        [$tpA, $tpB] = $this->tpPair();
        $pA = $this->propertyOn($tpA);
        $pB = $this->propertyOn($tpB, 'prospecting', $this->b, $this->branchB);

        $this->actingAs($this->admin)->post(route('corex.properties.mark-not-selling', $pA->id), ['reason' => 'private reason'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.tracked-properties.prospecting-status', $tpA->id), ['prospecting_status' => 'not_selling'])->assertRedirect();

        $this->assertSame('prospecting', $pB->fresh()->status, "agency B's property is untouched");
        $this->assertNull($this->work->find($this->b->id, $tpB->id), "agency B has no work record made");
        $this->assertSame(0, DB::table('tracked_property_agency_work_events')->where('agency_id', $this->b->id)->count());
        $this->assertSame(0, DB::table('tracked_property_agency_work')->where('agency_id', $this->b->id)->where('prospecting_reason', 'private reason')->count());
    }

    public function test_a_property_with_no_matched_mic_property_changes_status_without_error(): void
    {
        $tp = $this->tp(['street_number' => '99', 'street_name' => 'Nowhere Rd']);
        $p = $this->propertyOn($tp);
        DB::table('properties')->where('id', $p->id)->update(['tracked_property_id' => null]);

        $this->actingAs($this->admin)->post(route('corex.properties.mark-not-selling', $p->id), [])->assertRedirect();

        $this->assertSame('not_selling', $p->fresh()->status);
        $this->assertNull($this->work->find($this->agency->id, $tp->id));
    }
}
