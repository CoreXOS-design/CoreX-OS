<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalAuthorisationService;
use App\Services\Rentals\RentalFaultScreen;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 28: the office fault screen simplified (Johan approved 19 Oct 2026). A "Returned | Next" bar with one next
 * action at EVERY stage; a recorded "Declined" can be changed from the screen; the owner's cost approval given on the same call as the go-ahead is offered back as one
 * press; a phone quote needs no document; a blank final cost is refused; what was already typed is carried, not retyped.
 */
final class FaultScreenSimplifiedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function __construct() {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void {}
        });
        $this->agency = Agency::create(['name' => 'Simplified Agency', 'slug' => 'sa-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'SA Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid', 'phone' => '0821112222']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
    }

    private function fault(bool $saveVersion = true): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'Tap drips', 'description' => 'The kitchen tap drips all night', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/sa/tap.jpg']);
        if ($saveVersion) {
            $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.'], $this->agent);
        }

        return $fault->fresh();
    }

    private function supplier(string $name = 'Acme Plumbing'): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)) . '@example.invalid', 'is_active' => true, 'specialty' => 'other',
            'callout_fee_mode' => 'none',
        ]);
    }

    private function record(RentalFaultReport $f, array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $f), array_merge([
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call', 'told_at' => now()->subHour()->format('Y-m-d\TH:i'), 'note' => 'Lenny phoned: send a plumber to look.',
        ], $over));
    }

    /** The page as the agent reads it: entities decoded so wording can be asserted as written. */
    private function page(RentalFaultReport $f): string
    {
        return html_entity_decode($this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent(), ENT_QUOTES);
    }

    private function workOrderFrom(RentalFaultReport $f, AgencyServiceProvider $s): RentalWorkOrder
    {
        app(RentalWorkOrderService::class)->createFromFaultDecision($f->fresh(), $this->agent, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $s->id]);

        return RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->fresh()->rental_work_order_id);
    }

    private function quote(RentalWorkOrder $wo, AgencyServiceProvider $s, float $amount): void
    {
        $q = $wo->recordQuote(['agency_service_provider_id' => $s->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the tap'], $this->agent);
        $wo->selectQuote($q, $this->agent);
    }

    // ── 1. the Returned | Next bar: ONE next action at every stage ────────────────────────────────────────────────

    public function test_the_next_bar_names_one_next_action_at_every_stage(): void
    {
        $f = $this->fault();

        // reported / under review
        $html = $this->page($f);
        $this->assertStringContainsString('data-land-on-next data-stage="prepare" data-next="prepare"', $html);
        $this->assertStringContainsString('Send to the owner to authorise', $html);
        $this->assertStringContainsString('data-wo-click="[data-send-to-owner-to-authorise]"', $html, 'the bar button presses the real button; the confirm dialog still asks');
        $this->assertStringContainsString('Reported', $html);
        $this->assertSame(1, preg_match_all('/<[^>]*\sdata-land-on-next[\s>]/', $html), 'the page lands on the bar, and only on the bar');

        // with the owner
        $f->requestApproval($this->agent);
        $html = $this->page($f->fresh());
        $this->assertStringContainsString('data-stage="with_owner" data-next="with_owner"', $html);
        $this->assertStringContainsString("Record the owner's decision", $html);
        $this->assertStringContainsString('they have not answered yet', $html);

        // owner approved (recorded by the agent): the decision text is in the bar, the green form is the next action
        $this->record($f->fresh(), ['note' => 'Lenny phoned: yes send a plumber'])->assertSessionHasNoErrors();
        $html = $this->page($f->fresh());
        $this->assertStringContainsString('data-stage="appoint" data-next="appoint"', $html);
        $this->assertStringContainsString('Owner approved by phone call', $html);
        $this->assertStringContainsString('Lenny phoned: yes send a plumber', $html);
        $this->assertStringContainsString('Create work order', $html);

        // a work order exists
        $wo = $this->workOrderFrom($f, $this->supplier());
        $html = $this->page($f->fresh());
        $this->assertStringContainsString('data-stage="work_order" data-next="work_order"', $html);
        $this->assertStringContainsString('Open work order #' . $wo->id, $html);
    }

    public function test_the_stage_comes_from_one_place_and_every_stage_has_a_next_button_or_says_there_is_nothing_left(): void
    {
        $f = $this->fault();
        $screen = app(RentalFaultScreen::class);
        $c = ['can' => ['send_to_owner' => true, 'record_approval' => true, 'raise_work_order' => true], 'canOpenWorkOrder' => true];

        $out = $screen->build($f, $c);
        $this->assertSame('prepare', $out['stage']);
        $this->assertNotNull($out['next']['button']);
        $this->assertSame('Send to the owner to authorise', $out['next']['button']['label']);

        $f->forceFill(['status' => RentalFaultReport::STATUS_RESOLVED, 'resolved_at' => now(), 'outcome' => 'repaired'])->save();
        $closed = $screen->build($f->fresh(), $c);
        $this->assertSame('closed', $closed['stage']);
        $this->assertNull($closed['next']['button'], 'a closed fault with no work order has nothing left to do');
        $this->assertStringContainsString('Nothing is left to do', $closed['next']['text']);
    }

    public function test_finished_stages_fold_to_one_line_and_the_current_stage_is_open(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        $html = $this->page($f->fresh());

        preg_match_all('/<details class="wo-panel"[^>]*>/', $html, $tags);
        $this->assertMatchesRegularExpression('/<details class="wo-panel" id="wo-panel-decision"[^>]*\sopen\s*>/', $html, 'the current stage is open; panels: ' . implode(' ', $tags[0]));
        $this->assertDoesNotMatchRegularExpression('/<details class="wo-panel" id="wo-panel-history"[^>]*\sopen\s*>/', $html, 'history is collapsed');
        $this->assertDoesNotMatchRegularExpression('/<details class="wo-panel" id="wo-panel-ownercopy"[^>]*\sopen\s*>/', $html, 'once sent, the owner copy is one summary line');
        $this->assertStringContainsString('Sent to the owner', $html);
        $this->assertStringNotContainsString('data-owner-decision-summary', $html, 'no empty "Owner decision" shell while nothing is decided');
    }

    // ── 2. carry through ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_blank_owner_title_uses_the_fault_title_and_a_deliberate_blank_description_is_kept(): void
    {
        $f = $this->fault(false);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-version.store', $f), [
            'owner_title' => '', 'owner_description' => '', 'owner_agent_note' => 'Please approve',
        ])->assertSessionHasNoErrors();

        $f = $f->fresh();
        $this->assertSame('Tap drips', $f->owner_title, 'blank = the fault\'s own title, no 422');
        $this->assertNull($f->owner_description);
        $html = $this->page($f);
        $this->assertStringNotContainsString('The kitchen tap drips all night</textarea>', $html, 'a deliberate blank is not refilled with the tenant\'s raw words');
    }

    public function test_the_contractor_picked_travels_with_the_owners_decision(): void
    {
        $f = $this->fault();
        $s = $this->supplier();
        $this->record($f, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $s->id])->assertSessionHasNoErrors();

        $f = $f->fresh();
        $this->assertSame($s->id, $f->proposed_supplier_id);
        $this->assertSame('outside_supplier', $f->proposed_assignment);
        $this->assertStringContainsString('name="agency_service_provider_id" value="' . $s->id . '"', html_entity_decode($this->page($f)), 'the appoint form opens with them chosen');
    }

    public function test_a_supplier_of_another_agency_is_never_carried(): void
    {
        $f = $this->fault();
        $other = Agency::create(['name' => 'Other', 'slug' => 'ot-' . uniqid()]);
        $foreign = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $other->id, 'name' => 'Foreign', 'email' => 'f@example.invalid', 'is_active' => true, 'specialty' => 'other', 'callout_fee_mode' => 'none']);
        $this->record($f, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $foreign->id])->assertSessionHasNoErrors();

        $this->assertNull($f->fresh()->proposed_supplier_id);
    }

    // ── 3. same call ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_same_call_is_one_note_for_both_decisions_and_the_cost_step_is_one_press(): void
    {
        $f = $this->fault();
        $this->record($f, ['also_approve_cost' => 1, 'also_cost_up_to' => 1850, 'note' => 'Lenny: send him, and up to 1850 is fine'])->assertSessionHasNoErrors();
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('point', 'inspect')->whereNull('superseded_at')->firstOrFail();
        $this->assertSame('1850.00', (string) $row->also_cost_up_to);

        // nothing about the cost is authorised by the go-ahead itself (section 26 stands)
        $s = $this->supplier();
        $wo = $this->workOrderFrom($f, $s);
        $this->assertFalse(app(RentalAuthorisationService::class)->hasCostAuthority($wo));
        $this->assertNull(RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->first());

        // no quote yet: the offer waits
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-same-call', $html);
        $this->assertStringContainsString('data-same-call-waiting', $html);
        $this->assertStringNotContainsString('data-same-call-use', $html);

        // a quote at or below the figure: ONE button, the Next bar offers it too
        $this->quote($wo, $s, 1700);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('data-same-call-use', $html);
        $this->assertStringContainsString('data-next="same_call"', $html);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.cost.use-same-call', $wo))->assertSessionHasNoErrors()->assertRedirect();
        $cost = RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->firstOrFail();
        $this->assertTrue($cost->isAuthorised());
        $this->assertSame('owner_instruction', $cost->basis);
        $this->assertSame('1850.00', (string) $cost->amount_up_to);
        $this->assertSame('Lenny: send him, and up to 1850 is fine', $cost->note, 'the SAME words - not typed again');
        $this->assertSame('phone_call', $cost->told_via);
        $this->assertTrue(app(RentalAuthorisationService::class)->hasCostAuthority($wo->fresh()));
    }

    public function test_a_quote_above_the_same_call_figure_is_not_approved_and_says_ask_the_owner(): void
    {
        $f = $this->fault();
        $this->record($f, ['also_approve_cost' => 1, 'also_cost_up_to' => 1000])->assertSessionHasNoErrors();
        $s = $this->supplier();
        $wo = $this->workOrderFrom($f, $s);
        $this->quote($wo, $s, 1500);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('data-same-call-above', $html);
        $this->assertStringNotContainsString('data-same-call-use', $html);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.cost.use-same-call', $wo))->assertSessionHasErrors('cost_decision');
        $this->assertNull(RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->first());
    }

    public function test_the_same_call_tick_needs_an_amount_and_is_ignored_on_a_decline(): void
    {
        $f = $this->fault();
        $this->record($f, ['also_approve_cost' => 1, 'also_cost_up_to' => ''])->assertSessionHasErrors('owner_decision');
        $this->assertSame(0, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count());

        $this->record($f, ['decision' => 'declined', 'note' => 'Lenny: no', 'also_approve_cost' => 1, 'also_cost_up_to' => 900])->assertSessionHasNoErrors();
        $this->assertNull(RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->firstOrFail()->also_cost_up_to);
    }

    public function test_a_newer_decision_replaces_the_same_call_figure_with_it(): void
    {
        $f = $this->fault();
        $this->record($f, ['also_approve_cost' => 1, 'also_cost_up_to' => 1850])->assertSessionHasNoErrors();
        $this->record($f->fresh(), ['note' => 'Lenny phoned again: look only, no cost yet'])->assertSessionHasNoErrors();

        $stands = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('point', 'inspect')->whereNull('superseded_at')->firstOrFail();
        $this->assertNull($stands->also_cost_up_to);
        $this->assertNull(app(RentalAuthorisationService::class)->sameCallOffer($this->workOrderFrom($f, $this->supplier())));
    }

    // ── 4. a recorded Declined can be changed ────────────────────────────────────────────────────────────────────

    public function test_a_recorded_declined_can_be_changed_from_the_screen_and_the_history_keeps_both(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        $this->record($f->fresh(), ['decision' => 'declined', 'note' => 'Lenny: not now, too expensive'])->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $f->fresh()->status);

        $html = $this->page($f->fresh());
        $this->assertStringContainsString('data-stage="declined" data-next="declined"', $html);
        $this->assertStringContainsString('Owner changed their mind - change the decision', $html);
        $this->assertStringContainsString('data-owner-decision-modal', $html, 'the same popup, no dead end');
        $this->assertStringContainsString('Owner declined by phone call', $html);
        $this->assertStringContainsString('Lenny: not now, too expensive', $html);

        // the owner rings back
        $this->record($f->fresh(), ['note' => 'Lenny rang back: go ahead after all'])->assertSessionHasNoErrors();
        $f = $f->fresh();
        $this->assertNotSame(RentalFaultReport::STATUS_DECLINED, $f->status);
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->status);

        $this->assertSame(2, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('point', 'inspect')->count(), 'both entries stay');
        $this->assertSame(1, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('point', 'inspect')->whereNull('superseded_at')->count());
        $this->assertSame(2, $f->approvals()->count());
        $actions = $f->history()->pluck('action')->implode(' | ');
        $this->assertStringContainsString('Declined', $actions);
        $this->assertStringContainsString('Approved', $actions);
        $this->assertStringContainsString('replaced', html_entity_decode($this->page($f)), 'the older record is marked replaced');
    }

    public function test_a_declined_cannot_be_changed_once_a_work_order_exists_or_after_the_owners_own_press(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        $this->record($f->fresh())->assertSessionHasNoErrors();
        $this->workOrderFrom($f, $this->supplier());

        $this->record($f->fresh(), ['decision' => 'declined', 'note' => 'changed again'])->assertSessionHasErrors('owner_decision');
    }

    // ── 5. tenant confirmation: how and when are enough ──────────────────────────────────────────────────────────

    public function test_confirming_with_the_tenant_needs_only_how_and_when(): void
    {
        $f = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'phone_call', 'at' => now()->subMinutes(10)->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();
        $f = $f->fresh();
        $this->assertSame('phone_call', $f->tenant_confirmed_via);
        $this->assertSame('', (string) $f->tenant_confirmed_note);
        $line = $f->history()->pluck('note')->implode(' | ');
        $this->assertStringContainsString('Confirmed with the tenant by phone call', $f->history()->pluck('action')->implode(' | ') . ' ' . $line);
        $this->assertStringNotContainsString('""', $line);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['at' => now()->subMinutes(10)->format('Y-m-d\TH:i')])->assertSessionHasErrors('via');
    }

    // ── 6. a phone quote ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_phone_quote_needs_no_document_and_no_details_but_a_bare_quote_is_still_refused(): void
    {
        $f = $this->fault();
        $s = $this->supplier();
        $this->record($f)->assertSessionHasNoErrors();
        $wo = $this->workOrderFrom($f, $s);
        $url = route('corex.rental-work-orders.quotes.store', $wo);
        $base = ['agency_service_provider_id' => $s->id, 'amount' => 1200, 'quote_date' => '2026-10-19'];

        $this->actingAs($this->agent)->post($url, $base)->assertSessionHasErrors('quote');
        $this->assertSame(0, $wo->quotes()->count());

        $this->actingAs($this->agent)->post($url, $base + ['quoted_by_phone' => 1])->assertSessionHasNoErrors();
        $q = $wo->fresh()->quotes()->firstOrFail();
        $this->assertSame('1200.00', (string) $q->amount);
        $this->assertNull($q->document_storage_path);
        $this->assertSame('Quoted by phone on 19 Oct 2026.', $q->detail_text);
        $this->assertTrue((bool) $q->is_selected, 'the only quote is selected for you, as always - the owner still authorises the cost');
        $this->assertFalse(app(RentalAuthorisationService::class)->hasCostAuthority($wo->fresh()));
    }

    // ── 7. completion: final cost carried, never blank; one place for who pays ──────────────────────────────────

    private function readyToComplete(): array
    {
        $f = $this->fault();
        $this->record($f, ['also_approve_cost' => 1, 'also_cost_up_to' => 1850])->assertSessionHasNoErrors();
        $s = $this->supplier();
        $wo = $this->workOrderFrom($f, $s);
        $this->quote($wo, $s, 1700);
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.cost.use-same-call', $wo))->assertSessionHasNoErrors();

        return [$f, $wo->fresh()];
    }

    public function test_the_complete_form_starts_with_the_authorised_amount_and_a_blank_final_cost_is_refused(): void
    {
        [, $wo] = $this->readyToComplete();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-cost-from-authorised', $html);
        $this->assertMatchesRegularExpression('/name="cost_amount"[^>]*required[^>]*value="1850.00"/', $html);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.complete', $wo), ['cost_amount' => ''])->assertSessionHasErrors('cost_amount');
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertNull($wo->fresh()->cost_amount, 'nothing was saved, so no owner statement with no amount');
    }

    public function test_a_job_from_a_fault_does_not_ask_who_paid_at_completion_and_the_server_keeps_it_not_yet_paid(): void
    {
        [, $wo] = $this->readyToComplete();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="paid_by"', $html);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.complete', $wo), ['cost_amount' => 1700])->assertSessionDoesntHaveErrors(['paid_by']);
        $this->assertSame(RentalWorkOrder::PAID_BY_NOT_YET_PAID, $wo->fresh()->paid_by);
    }

    public function test_completing_a_fault_job_after_a_ruling_keeps_paid_by_in_step_with_the_ruling(): void
    {
        [, $wo] = $this->readyToComplete();
        $wo->forceFill(['who_pays' => 'tenant'])->saveQuietly();

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.complete', $wo), ['cost_amount' => 1700])->assertSessionDoesntHaveErrors(['paid_by']);
        $this->assertSame(RentalWorkOrder::PAID_BY_TENANT, $wo->fresh()->paid_by);
    }

    public function test_the_cause_never_silently_records_the_first_option(): void
    {
        [, $wo] = $this->readyToComplete();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select id="wo-cause"[^>]*>\s*<option value="" selected>Choose/', $html, 'a blank first option, so skipping it records nothing');
        $this->assertStringNotContainsString('for="wo-found">What was found (required)', $html, 'the Complete form no longer says required for what the server does not require');
        $this->assertStringNotContainsString('for="wo-cause">Cause (required)', $html);

        // found text without a cause is refused by the server, as before
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.complete', $wo), ['cost_amount' => 1700, 'found_text' => 'Washer perished', 'cause_id' => ''])->assertSessionHasErrors('rental_work_order');
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    // ── 8. small things ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_standalone_work_order_form_no_longer_asks_for_a_trade_type(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.create', ['property_id' => $this->property->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="trade_type"', $html);
    }

    public function test_the_access_contact_is_not_asked_twice_and_the_tenant_can_fill_it_in_one_press(): void
    {
        $f = $this->fault();
        $this->record($f)->assertSessionHasNoErrors();
        $wo = $this->workOrderFrom($f, $this->supplier());

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'name="access_contact_name"'), 'one copy of the three boxes, in the Appointment panel');
        $this->assertStringContainsString('data-tenant-is-access', $html);
        $this->assertStringContainsString('Tina Tenant', html_entity_decode($html));
    }

    public function test_the_photo_type_follows_where_the_job_is(): void
    {
        [, $wo] = $this->readyToComplete();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="reported" selected>/', $html, 'before the work: Reported (before)');

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED])->saveQuietly();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="completed" selected>/', $html, 'once the work is done: Completed');
    }

    public function test_the_owner_version_rules_are_written_once(): void
    {
        $this->assertSame(['nullable', 'string', 'max:191'], RentalFaultReport::OWNER_VERSION_RULES['owner_title']);
        foreach (['RentalFaultReportController', 'RentalFaultAuthorityController'] as $c) {
            $src = file_get_contents(app_path("Http/Controllers/CoreX/{$c}.php"));
            $this->assertStringContainsString('OWNER_VERSION_RULES', $src);
            $this->assertStringNotContainsString("'owner_description' => ['nullable'", $src, "$c must not repeat the rules");
        }
    }
}
