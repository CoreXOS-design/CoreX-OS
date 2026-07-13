<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-252 — the save→push loop.
 *
 * A compliant, on-market (Active) listing has live portal copies riding on it.
 * Saving it is the instant those copies go stale, and nothing used to connect the
 * two: the agent dropped the price, saw "Property updated.", and left Property24 /
 * Private Property / the company website advertising yesterday's number.
 *
 * So a qualifying save now flashes `open_syndication`, and the property page opens
 * the syndication panel on it — with "Refresh all portals" in front of the agent.
 *
 * Both halves of the rule are load-bearing and are proven here:
 *   compliant + Active            → prompt
 *   compliant + NOT Active (sold) → silent (never nag on a sold listing)
 *   NOT compliant + Active        → silent (nothing it may publish)
 *   NOT compliant + NOT Active    → silent
 *
 * Spec: .ai/specs/syndication-refresh-all.md
 */
final class SyndicationRefreshPromptTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        // PropertyObserver dispatches portal jobs on a status change (e.g. the
        // off-market desyndicate). This test is about the prompt, not the push.
        Queue::fake();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Syn ' . Str::random(6), 'slug' => 'syn-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin',
        ]);

        // Real KZN South Coast stock, not "Test / Test / 0000000000".
        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'SYN-' . Str::random(8), 'title' => '12 Marine Drive, Uvongo',
            'price' => 2_450_000, 'status' => 'active', 'is_demo' => false,
            'listing_type' => 'sale',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
            'beds' => 3, 'baths' => 2, 'garages' => 2,
            'compliance_snapshot_at' => null,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // update() refuses to save a contactless property.
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_by_user_id' => $this->user->id,
            'first_name' => 'Thandeka', 'last_name' => 'Mkhize', 'phone' => '083 412 8890',
        ]);
        DB::table('contact_property')->insert([
            'property_id' => $this->propertyId, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Mark the listing compliant — the timestamp IS the flag (there is no boolean). */
    private function markCompliant(): void
    {
        DB::table('properties')->where('id', $this->propertyId)
            ->update(['compliance_snapshot_at' => now()]);
    }

    /**
     * Put the listing LIVE on Property24 — a ref, an active status, and the switch on.
     * This is what Property::portalLinks() reads to report a live portal.
     *
     * Deliberately NOT part of markCompliant(): pressing Go Live stamps the compliance
     * snapshot and enables NO portal, so "compliant but on nothing" is a real, common
     * state — and it must not prompt. Keeping the two separate is what lets the tests
     * below tell those cases apart.
     */
    private function makeLiveOnP24(): void
    {
        DB::table('properties')->where('id', $this->propertyId)->update([
            'p24_ref'                 => '112233445',
            'p24_syndication_status'  => 'active',
            'p24_syndication_enabled' => true,
        ]);
    }

    /** The payload the edit form actually posts. `status` is a required field on it. */
    private function save(string $status): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->put(
            route('corex.properties.update', $this->propertyId),
            [
                'title'    => '12 Marine Drive, Uvongo',
                'price'    => 2_395_000,   // the price drop that makes the portals stale
                'status'   => $status,
                'suburb'   => 'Uvongo',
                'city'     => 'Margate',
                'province' => 'KwaZulu-Natal',
                'beds'     => 3,
                'baths'    => 2,
                'garages'  => 2,
                'agent_id' => $this->user->id,
            ]
        );
    }

    public function test_saving_a_compliant_active_listing_that_is_live_on_a_portal_prompts(): void
    {
        $this->markCompliant();
        $this->makeLiveOnP24();

        $resp = $this->save('active');

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect(route('corex.properties.show', $this->propertyId));
        // The flash carries the SAVED property's id, never a bare `true`.
        $resp->assertSessionHas('open_syndication', $this->propertyId);

        $this->assertEquals(
            2_395_000,
            DB::table('properties')->where('id', $this->propertyId)->value('price'),
            'the edit itself still persisted — the prompt rides along, it does not replace the save'
        );
    }

    public function test_a_compliant_active_listing_on_no_portal_is_never_prompted(): void
    {
        // THE REGRESSION THIS GUARDS: pressing Go Live stamps compliance_snapshot_at and
        // enables NO portal, so compliant + Active + on-nothing is the DEFAULT state of
        // every freshly-compliant listing. Prompting here threw a full-screen modal over
        // every single save whose only new control — Refresh all portals — is correctly
        // hidden inside it, because there is nothing to refresh. An empty, unavoidable,
        // blocking overlay on every save. The prompt must only fire when it has something
        // to say.
        $this->markCompliant();   // deliberately NOT live on any portal

        $resp = $this->save('active');

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionMissing('open_syndication');
    }

    public function test_a_compliant_listing_that_is_no_longer_active_is_never_nagged(): void
    {
        $this->markCompliant();
        $this->makeLiveOnP24();   // live on a portal, but sold — still must not nag

        $resp = $this->save('sold');

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionMissing('open_syndication');
    }

    public function test_a_non_compliant_listing_is_not_prompted_however_active_it_is(): void
    {
        // No compliance snapshot — the listing has nothing it is permitted to publish.
        $this->makeLiveOnP24();

        $resp = $this->save('active');

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionMissing('open_syndication');
    }

    public function test_a_listing_that_is_neither_compliant_nor_active_is_not_prompted(): void
    {
        $resp = $this->save('withdrawn');

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionMissing('open_syndication');
    }

    public function test_a_switched_off_portal_does_not_count_as_live_even_with_a_stale_active_status(): void
    {
        // Turning a portal off leaves *_syndication_status at its last value, so a
        // disabled listing routinely still reads 'active'. Trusting the status alone
        // would prompt on a listing that reaches nobody.
        $this->markCompliant();
        $this->makeLiveOnP24();
        DB::table('properties')->where('id', $this->propertyId)
            ->update(['p24_syndication_enabled' => false]);   // the truth

        $resp = $this->save('active');

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionMissing('open_syndication');
    }

    public function test_the_status_test_ignores_casing(): void
    {
        // Agencies configure their own status list, so the stored value can arrive
        // title-cased ("Active"). The rule must not turn on a capital letter.
        $this->markCompliant();
        $this->makeLiveOnP24();

        $resp = $this->save('Active');

        $resp->assertSessionHasNoErrors();
        $resp->assertSessionHas('open_syndication', $this->propertyId);
    }

    public function test_the_property_page_opens_the_panel_when_the_save_flashed_the_prompt(): void
    {
        $this->markCompliant();

        $resp = $this->actingAs($this->user)
            ->withSession(['open_syndication' => $this->propertyId])
            ->get(route('corex.properties.show', $this->propertyId));

        $resp->assertOk();
        $resp->assertSee('synOpen: true', false);
    }

    public function test_a_prompt_flashed_for_another_listing_never_opens_this_ones_panel(): void
    {
        // The flash survives one request. If a redirect is never followed (Back into a
        // cached POST-redirect, a prefetch), a bare truthy flash would still be sitting
        // there and would pop the panel on whatever property the agent opened next.
        // The flash carries an id, and it has to match.
        $this->markCompliant();

        $resp = $this->actingAs($this->user)
            ->withSession(['open_syndication' => $this->propertyId + 999])
            ->get(route('corex.properties.show', $this->propertyId));

        $resp->assertOk();
        $resp->assertSee('synOpen: false', false);
    }

    public function test_the_property_page_stays_closed_on_an_ordinary_visit(): void
    {
        $this->markCompliant();

        $resp = $this->actingAs($this->user)->get(route('corex.properties.show', $this->propertyId));

        $resp->assertOk();
        $resp->assertSee('synOpen: false', false);
        $resp->assertDontSee('synOpen: true', false);
    }

    public function test_the_panel_renders_the_refresh_all_control(): void
    {
        $this->markCompliant();

        $resp = $this->actingAs($this->user)->get(route('corex.properties.show', $this->propertyId));

        $resp->assertOk();
        // The button, its Alpine component, and the bus the three portal panels
        // answer on. Rendered once, from the one shared panel partial.
        $resp->assertSee('Refresh all portals');
        $resp->assertSee('corex-syndication-refresh-all', false);
        $resp->assertSee('corex-syndication-census-request', false);
        $resp->assertSee('onSyndicationRefreshAll($event)', false);
        $resp->assertSee('onSyndicationCensusRequest($event)', false);
    }

    public function test_the_bus_is_scoped_to_one_property_so_it_can_never_push_the_wrong_listing(): void
    {
        // The refresh bus rides on `window`, and the Properties index reuses ONE modal
        // for every listing. If a stale panel could ever hear another property's press,
        // the failure mode is a real, public, wrong-price advert on Property24 / Private
        // Property. So the id is stamped on the component and checked on both ends —
        // correctness must not rest on Alpine's MutationObserver destroying the old
        // panel in time. This test exists to stop anyone quietly deleting that guard.
        $this->markCompliant();

        $resp = $this->actingAs($this->user)->get(route('corex.properties.show', $this->propertyId));

        $resp->assertOk();
        // The dispatcher is bound to THIS property, not to "whatever is on screen".
        $resp->assertSee('syndicationRefreshAll(' . $this->propertyId . ')', false);
        // Both ends drop a message that isn't theirs.
        $resp->assertSee('isSameProperty(event)', false);
        $resp->assertSee('propertyId: this.propertyId', false);
        // And the un-scoped form is gone for good.
        $resp->assertDontSee('syndicationRefreshAll()', false);
    }
}
