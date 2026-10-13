<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalPayerRulingMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeCause;
use App\Models\RentalJobCard;
use App\Models\RentalPayerRuling;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalSecureAccessTokenService;
use App\Services\Rentals\RentalWhoPaysService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.13, stage 4: "Who pays" - a LOGGED ruling. What was found + the cause (contractor link, crew link,
 * agent), a default payer from the fault type's list, the agent's append-only ruling, the owner and tenant told in plain words. Nothing is charged or invoiced.
 */
final class WhoPaysRulingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'Pay Agency', 'slug' => 'wp-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'WP Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Blocked drain / toilet', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Stop using it.', 'is_default' => true, 'sort_order' => 1, 'is_active' => true]);
        RentalFaultTypeCause::seedForType($this->type);
    }

    private function cause(string $label): RentalFaultTypeCause
    {
        return RentalFaultTypeCause::withoutGlobalScopes()->where('rental_fault_type_id', $this->type->id)->where('label', $label)->firstOrFail();
    }

    private function fault(array $over = []): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->type->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'Drain blocked', 'description' => 'Water will not go down', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
            'tenant_self_checks' => [['text' => 'Pour hot water down the drain.', 'ticked' => true], ['text' => 'Check the gully outside', 'ticked' => false]],
        ], $over));
    }

    private function workOrder(?RentalFaultReport $fault = null, array $over = []): RentalWorkOrder
    {
        $fault ??= $this->fault();
        $wo = RentalWorkOrder::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'title' => 'Clear the drain', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_IN_PROGRESS,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'reported_by_type' => 'tenant', 'reported_at' => now(), 'reported_fault_report_id' => $fault->id,
            'created_by_user_id' => $this->agent->id,
        ], $over));
        $fault->forceFill(['rental_work_order_id' => $wo->id])->save();

        return $wo->fresh();
    }

    private function svc(): RentalWhoPaysService
    {
        return app(RentalWhoPaysService::class);
    }

    private function found(RentalWorkOrder $wo, string $cause = 'Foreign object in the drain'): RentalWorkOrder
    {
        $this->svc()->recordFindings($wo, 'A toy car was stuck in the pipe', $this->cause($cause)->id, null, 'Test (agent)', $this->agent);

        return $wo->fresh();
    }

    // ── seeded defaults + the fault-type lists ────────────────────────────────

    public function test_the_seeded_defaults_are_neutral_idempotent_and_as_ruled(): void
    {
        $this->assertSame('tenant', $this->cause('Foreign object in the drain')->default_payer);
        $this->assertSame('owner', $this->cause('Roots in the pipe')->default_payer);
        $this->assertSame('owner', $this->cause('Collapsed pipe')->default_payer);
        $this->assertSame('owner', $this->cause('Wear and tear')->default_payer);
        $before = RentalFaultTypeCause::withoutGlobalScopes()->count();
        RentalFaultTypeCause::seedForType($this->type);
        $this->assertSame($before, RentalFaultTypeCause::withoutGlobalScopes()->count());

        $gate = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Gate / garage motor', 'category' => 'Security', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => true, 'sort_order' => 2, 'is_active' => true]);
        RentalFaultTypeCause::seedForType($gate);
        $row = RentalFaultTypeCause::withoutGlobalScopes()->where('rental_fault_type_id', $gate->id)->where('kind', 'self_check')->firstOrFail();
        $this->assertSame('Remote control battery is flat', $row->label);
        $this->assertSame('tenant', $row->default_payer);
    }

    public function test_the_fault_type_screen_creates_edits_archives_and_restores_with_no_hard_delete(): void
    {
        $url = route('corex.rental-fault-types.causes.store', $this->type);
        $this->actingAs($this->agent)->post($url, ['kind' => 'cause', 'label' => 'Tree roots', 'default_payer' => 'owner'])->assertRedirect();
        $row = RentalFaultTypeCause::withoutGlobalScopes()->where('label', 'Tree roots')->firstOrFail();
        $this->assertSame($this->agency->id, (int) $row->agency_id);

        $this->actingAs($this->agent)->post($url, ['kind' => 'cause', 'label' => 'tree roots'])->assertSessionHasErrors('label');

        $this->actingAs($this->agent)->put(route('corex.rental-fault-types.causes.update', [$this->type, $row->id]), ['kind' => 'cause', 'label' => 'Tree roots in pipe', 'default_payer' => ''])->assertRedirect();
        $this->assertNull($row->fresh()->default_payer);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-types.causes.archive', [$this->type, $row->id]))->assertRedirect();
        $this->assertSoftDeleted('rental_fault_type_causes', ['id' => $row->id]);
        $this->actingAs($this->agent)->get(route('corex.rental-fault-types.edit', $this->type))->assertOk()->assertDontSee('Tree roots in pipe');
        $this->actingAs($this->agent)->get(route('corex.rental-fault-types.edit', $this->type) . '?archived_causes=1')->assertOk()->assertSee('Tree roots in pipe')->assertSee('Archived');

        $this->actingAs($this->agent)->post(route('corex.rental-fault-types.causes.restore', [$this->type, $row->id]))->assertRedirect();
        $this->assertNull($row->fresh()->deleted_at);
        $this->assertTrue((bool) $row->fresh()->is_active);
    }

    public function test_another_agencys_fault_type_and_rows_are_not_reachable(): void
    {
        $wo = $this->found($this->workOrder());
        $other = Agency::create(['name' => 'Other', 'slug' => 'ot-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'O-' . $other->id, 'is_active' => true]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $row = $this->cause('Wear and tear');

        $this->actingAs($stranger)->post(route('corex.rental-fault-types.causes.store', $this->type), ['kind' => 'cause', 'label' => 'Hacked'])->assertNotFound();
        $this->actingAs($stranger)->post(route('corex.rental-fault-types.causes.archive', [$this->type, $row->id]))->assertNotFound();
        $this->assertNull($row->fresh()->deleted_at);

        $this->actingAs($stranger)->post(route('corex.rental-work-orders.who-pays.rule', $wo), ['payer' => 'owner', 'reason' => 'nope'])->assertNotFound();
        $this->assertSame(0, RentalPayerRuling::withoutGlobalScopes()->count());
    }

    // ── report-back ───────────────────────────────────────────────────────────

    public function test_the_contractor_link_needs_what_was_found_and_a_cause_on_a_fault_job_and_records_them(): void
    {
        $supplier = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Pipe Pros', 'email' => 'pipe@example.invalid', 'is_active' => true, 'specialty' => 'other']);
        $wo = $this->workOrder(null, ['agency_service_provider_id' => $supplier->id]);
        $raw = app(RentalSecureAccessTokenService::class)->issueFor($wo, $this->agent)['raw_token'];

        $this->get(route('rentals.secure-link.show', $raw))->assertOk()->assertSee('What did you find?')->assertSee('Foreign object in the drain')->assertSee('Something else (describe it)');

        $this->post(route('rentals.secure-link.mark-done', $raw), [])->assertSessionHasErrors('work_order');
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);

        $this->post(route('rentals.secure-link.mark-done', $raw), ['found_text' => 'Roots in the line', 'cause_id' => '', 'cause_note' => ''])->assertSessionHasErrors('work_order');

        $this->post(route('rentals.secure-link.mark-done', $raw), ['found_text' => 'Roots in the line', 'cause_id' => $this->cause('Roots in the pipe')->id, 'cause_note' => 'Large tree next door'])->assertSessionHasNoErrors();
        $fresh = $wo->fresh();
        $this->assertSame('Roots in the line', $fresh->found_text);
        $this->assertSame('Roots in the pipe', $fresh->cause_label);
        $this->assertSame('Roots in the pipe - Large tree next door', $this->svc()->causeText($fresh));
        $this->assertSame('Pipe Pros (contractor link)', $fresh->reported_back_by);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $fresh->status);
    }

    public function test_a_job_that_did_not_come_from_a_fault_is_unchanged(): void
    {
        $supplier = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Pipe Pros', 'email' => 'pipe2@example.invalid', 'is_active' => true, 'specialty' => 'other']);
        $wo = $this->workOrder(null, ['agency_service_provider_id' => $supplier->id, 'reported_fault_report_id' => null]);
        $raw = app(RentalSecureAccessTokenService::class)->issueFor($wo, $this->agent)['raw_token'];

        $this->get(route('rentals.secure-link.show', $raw))->assertOk()->assertDontSee('What did you find?');
        $this->post(route('rentals.secure-link.mark-done', $raw), [])->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertNull($wo->fresh()->found_text);
    }

    public function test_the_crew_link_asks_for_the_same_two_things_before_signing_off(): void
    {
        $wo = $this->workOrder(null, ['assignment_type' => RentalWorkOrder::ASSIGNMENT_INTERNAL]);
        $card = RentalJobCard::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'title' => 'Clear the drain', 'status' => RentalJobCard::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(), 'created_by_user_id' => $this->agent->id, 'rental_work_order_id' => $wo->id,
        ]);
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->agent)['raw_token'];

        $this->post(route('rentals.crew-job.complete', $raw), ['full_name' => 'Pat Plumber', 'confirm' => 1])->assertSessionHasErrors();
        $this->assertNull($card->fresh()->worker_signed_off_at);
        $this->assertNull($wo->fresh()->found_text);

        $this->post(route('rentals.crew-job.complete', $raw), ['full_name' => 'Pat Plumber', 'confirm' => 1, 'found_text' => 'A toy car', 'cause_id' => $this->cause('Foreign object in the drain')->id]);
        $this->assertSame('A toy car', $wo->fresh()->found_text);
        $this->assertSame('Pat Plumber (crew)', $wo->fresh()->reported_back_by);
    }

    public function test_the_agent_can_record_and_correct_and_every_change_leaves_a_history_line(): void
    {
        $wo = $this->workOrder();
        $url = route('corex.rental-work-orders.who-pays.findings', $wo);
        $this->actingAs($this->agent)->post($url, ['found_text' => '', 'cause_id' => ''])->assertSessionHasErrors('who_pays');

        $this->actingAs($this->agent)->post($url, ['found_text' => 'Blocked with a toy', 'cause_id' => $this->cause('Foreign object in the drain')->id])->assertRedirect();
        $this->assertSame('Blocked with a toy', $wo->fresh()->found_text);
        $this->assertSame('Agnes Agent (agent)', $wo->fresh()->reported_back_by);

        $this->actingAs($this->agent)->post($url, ['found_text' => 'Blocked with a toy', 'cause_id' => $this->cause('Foreign object in the drain')->id])->assertRedirect();
        $this->actingAs($this->agent)->post($url, ['found_text' => 'Pipe has collapsed', 'cause_id' => $this->cause('Collapsed pipe')->id])->assertRedirect();

        $this->assertSame(2, $wo->updates()->where('note', 'like', '%recorded - What was found%')->count(), 'an unchanged save writes nothing');
        $this->assertStringContainsString('Before: What was found: "Blocked with a toy"', (string) $wo->updates()->latest('id')->first()->note);
    }

    // ── suggestion + the block ────────────────────────────────────────────────

    public function test_the_suggestion_comes_from_the_picked_cause_and_a_ticked_self_check_shows_its_default(): void
    {
        RentalFaultTypeCause::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $this->type->id, 'kind' => 'self_check', 'label' => 'Pour hot water down the drain', 'default_payer' => 'tenant', 'sort_order' => 9, 'is_active' => true]);
        $wo = $this->workOrder();
        $this->assertNull($this->svc()->suggestion($wo));

        $wo = $this->found($wo, 'Roots in the pipe');
        $this->assertSame('owner', $this->svc()->suggestion($wo)['payer']);
    }

    public function test_the_work_order_page_shows_the_ticks_the_confirmation_the_findings_and_the_default(): void
    {
        RentalFaultTypeCause::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $this->type->id, 'kind' => 'self_check', 'label' => 'Pour hot water down the drain', 'default_payer' => 'tenant', 'sort_order' => 9, 'is_active' => true]);
        $fault = $this->fault(['tenant_confirmed_via' => 'phone_call', 'tenant_confirmed_at' => now(), 'tenant_confirmed_note' => 'Tenant tried it twice', 'tenant_confirmed_by_user_id' => $this->agent->id, 'tenant_confirmed_recorded_at' => now()]);
        $wo = $this->found($this->workOrder($fault), 'Roots in the pipe');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        foreach (['Who pays', 'Pour hot water down the drain.', 'Normally: Tenant', 'Tenant tried it twice', 'A toy car was stuck in the pipe', 'Roots in the pipe', 'Owner (The default for', 'Rule who pays', 'A record of the decision. CoreX does not raise a charge.'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('Not reported yet', $html);
        $this->assertStringNotContainsString('No ruling yet', $html);
        $this->assertStringNotContainsString('No default for this cause', $html);
        // it sits after the working sections and before Photos
        $this->assertGreaterThan(strpos($html, 'id="approval-panel"'), strpos($html, 'id="who-pays"'));
        $this->assertLessThan(strpos($html, 'id="wo-photos"'), strpos($html, 'id="who-pays"'));
        // a work order that did not come from a fault has no block
        $plain = $this->workOrder(null, ['reported_fault_report_id' => null]);
        $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $plain))->assertOk()->assertDontSee('id="who-pays"', false);
    }

    public function test_before_anything_is_reported_it_is_one_collapsed_line_and_opens_when_there_is_something_to_act_on(): void
    {
        $wo = $this->workOrder(null, ['status' => RentalWorkOrder::STATUS_ORDERED]);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-who-pays-collapsed', $html);
        $this->assertStringContainsString('Decided once we know what was found.', $html);
        $this->assertStringContainsString('Enter what was found', $html);
        foreach (['Pour hot water', 'Nothing recorded yet', 'Rule who pays', 'What the tenant says they checked'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }

        // the job is under way: the block opens, says nothing is recorded, and shows the plain no-self-checks line
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $fault = $this->svc()->faultFor($wo);
        $fault->forceFill(['tenant_self_checks' => null])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-who-pays-collapsed', $html);
        $this->assertStringContainsString('Nothing recorded yet.', $html);
        $this->assertStringContainsString('No self-checks were shown for this fault.', $html);
        $this->assertStringNotContainsString('The tenant was shown no self-checks.', $html);
    }

    // ── the ruling ────────────────────────────────────────────────────────────

    public function test_a_ruling_needs_findings_a_reason_and_for_shared_a_split(): void
    {
        $wo = $this->workOrder();
        $url = route('corex.rental-work-orders.who-pays.rule', $wo);
        $this->actingAs($this->agent)->post($url, ['payer' => 'tenant', 'reason' => 'Toy in drain'])->assertSessionHasErrors('who_pays');   // no findings yet

        $wo = $this->found($wo);
        $this->actingAs($this->agent)->post($url, ['payer' => 'tenant'])->assertSessionHasErrors('reason');
        $this->actingAs($this->agent)->post($url, ['payer' => 'bank', 'reason' => 'x y z'])->assertSessionHasErrors('payer');
        $this->actingAs($this->agent)->post($url, ['payer' => 'shared', 'reason' => 'Both contributed'])->assertSessionHasErrors('who_pays');
        $this->actingAs($this->agent)->post($url, ['payer' => 'shared', 'tenant_percent' => 100, 'reason' => 'Both contributed'])->assertSessionHasErrors('tenant_percent');
        $this->assertSame(0, RentalPayerRuling::withoutGlobalScopes()->count());

        $this->actingAs($this->agent)->post($url, ['payer' => 'shared', 'tenant_percent' => 40, 'reason' => 'Both contributed'])->assertSessionHasNoErrors();
        $r = RentalPayerRuling::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([40, 60], [(int) $r->tenant_percent, (int) $r->owner_percent]);
        $this->assertSame('Shared (tenant 40%, owner 60%)', $r->summary());
        $this->assertSame('shared', $wo->fresh()->who_pays);
        $this->assertSame('tenant', $r->suggested_payer);
    }

    public function test_a_newer_ruling_replaces_the_current_one_and_the_old_one_stays_unedited(): void
    {
        $wo = $this->found($this->workOrder());
        $this->svc()->rule($wo, $this->agent, 'tenant', null, 'A toy was in the drain', UploadedFile::fake()->create('photo.pdf', 20, 'application/pdf'));
        $first = RentalPayerRuling::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($first->evidence_path);
        Storage::disk('local')->assertExists($first->evidence_path);

        $this->svc()->rule($wo->fresh(), $this->agent, 'owner', null, 'On review the pipe was old');
        $this->assertSame(2, RentalPayerRuling::withoutGlobalScopes()->count());
        $first->refresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertSame('tenant', $first->payer);
        $this->assertSame('A toy was in the drain', $first->reason);
        $current = $this->svc()->currentRuling($wo->fresh());
        $this->assertSame('owner', $current->payer);
        $this->assertSame('owner', $wo->fresh()->who_pays);
        $this->assertSame($current->id, (int) $wo->fresh()->who_pays_ruling_id);
        $this->assertSame(2, $this->svc()->history($wo->fresh())->count());

        $this->actingAs($this->agent)->get(route('corex.rental-work-orders.who-pays.evidence', [$wo, $first->id]))->assertOk();
        $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->assertSee('On review the pipe was old')->assertSee('History (2 rulings)');
    }

    public function test_the_fault_page_shows_the_ruling_read_only_to_both_lease_agents(): void
    {
        $fault = $this->fault();
        $wo = $this->found($this->workOrder($fault));
        $this->svc()->rule($wo, $this->agent, 'tenant', null, 'A toy was in the drain');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-who-pays-summary', $html);
        $this->assertStringContainsString('A toy car was stuck in the pipe', $html);
        $this->assertStringContainsString('Ruling:', $html);
        $this->assertStringContainsString('Tenant', $html);
    }

    public function test_a_ruling_charges_nothing_and_leaves_paid_by_alone(): void
    {
        $wo = $this->found($this->workOrder());
        $before = ['paid_by' => $wo->paid_by, 'cost' => $wo->cost_amount, 'invoices' => $wo->invoices()->count()];
        $this->svc()->rule($wo, $this->agent, 'tenant', null, 'Toy in drain');
        $after = $wo->fresh();
        $this->assertSame($before, ['paid_by' => $after->paid_by, 'cost' => $after->cost_amount, 'invoices' => $after->invoices()->count()]);
    }

    // ── the owner and the tenant are told ─────────────────────────────────────

    public function test_the_owner_and_the_tenant_are_each_emailed_the_ruling_in_their_own_words(): void
    {
        $wo = $this->found($this->workOrder());
        $this->svc()->rule($wo, $this->agent, 'tenant', null, 'A toy was in the drain');

        Mail::assertQueued(RentalPayerRulingMail::class, 2);
        Mail::assertQueued(RentalPayerRulingMail::class, fn ($m) => $m->audience === 'owner' && $m->hasTo($this->landlord->email) && $m->words['headline'] === 'The tenant will pay for this.'
            && str_contains($m->words['closing'], 'in touch about payment'));
        Mail::assertQueued(RentalPayerRulingMail::class, fn ($m) => $m->audience === 'tenant' && $m->hasTo($this->tenant->email) && $m->words['headline'] === 'You are asked to pay for this.');
        $r = RentalPayerRuling::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($r->owner_told_at);
        $this->assertNotNull($r->tenant_told_at);

        // the mail never carries or links the evidence, and a replacement says it replaces
        Mail::fake();
        $this->svc()->rule($wo->fresh(), $this->agent, 'shared', 25, 'On review');
        Mail::assertQueued(RentalPayerRulingMail::class, fn ($m) => $m->replacesEarlier && $m->audience === 'tenant' && $m->words['headline'] === 'The cost is shared: the owner pays 75% and you pay 25%.');
        Mail::assertQueued(RentalPayerRulingMail::class, fn ($m) => $m->audience === 'owner' && $m->words['headline'] === 'The cost is shared: you pay 75% and the tenant pays 25%.');
    }

    public function test_the_portal_payloads_carry_the_ruling_for_each_audience(): void
    {
        $fault = $this->fault();
        $wo = $this->found($this->workOrder($fault));
        $this->assertNull($this->svc()->portalPayload($fault->fresh(), 'tenant'));
        $this->svc()->rule($wo, $this->agent, 'owner', null, 'Roots are the owner\'s');

        $tenant = $this->svc()->portalPayload($fault->fresh(), 'tenant');
        $owner = $this->svc()->portalPayload($fault->fresh(), 'owner');
        $this->assertSame('The owner pays for this.', $tenant['headline']);
        $this->assertSame('You will pay for this.', $owner['headline']);
        $this->assertSame('A toy car was stuck in the pipe', $tenant['found']);
        $this->assertSame('Foreign object in the drain', $owner['cause']);
        $this->assertArrayNotHasKey('evidence_path', $tenant);
    }

    // ── lists and exports ─────────────────────────────────────────────────────

    public function test_the_work_order_list_filters_and_exports_cause_and_who_pays(): void
    {
        $a = $this->found($this->workOrder(null, ['title' => 'Drain job A']), 'Roots in the pipe');
        $this->svc()->rule($a, $this->agent, 'owner', null, 'Roots');
        $b = $this->found($this->workOrder(null, ['title' => 'Drain job B']), 'Foreign object in the drain');
        $this->svc()->rule($b, $this->agent, 'tenant', null, 'Toy');
        $this->workOrder(null, ['title' => 'Drain job C']);

        $list = fn (array $q) => $this->actingAs($this->agent)->get(route('corex.rental-work-orders.index', $q))->assertOk()->getContent();
        $html = $list([]);
        $this->assertStringContainsString('Who pays (ruling)', $html);
        $this->assertStringContainsString('Drain job A', $html);

        $html = $list(['who_pays' => 'tenant']);
        $this->assertStringContainsString('Drain job B', $html);
        $this->assertStringNotContainsString('Drain job A', $html);
        $this->assertStringNotContainsString('Drain job C', $html);
        $html = $list(['who_pays' => 'none']);
        $this->assertStringContainsString('Drain job C', $html);
        $this->assertStringNotContainsString('Drain job B', $html);
        $html = $list(['cause' => 'roots']);
        $this->assertStringContainsString('Drain job A', $html);
        $this->assertStringNotContainsString('Drain job B', $html);

        $csv = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.export', ['format' => 'csv', 'who_pays' => 'owner']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Cause,"Who pays (ruling)"', $csv);
        $this->assertStringContainsString('Drain job A', $csv);
        $this->assertStringContainsString('Roots in the pipe', $csv);
        $this->assertStringNotContainsString('Drain job B', $csv);
    }
}
