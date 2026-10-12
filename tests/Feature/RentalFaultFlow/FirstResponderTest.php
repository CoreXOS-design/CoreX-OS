<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalFaultType;
use App\Models\RentalFirstResponder;
use App\Models\RentalFirstResponderChange;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\FirstResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21: "Who looks at faults first" - the rule (property list, owner default, per fault type), saving with its
 * change log, the screens, the fault page's block with exactly two choices, and the picker order. Nothing in here may send anything.
 */
final class FirstResponderTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $landlord;
    private RentalFaultType $plumbing;
    private RentalFaultType $electrical;
    private AgencyServiceProvider $caretaker;
    private AgencyServiceProvider $ownerMan;
    private AgencyServiceProvider $plumber;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'First Look Agency', 'slug' => 'fl-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'FL Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->plumbing = $this->type('Leaking tap', 'Plumbing');
        $this->electrical = $this->type('Power tripping', 'Electrical');
        $this->caretaker = $this->supplier('Pieter the caretaker', 'caretaker');
        $this->ownerMan = $this->supplier("Owner's handyman", 'owner_team');
        $this->plumber = $this->supplier('Drip Plumbing', 'contractor', 'plumber');
    }

    private function type(string $name, string $category, ?Agency $agency = null): RentalFaultType
    {
        return RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => ($agency ?? $this->agency)->id, 'name' => $name, 'category' => $category, 'urgency' => 'routine',
            'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function supplier(string $name, string $kind, string $specialty = 'other', ?Agency $agency = null, array $over = []): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => ($agency ?? $this->agency)->id, 'name' => $name, 'specialty' => $specialty, 'kind' => $kind, 'is_active' => true,
            'email' => strtolower(preg_replace('/\W+/', '', $name)) . '@example.invalid', 'phone' => '0825550000',
        ], $over));
    }

    private function row(AgencyServiceProvider $s, string $scope = 'all', array $types = [], string $tell = 'inspect'): array
    {
        return ['supplier_id' => $s->id, 'fault_scope' => $scope, 'fault_type_ids' => $types, 'tell_mode' => $tell];
    }

    private function svc(): FirstResponderService
    {
        return app(FirstResponderService::class);
    }

    private function fault(array $over = []): RentalFaultReport
    {
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);

        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'rental_fault_type_id' => $this->plumbing->id, 'reported_by_type' => 'agent_noticed', 'reported_by_user_id' => $this->agent->id,
            'reported_channel' => 'phone', 'title' => 'Tap leaks', 'description' => 'The kitchen tap drips',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    // ── the rule ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_property_list_is_returned_in_order_and_a_scoped_entry_only_covers_its_types(): void
    {
        $this->svc()->save($this->property, [
            $this->row($this->caretaker, 'types', [$this->plumbing->id], 'info_only'),
            $this->row($this->ownerMan),
        ], $this->agent);

        $plumbing = $this->property->firstRespondersFor($this->plumbing);
        $this->assertSame(['Pieter the caretaker', "Owner's handyman"], $plumbing->pluck('name')->all());
        $this->assertSame([1, 2], $plumbing->pluck('position')->all());
        $this->assertSame('caretaker', $plumbing[0]['kind']);
        $this->assertSame('Caretaker / complex manager', $plumbing[0]['kind_label']);
        $this->assertSame('Notify for information only', $plumbing[0]['tell_label']);
        $this->assertSame('property', $plumbing[0]['source']);
        $this->assertSame('Set for this property', $plumbing[0]['source_label']);

        // an electrical fault: the caretaker is only for plumbing, the owner's man is for all
        $this->assertSame(["Owner's handyman"], $this->property->firstRespondersFor($this->electrical)->pluck('name')->all());
        // a fault with no type: only the "all faults" entry covers it
        $this->assertSame(["Owner's handyman"], $this->property->firstRespondersFor(null)->pluck('name')->all());
        $this->assertSame(["Owner's handyman"], $this->property->firstRespondersFor($this->electrical->id)->pluck('name')->all());
    }

    public function test_the_owner_default_applies_where_the_property_has_nothing_and_the_property_overrides_it_for_the_types_it_covers(): void
    {
        $this->svc()->save($this->landlord, [$this->row($this->ownerMan)], $this->agent);

        $inherited = $this->property->firstRespondersFor($this->plumbing);
        $this->assertSame(["Owner's handyman"], $inherited->pluck('name')->all());
        $this->assertSame('owner', $inherited[0]['source']);
        $this->assertSame('From owner default - Lenny Landlord', $inherited[0]['source_label']);

        // the property sets its own list for plumbing only: plumbing now uses it, everything else still uses the owner's default
        $this->svc()->save($this->property, [$this->row($this->caretaker, 'types', [$this->plumbing->id])], $this->agent);
        $this->assertSame(['Pieter the caretaker'], $this->property->firstRespondersFor($this->plumbing)->pluck('name')->all());
        $this->assertSame('property', $this->property->firstRespondersFor($this->plumbing)[0]['source']);
        $this->assertSame(["Owner's handyman"], $this->property->firstRespondersFor($this->electrical)->pluck('name')->all());
        $this->assertSame('owner', $this->property->firstRespondersFor($this->electrical)[0]['source']);

        $effect = $this->svc()->inEffect($this->property);
        $this->assertSame('property', $effect['source']);
        $this->assertTrue($effect['owner_fills_rest']);
    }

    public function test_nobody_is_set_means_an_empty_list_and_an_inactive_supplier_is_never_offered(): void
    {
        $this->assertTrue($this->property->firstRespondersFor($this->plumbing)->isEmpty());
        $this->assertSame('none', $this->svc()->inEffect($this->property)['source']);

        $this->svc()->save($this->property, [$this->row($this->caretaker), $this->row($this->ownerMan)], $this->agent);
        $this->caretaker->update(['is_active' => false]);
        $this->assertSame(["Owner's handyman"], $this->property->firstRespondersFor($this->plumbing)->pluck('name')->all());
    }

    // ── saving ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_at_most_three_entries_and_no_supplier_twice_and_a_type_limited_row_needs_a_type(): void
    {
        $fourth = $this->supplier('Insurer Inc', 'insurer');
        $fifth = $this->supplier('Body Corp Bob', 'body_corporate');
        try {
            $this->svc()->save($this->property, [$this->row($this->caretaker), $this->row($this->ownerMan), $this->row($fourth), $this->row($fifth)], $this->agent);
            $this->fail('four entries must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at most 3', $e->errors()['first_responders'][0]);
        }
        try {
            $this->svc()->save($this->property, [$this->row($this->caretaker), $this->row($this->caretaker)], $this->agent);
            $this->fail('the same supplier twice must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('twice', $e->errors()['first_responders'][0]);
        }
        try {
            $this->svc()->save($this->property, [$this->row($this->caretaker, 'types', [])], $this->agent);
            $this->fail('a types row with no types must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at least one type', $e->errors()['first_responders'][0]);
        }
        $this->assertSame(0, RentalFirstResponder::withoutGlobalScopes()->count());
    }

    public function test_another_agencys_supplier_and_an_attorney_cannot_be_chosen(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $theirs = $this->supplier('Their Caretaker', 'caretaker', 'other', $other);
        $attorney = $this->supplier('Van Dyk Attorneys', 'contractor', 'transfer_attorney');
        foreach ([$theirs, $attorney] as $bad) {
            try {
                $this->svc()->save($this->property, [$this->row($bad)], $this->agent);
                $this->fail('must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('first_responders', $e->errors());
            }
        }
    }

    public function test_every_change_is_logged_with_before_after_who_and_how_agreed_and_removal_is_soft(): void
    {
        $this->assertTrue($this->svc()->save($this->property, [$this->row($this->caretaker)], $this->agent, 'agreed by phone, 12 Oct'));
        $log = RentalFirstResponderChange::withoutGlobalScopes()->where('property_id', $this->property->id)->latest('id')->first();
        $this->assertSame('Nobody set', $log->old_summary);
        $this->assertStringContainsString('1. Pieter the caretaker (Caretaker / complex manager) - all faults - notify to inspect and report back', $log->new_summary);
        $this->assertSame('agreed by phone, 12 Oct', $log->agreed_with);
        $this->assertSame($this->agent->id, $log->changed_by_user_id);

        // saving the same list again changes nothing and logs nothing
        $this->assertFalse($this->svc()->save($this->property, [$this->row($this->caretaker)], $this->agent));
        $this->assertSame(1, RentalFirstResponderChange::withoutGlobalScopes()->where('property_id', $this->property->id)->count());

        // taking the entry off: soft-deleted (still in the table), logged
        $this->assertTrue($this->svc()->save($this->property, [], $this->agent));
        $this->assertSame(1, RentalFirstResponder::withoutGlobalScopes()->withTrashed()->where('property_id', $this->property->id)->count());
        $this->assertSame(0, RentalFirstResponder::withoutGlobalScopes()->whereNull('deleted_at')->where('property_id', $this->property->id)->count());
        $this->assertSame(2, RentalFirstResponderChange::withoutGlobalScopes()->where('property_id', $this->property->id)->count());
    }

    // ── the screens ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_property_form_saves_the_list_and_the_rental_tab_shows_which_rule_is_in_effect(): void
    {
        $this->actingAs($this->agent)->put(route('corex.properties.rental-first-responders.update', $this->property), [
            'responders' => [
                ['supplier_id' => $this->caretaker->id, 'fault_scope' => 'types', 'fault_type_ids' => [$this->plumbing->id], 'tell_mode' => 'inspect'],
                ['supplier_id' => $this->ownerMan->id, 'fault_scope' => 'all', 'tell_mode' => 'info_only'],
            ],
            'agreed_with' => 'by email',
        ])->assertRedirect();
        $this->assertSame(['Pieter the caretaker', "Owner's handyman"], $this->property->firstRespondersFor($this->plumbing)->pluck('name')->all());

        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $this->property) . '?tab=rental')->assertOk()->getContent();
        $this->assertStringContainsString('Who looks at faults first', $html);
        $this->assertStringContainsString('data-first-responders-in-effect="property"', $html);
        $this->assertStringContainsString('In effect: set for this property.', $html);
        $this->assertStringContainsString('Pieter the caretaker', $html);
    }

    public function test_the_property_form_refuses_a_fourth_row_and_a_property_of_another_agency(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $theirProp = Property::forceCreate(['agency_id' => $other->id, 'agent_id' => $this->agent->id, 'title' => 'Theirs', 'status' => 'active', 'listing_type' => 'rental']);
        $extra = [$this->supplier('A', 'caretaker'), $this->supplier('B', 'insurer'), $this->supplier('C', 'body_corporate'), $this->supplier('D', 'owner_team')];
        $rows = array_map(fn ($s) => ['supplier_id' => $s->id, 'fault_scope' => 'all', 'tell_mode' => 'inspect'], $extra);
        $this->actingAs($this->agent)->put(route('corex.properties.rental-first-responders.update', $this->property), ['responders' => $rows])->assertSessionHasErrors();
        $this->assertSame(0, RentalFirstResponder::withoutGlobalScopes()->count());

        $status = $this->actingAs($this->agent)->put(route('corex.properties.rental-first-responders.update', $theirProp), ['responders' => []])->getStatusCode();
        $this->assertContains($status, [403, 404]);
    }

    public function test_a_property_that_is_not_a_rental_cannot_be_given_a_list(): void
    {
        $sale = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Sale', 'status' => 'active', 'listing_type' => 'sale']);
        $this->actingAs($this->agent)->put(route('corex.properties.rental-first-responders.update', $sale), [
            'responders' => [['supplier_id' => $this->caretaker->id, 'fault_scope' => 'all', 'tell_mode' => 'inspect']],
        ])->assertForbidden();
    }

    public function test_the_owner_default_saves_from_the_contact_page_and_shows_there_and_on_the_property(): void
    {
        $this->actingAs($this->agent)->put(route('corex.contacts.first-responders.update', $this->landlord), [
            'responders' => [['supplier_id' => $this->ownerMan->id, 'fault_scope' => 'all', 'tell_mode' => 'inspect']],
        ])->assertRedirect();
        $this->assertSame(1, RentalFirstResponder::withoutGlobalScopes()->where('contact_id', $this->landlord->id)->count());

        $contactHtml = $this->actingAs($this->agent)->get(route('corex.contacts.show', $this->landlord))->assertOk()->getContent();
        $this->assertStringContainsString("This owner's default for all their properties", $contactHtml);
        $this->assertStringContainsString('data-owner-first-responders', $contactHtml);

        $propHtml = $this->actingAs($this->agent)->get(route('corex.properties.show', $this->property) . '?tab=rental')->getContent();
        $this->assertStringContainsString('data-first-responders-in-effect="owner"', $propHtml);
        $this->assertStringContainsString("In effect: from the owner's default (Lenny Landlord).", $propHtml);
        $this->assertStringContainsString('Change for this property', $propHtml);
    }

    public function test_a_contact_who_is_not_an_owner_gets_no_owner_panel(): void
    {
        $tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant']);
        $html = $this->actingAs($this->agent)->get(route('corex.contacts.show', $tenant))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-owner-first-responders', $html);
    }

    // ── the fault page ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_fault_page_shows_the_block_with_exactly_two_equal_buttons(): void
    {
        $this->svc()->save($this->property, [$this->row($this->caretaker), $this->row($this->ownerMan)], $this->agent);
        $fault = $this->fault();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-first-look-block', $html);
        $this->assertStringContainsString('First to look at this:', $html);
        $this->assertStringContainsString('Pieter the caretaker', $html);
        $this->assertStringContainsString('Caretaker / complex manager', $html);
        // the shared block (cc4's partial) writes the first button's name in the browser, from the map it is given: the name is in that map, both buttons are there
        $this->assertMatchesRegularExpression('/name(?:&quot;|"|\\\\u0022|\\\\")?\s*:\s*(?:&quot;|"|\\\\u0022|\\\\")?Pieter the caretaker/', $html);
        $this->assertStringContainsString('Send to owner to authorise', $html);
        $this->assertSame(1, preg_match_all('/data-inspect-choice/', $html), 'ONE shared two-button block');
        $this->assertSame(1, preg_match_all('/data-send-to-inspect/', $html));
        $this->assertSame(1, preg_match_all('/data-send-to-owner-to-authorise/', $html));
        $this->assertStringContainsString(e("Owner's handyman (Owner's own maintenance team)"), $html); // "Then: ..."
        // the two buttons are the same size: the same utility classes on both
        $this->assertSame(2, preg_match_all('/class="corex-btn-(?:primary|outline) text-xs w-full"[^>]*data-(?:send-to-inspect|send-to-owner-to-authorise)/', $html));
        $this->assertStringContainsString('first-look-form', $html);
    }

    public function test_the_block_is_absent_without_a_first_responder_and_once_a_work_order_or_the_owner_stage_is_reached(): void
    {
        $fault = $this->fault();
        $this->assertStringNotContainsString('data-first-look-block', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent());

        $this->svc()->save($this->property, [$this->row($this->caretaker)], $this->agent);
        $this->assertStringContainsString('data-first-look-block', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent());

        $fault->forceFill(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING])->save();
        $this->assertStringNotContainsString('data-first-look-block', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent());
    }

    public function test_pressing_either_button_only_records_the_choice_and_sends_nothing(): void
    {
        $this->svc()->save($this->property, [$this->row($this->caretaker)], $this->agent);
        $fault = $this->fault();
        $before = $fault->fresh()->only(['status', 'owner_approval_status', 'approval_route', 'sent_to_owner_at']);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.first-responder', $fault), ['supplier_id' => $this->caretaker->id, 'choice' => 'inspect'])->assertRedirect();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.first-responder', $fault), ['supplier_id' => $this->caretaker->id, 'choice' => 'owner_authorise'])->assertRedirect();

        $notes = RentalFaultReportUpdate::withoutGlobalScopes()->where('rental_fault_report_id', $fault->id)->where('update_type', RentalFaultReportUpdate::TYPE_FIRST_RESPONDER_CHOSEN)->orderBy('id')->pluck('note')->all();
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('chose to send Pieter the caretaker (Caretaker / complex manager) to inspect - not sent yet', $notes[0]);
        $this->assertStringContainsString('chose to send this to the owner to authorise', $notes[1]);
        $this->assertSame($before, $fault->fresh()->only(['status', 'owner_approval_status', 'approval_route', 'sent_to_owner_at']));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_a_supplier_who_is_not_the_first_responder_cannot_be_chosen_by_typing_an_id(): void
    {
        $this->svc()->save($this->property, [$this->row($this->caretaker)], $this->agent);
        $fault = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.first-responder', $fault), ['supplier_id' => $this->plumber->id, 'choice' => 'inspect'])->assertStatus(422);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.first-responder', $fault), ['supplier_id' => $this->caretaker->id, 'choice' => 'delete-everything'])->assertSessionHasErrors('choice');
        $this->assertSame(0, RentalFaultReportUpdate::withoutGlobalScopes()->where('update_type', RentalFaultReportUpdate::TYPE_FIRST_RESPONDER_CHOSEN)->count());
    }

    public function test_the_contractor_picker_lists_the_first_responders_on_top_under_set_for_this_property(): void
    {
        $this->svc()->save($this->property, [$this->row($this->ownerMan), $this->row($this->caretaker)], $this->agent);
        $fault = $this->fault(['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => 'agency_appoints']);

        $picker = app(\App\Services\Rentals\RentalFaultContractorService::class)->pickerFor($fault);
        $this->assertSame(["Owner's handyman", 'Pieter the caretaker'], $picker->take(2)->pluck('name')->all());
        $this->assertTrue($picker[0]['first_responder']);
        $this->assertSame(1, $picker->where('name', 'Pieter the caretaker')->count(), 'a first responder is not repeated below');
        $this->assertTrue($picker->contains('name', 'Drip Plumbing'));

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-group="set"', $html);
        $this->assertStringContainsString('Set for this property', $html);
        $this->assertLessThan(strpos($html, 'Other contractors'), strpos($html, 'Set for this property'));
        $this->assertStringContainsString('data-contractor-kind="caretaker"', $html);
    }

    public function test_one_agencys_lists_never_reach_another(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $theirProp = Property::forceCreate(['agency_id' => $other->id, 'agent_id' => $this->agent->id, 'title' => 'Theirs', 'status' => 'active', 'listing_type' => 'rental']);
        $this->svc()->save($this->property, [$this->row($this->caretaker)], $this->agent);
        $this->assertTrue($theirProp->firstRespondersFor(null)->isEmpty());
    }
}
