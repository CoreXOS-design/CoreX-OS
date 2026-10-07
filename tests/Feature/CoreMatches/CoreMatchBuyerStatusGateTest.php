<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Mail\Matches\MatchDigestMail;
use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchNotification;
use App\Models\CoreMatchBuyerStateAuditEntry;
use App\Models\Property;
use App\Models\User;
use App\Services\BuyerStateService;
use App\Services\Matching\CoreMatchBuyerGate;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Johan, 2026-10-07 — a buyer whose Buyer Pipeline status is Won or Lost has no Core
 * Matches ANYWHERE; the excluded statuses are an agency setting (default Won + Lost);
 * the buyer's status shows as a chip on each board row; and the board offers the same
 * contact-notes actions as the contact screen ("Note only" / "Contacted and note").
 */
final class CoreMatchBuyerStatusGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Gate Test Agency', 'slug' => 'gate-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = $this->makeUser('agent');
    }

    private function makeUser(string $role, ?Agency $agency = null): User
    {
        $agency ??= $this->agency;

        return User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function buyer(string $first, ?string $state, string $listingType = 'sale', ?User $owner = null): ContactMatch
    {
        $owner ??= $this->agent;
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $owner->id,
            'first_name' => $first, 'last_name' => 'Gate', 'is_buyer' => $state !== null,
            'buyer_state' => $state,
        ]);

        $match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $owner->id, 'agent_id' => $owner->id,
            'name' => 'Wishlist', 'listing_type' => $listingType, 'status' => ContactMatch::STATUS_ACTIVE,
            'price_min' => 1_000_000, 'price_max' => 2_000_000, 'beds_min' => 2, 'property_types' => ['House'],
        ]);

        // Creating a wishlist auto-lands a state-less buyer on the pipeline as 'new' (AT-72);
        // a buyer with genuinely NO pipeline record is put back after the fact.
        if ($state === null) {
            Contact::withoutGlobalScopes()->whereKey($contact->id)->update(['buyer_state' => null, 'is_buyer' => 0]);
        }

        return $match;
    }

    private function setExcluded(?array $states): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update(['core_matches_excluded_buyer_states' => $states]);
        AgencyContactSettings::clearCoreMatchExcludedBuyerStatesCache();
    }

    private function board(?User $viewer = null, array $query = ['scope' => 'own'])
    {
        return $this->actingAs($viewer ?? $this->agent)->get(route('corex.core-matches.index', $query));
    }

    private function listing(): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'title' => 'Gate listing',
            'status' => 'active', 'listing_type' => 'sale', 'price' => 1_500_000, 'beds' => 3,
            'garages' => 2, 'property_type' => 'House',
        ]);
    }

    // ---- The rule: default Won + Lost, per setting -------------------------

    public function test_default_excludes_won_and_lost_and_keeps_every_other_state_including_none(): void
    {
        $this->buyer('Newbie', 'new');
        $this->buyer('Warmie', 'warm');
        $this->buyer('Coldie', 'cold');
        $this->buyer('Lostie', 'lost');
        $this->buyer('Winnie', 'won');
        $this->buyer('Nostate', null);

        $resp = $this->board()->assertOk();

        foreach (['Newbie', 'Warmie', 'Coldie', 'Nostate'] as $shown) {
            $resp->assertSee($shown, false);
        }
        foreach (['Lostie', 'Winnie'] as $hidden) {
            $resp->assertDontSee($hidden, false);
        }
    }

    public function test_the_excluded_statuses_are_an_agency_setting_not_a_constant(): void
    {
        $this->buyer('Coldie', 'cold');
        $this->buyer('Lostie', 'lost');
        $this->setExcluded(['cold']);

        $this->board()->assertOk()->assertDontSee('Coldie', false)->assertSee('Lostie', false);
    }

    public function test_an_agency_can_choose_to_exclude_no_one(): void
    {
        $this->buyer('Lostie', 'lost');
        $this->buyer('Winnie', 'won');
        $this->setExcluded([]);

        $this->board()->assertOk()->assertSee('Lostie', false)->assertSee('Winnie', false);
    }

    public function test_a_null_setting_resolves_to_the_code_default(): void
    {
        $this->assertSame(['won', 'lost'], AgencyContactSettings::forAgencyReadOnly($this->agency->id)->coreMatchesExcludedBuyerStates());
    }

    public function test_moving_a_buyer_back_to_an_active_status_returns_their_matches(): void
    {
        $match = $this->buyer('Boomerang', 'warm');
        $svc = app(BuyerStateService::class);

        $svc->transitionTo($match->contact, 'lost', 'manual_override', $this->agent->id);
        $this->board()->assertDontSee('Boomerang', false);

        $svc->transitionTo($match->contact->fresh(), 'warm', 'manual_override', $this->agent->id);
        $this->board()->assertSee('Boomerang', false);

        $svc->transitionTo($match->contact->fresh(), BuyerStateService::WON, 'manual_override', $this->agent->id);
        $this->board()->assertDontSee('Boomerang', false);
    }

    public function test_the_rule_holds_for_rentals_and_for_the_agency_scope(): void
    {
        $manager = $this->makeUser('principal');
        $this->buyer('Renter', 'warm', 'rental');
        $this->buyer('LostRenter', 'lost', 'rental');

        $this->actingAs($manager)->get(route('corex.rentals.core-matches.all', ['scope' => 'agency']))
            ->assertOk()->assertSee('Renter', false)->assertDontSee('LostRenter', false);
        $this->actingAs($manager)->get(route('corex.core-matches.all', ['scope' => 'agency', 'listing_type' => 'rental']))
            ->assertOk()->assertDontSee('LostRenter', false);
    }

    // ---- The other Core Matches surfaces use the same rule ------------------

    public function test_the_property_page_tab_and_new_listing_alerts_skip_excluded_buyers(): void
    {
        $this->buyer('Activebuyer', 'warm');
        $lost = $this->buyer('Lostbuyer', 'lost');
        $property = $this->listing();
        $ms = app(MatchingService::class);

        $this->assertSame(['Activebuyer'], $ms->matchesForProperty($property)->map(fn ($m) => $m->contact->first_name)->all());
        $this->assertNotContains($lost->contact_id, $ms->candidatesForProperty($property)->pluck('contact_id')->all());

        // setting drives it there too
        $this->setExcluded([]);
        $this->assertContains($lost->contact_id, $ms->matchesForProperty($property)->pluck('contact_id')->all());
    }

    public function test_the_daily_digest_skips_a_buyer_marked_lost_after_the_alert_was_queued(): void
    {
        Mail::fake();
        $property = $this->listing();
        $live = $this->buyer('Livebuyer', 'warm');
        $lost = $this->buyer('Lostbuyer', 'lost');
        foreach ([$live, $lost] as $m) {
            ContactMatchNotification::create([
                'agency_id' => $this->agency->id, 'contact_match_id' => $m->id, 'property_id' => $property->id,
                'score' => 80, 'notified_user_id' => $this->agent->id,
            ]);
        }

        $this->artisan('corex:matches:send-digests')->assertExitCode(0);

        Mail::assertSent(MatchDigestMail::class, function (MatchDigestMail $mail) use ($live, $lost) {
            $ids = collect($mail->groups)->pluck('contact_id');

            return $ids->contains($live->contact_id) && ! $ids->contains($lost->contact_id);
        });
    }

    public function test_the_mobile_list_skips_excluded_buyers(): void
    {
        $this->buyer('Mobilelive', 'warm');
        $this->buyer('Mobilelost', 'lost');
        Sanctum::actingAs($this->agent);

        $names = collect($this->getJson(route('v1.mobile.core-matches.index'))->assertOk()->json('groups'))
            ->pluck('contact.full_name')->all();

        $this->assertContains('Mobilelive Gate', $names);
        $this->assertNotContains('Mobilelost Gate', $names);
    }

    public function test_the_pipeline_match_count_filter_drops_excluded_buyers_only(): void
    {
        $live = $this->buyer('A', 'warm')->contact_id;
        $lost = $this->buyer('B', 'lost')->contact_id;
        $won  = $this->buyer('C', 'won')->contact_id;
        $none = $this->buyer('D', null)->contact_id;

        $this->assertEqualsCanonicalizing([$live, $none], CoreMatchBuyerGate::filterContactIds([$live, $lost, $won, $none], $this->agency->id));
    }

    // ---- Status chip --------------------------------------------------------

    public function test_the_status_chip_shows_the_pipeline_label_and_is_absent_without_a_pipeline_record(): void
    {
        $this->buyer('Warmchip', 'warm');
        $this->buyer('Nochip', null);
        $this->setExcluded([]);
        $this->buyer('Lostchip', 'lost');

        $html = $this->board()->assertOk()->getContent();

        $this->assertStringContainsString('data-buyer-state="warm"', $html);
        $this->assertStringContainsString('data-buyer-state="lost"', $html);
        // exactly two chips for three rows: the buyer with no pipeline record has none
        $this->assertSame(2, substr_count($html, 'data-buyer-state='));
        $this->assertStringContainsString('ds-badge-danger', $html);
    }

    // ---- Notes control: same endpoint, same effects as the contact screen -----

    /**
     * "Note only" moves NOTHING about contact — neither last_contacted_at nor the explicit
     * contacted_marked_at (Johan, 2026-10-07 — supersedes the earlier "any note resets the clock").
     * Only "Contacted and note" does (next test).
     */
    public function test_note_only_saves_the_note_but_does_not_record_the_explicit_contacted_mark(): void
    {
        $contact = $this->buyer('Noteonly', 'warm')->contact;

        $this->actingAs($this->agent)->from(route('corex.core-matches.index', ['scope' => 'own']))
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Left a voicemail', 'redirect_to' => 'back'])
            ->assertRedirect(route('corex.core-matches.index', ['scope' => 'own']));

        $this->assertDatabaseHas('contact_notes', ['contact_id' => $contact->id, 'body' => 'Left a voicemail', 'user_id' => $this->agent->id]);
        $this->assertNull($contact->fresh()->contacted_marked_at);
        $this->assertNull($contact->fresh()->last_contacted_at);
    }

    public function test_contacted_and_note_saves_the_note_and_updates_last_contacted(): void
    {
        $contact = $this->buyer('Contactednote', 'warm')->contact;

        $this->actingAs($this->agent)->from(route('corex.core-matches.index'))
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Spoke, wants a viewing', 'mark_contacted' => 1, 'redirect_to' => 'back'])
            ->assertRedirect(route('corex.core-matches.index'));

        $this->assertDatabaseHas('contact_notes', ['contact_id' => $contact->id, 'body' => 'Spoke, wants a viewing']);
        $this->assertNotNull($contact->fresh()->last_contacted_at);
        $this->assertNotNull($contact->fresh()->contacted_marked_at);
        // …and the board shows what the contact screen would
        $this->board()->assertSee('Contacted', false)->assertSee('1 note', false);
    }

    public function test_the_board_offers_the_note_control_with_both_actions(): void
    {
        $this->buyer('Hasnote', 'warm');

        $this->board()->assertOk()
            ->assertSee('+ Note', false)
            ->assertSee('Note only', false)
            ->assertSee('Contacted and note', false);
    }

    public function test_another_agencys_contact_cannot_be_noted_from_here(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Other']);
        $contact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Foreign', 'last_name' => 'Buyer',
            'created_by_user_id' => $this->makeUser('agent', $otherAgency)->id,
        ]);

        $this->actingAs($this->agent)
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'x', 'redirect_to' => 'back'])
            ->assertNotFound();
        $this->assertDatabaseMissing('contact_notes', ['body' => 'x']);
    }

    // ---- Setting save + audit + wizard saver ------------------------------------

    public function test_saving_the_setting_is_audited_only_when_it_changes(): void
    {
        $this->assertTrue(CoreMatchBuyerGate::saveExcludedStates($this->agency->id, ['cold', 'lost', 'bogus'], $this->agent->id));
        $this->assertSame(['cold', 'lost'], AgencyContactSettings::forAgencyReadOnly($this->agency->id)->coreMatchesExcludedBuyerStates());

        $entry = CoreMatchBuyerStateAuditEntry::withoutGlobalScopes()->where('agency_id', $this->agency->id)->latest('id')->first();
        $this->assertSame(['won', 'lost'], $entry->old_values);
        $this->assertSame(['cold', 'lost'], $entry->new_values);
        $this->assertSame($this->agent->id, $entry->changed_by_user_id);

        $this->assertFalse(CoreMatchBuyerGate::saveExcludedStates($this->agency->id, ['cold', 'lost'], $this->agent->id));
        $this->assertSame(1, CoreMatchBuyerStateAuditEntry::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    public function test_the_settings_page_saver_saves_the_choice_and_a_post_without_the_marker_never_wipes_it(): void
    {
        $admin = $this->makeUser('principal');
        $this->setExcluded(['cold']);

        // Post that never rendered the group (no marker): saved value untouched.
        $this->actingAs($admin)->put(route('command-center.settings.core-matches.update'), [
            'core_matches_working_window_days' => 7,
            'core_matches_allowed_statuses'    => ['active'],
        ])->assertRedirect();
        $this->assertSame(['cold'], AgencyContactSettings::forAgencyReadOnly($this->agency->id)->coreMatchesExcludedBuyerStates());

        // Post with the marker and nothing ticked = a real "exclude no one".
        AgencyContactSettings::clearCoreMatchExcludedBuyerStatesCache();
        $this->actingAs($admin)->put(route('command-center.settings.core-matches.update'), [
            'core_matches_working_window_days' => 7,
            'core_matches_allowed_statuses'    => ['active'],
            'core_matches_excluded_buyer_states_present' => 1,
        ])->assertRedirect();
        $this->assertSame([], AgencyContactSettings::forAgencyReadOnly($this->agency->id)->coreMatchesExcludedBuyerStates());
    }

    public function test_the_setup_wizard_row_exists_and_saves_through_the_narrow_saver(): void
    {
        $control = collect(config('agency-onboarding-copy.matches.controls'))->firstWhere('key', 'core_matches_excluded_buyer_states');
        $this->assertNotNull($control, 'wizard Core Matches step must carry the setting');
        $this->assertNotEmpty($control['explain']);
        $this->assertNotEmpty($control['affects']);
        $this->assertContains(
            'updateCoreMatchesExcludedBuyerStates',
            collect(config('agency-onboarding-copy.matches.savers'))->pluck('method')->all()
        );

        $admin = $this->makeUser('principal');
        $this->actingAs($admin);
        $saver = app(\App\Http\Controllers\CommandCenter\ContactGovernanceController::class);

        // marker absent → untouched
        $saver->updateCoreMatchesExcludedBuyerStates(\Illuminate\Http\Request::create('/x', 'POST', ['core_matches_excluded_buyer_states' => ['cold']]));
        $this->assertSame(['won', 'lost'], AgencyContactSettings::forAgencyReadOnly($this->agency->id)->coreMatchesExcludedBuyerStates());

        // marker present → saved
        $saver->updateCoreMatchesExcludedBuyerStates(\Illuminate\Http\Request::create('/x', 'POST', [
            'core_matches_excluded_buyer_states_present' => 1, 'core_matches_excluded_buyer_states' => ['lost'],
        ]));
        AgencyContactSettings::clearCoreMatchExcludedBuyerStatesCache();
        $this->assertSame(['lost'], AgencyContactSettings::forAgencyReadOnly($this->agency->id)->coreMatchesExcludedBuyerStates());
    }
}
