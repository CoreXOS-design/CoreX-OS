<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalAuthorisationToOwnerMail;
use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Services\Rentals\RentalFaultProgressService;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalAuthorisation;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\FaultInspectionService;
use App\Models\RentalPropertyFaultSetting;
use App\Services\Rentals\RentalOwnerFaultSettings;
use App\Services\Rentals\RentalAuthorisationService;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22, stage 1: nothing is automatic. Every job carries an AUTHORISATION RECORD at Decision 1 (who goes
 * to look); the agent sends someone to inspect only when the visit costs the owner nothing; otherwise the owner authorises on their link, or the agent records the
 * owner's decision; the owner is told in one sentence, by email, at once. The tenant's ticks and the agent's confirmation sit at the top of the fault page.
 */
final class AuthorityFlowTest extends TestCase
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
    /** @var array<int, array{0:?string,1:object}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $self = $this;
        $this->app->instance(RentalMailDispatcher::class, new class($self) extends RentalMailDispatcher {
            public function __construct(private $t) {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void { $this->t->recordMail($recipientEmail, $mail); }
        });
        $this->agency = Agency::create(['name' => 'Authority Agency', 'slug' => 'au-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'AU Unit', 'status' => 'active', 'listing_type' => 'rental', 'rental_no_approval_spend_threshold' => 1500]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
    }

    public function recordMail(?string $to, object $mail): void
    {
        $this->sent[] = [$to, $mail];
    }

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    private function fault(bool $saveVersion = true, array $over = []): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'TENANT WORDS: tap', 'description' => 'TENANT ORIGINAL: the kitchen tap drips all night', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ], $over));
        $photo = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/au/tap.jpg', 'uploaded_by_user_id' => null]);
        if ($saveVersion) {
            $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'Plumber suggested', 'owner_photo_ids' => [$photo->id]], $this->agent);
        }

        return $fault->fresh();
    }

    private function supplier(string $name, string $mode = 'unknown', ?float $amount = null, array $over = []): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)) . '@example.invalid', 'is_active' => true, 'specialty' => 'other',
            'callout_fee_mode' => $mode, 'callout_fee_amount' => $amount,
        ], $over));
    }

    /** The OWNER's call-out permission for this property, as the owner-settings build stores it (confirmed by the owner = standing authority). */
    private function allowance(?float $amount, string $status = RentalPropertyFaultSetting::STATUS_CONFIRMED): void
    {
        RentalPropertyFaultSetting::withoutGlobalScopes()->updateOrCreate(['property_id' => $this->property->id], [
            'agency_id' => $this->agency->id, 'status' => $status, 'repair_limit' => 1500, 'emergency_limit' => null,
            'callout_mode' => $amount === null ? 'no' : 'yes', 'callout_limit' => $amount, 'version' => 1,
        ]);
    }

    private function inspect(RentalFaultReport $f, AgencyServiceProvider $p)
    {
        return $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-inspect', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id]);
    }

    // ── the owner's settings are read through ONE resolver ───────────────────

    public function test_the_fault_flow_reads_the_owners_permission_only_through_the_owner_settings_resolver(): void
    {
        // nothing recorded -> not set -> ask the owner
        $this->assertNull(RentalOwnerFaultSettings::for($this->property)->calloutAllowedUpTo());
        $this->allowance(1000.0);
        $this->assertSame(1000.0, RentalOwnerFaultSettings::for($this->property)->calloutAllowedUpTo());
        // an owner who does not agree (disputed) gives no authority at all
        $this->allowance(1000.0, RentalPropertyFaultSetting::STATUS_DISPUTED);
        $this->assertNull(RentalOwnerFaultSettings::for($this->property)->calloutAllowedUpTo());
        $f = $this->fault();
        $this->inspect($f, $this->supplier('Fee Plumbing', 'yes', 750))->assertSessionHasErrors('rental_fault_report');
    }

    // ── the call-out rule ────────────────────────────────────────────────────

    public function test_the_call_out_rule_for_every_fee_state_and_boundary(): void
    {
        $svc = app(FaultInspectionService::class);
        $none = $svc->evaluate($this->property, 'Sam Plumbing', 'no', null);
        $this->assertTrue($none['allowed']);
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE, $none['basis']);

        // a fee and no owner permission set -> the owner must authorise first (Johan's line, word for word)
        $fee = $svc->evaluate($this->property, 'Sam Plumbing', 'yes', 750.0);
        $this->assertFalse($fee['allowed']);
        $this->assertSame('Sam Plumbing charges a call-out fee of R750. The owner must authorise this first.', $fee['line']);

        // a fee NOT KNOWN is treated as a fee, and can never be covered
        $unknown = $svc->evaluate($this->property, 'Sam Plumbing', 'unknown', null);
        $this->assertFalse($unknown['allowed']);
        $this->assertStringContainsString("We don't know whether Sam Plumbing charges a call-out fee. The owner must authorise this first", $unknown['line']);

        // the owner's permission covers it (boundary: equal is covered, one rand over is not)
        $this->allowance(1000.0);
        $this->assertTrue($svc->evaluate($this->property, 'Sam', 'yes', 750.0)['allowed']);
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING, $svc->evaluate($this->property, 'Sam', 'yes', 1000.0)['basis']);
        $this->assertFalse($svc->evaluate($this->property, 'Sam', 'yes', 1001.0)['allowed']);
        // no tolerance: one cent over the owner's call-out permission is over (Johan, 13 Oct 2026: a hard lock)
        $this->assertTrue($svc->evaluate($this->property, 'Sam', 'yes', 1000.00)['allowed']);
        $over = $svc->evaluate($this->property, 'Sam', 'yes', 1000.01);
        $this->assertFalse($over['allowed']);
        $this->assertStringContainsString('The owner must authorise this first.', $over['line']);
        $this->assertFalse($svc->evaluate($this->property, 'Sam', 'unknown', null)['allowed'], 'even with a permission, a fee that is not known cannot be covered');
    }

    // ── Send <name> to inspect ───────────────────────────────────────────────

    public function test_sending_a_no_fee_contractor_writes_the_record_makes_the_work_order_and_tells_the_owner(): void
    {
        $f = $this->fault();
        $p = $this->supplier('Free Visit Plumbing', 'no');
        $this->inspect($f, $p)->assertRedirect();
        $f = $f->fresh();

        $this->assertNotNull($f->rental_work_order_id, 'the work order exists');
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->sole();
        $this->assertSame('inspect', $row->point);
        $this->assertSame('authorised', $row->outcome);
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE, $row->basis);
        $this->assertSame($this->agent->id, $row->by_user_id);
        $this->assertSame('Free Visit Plumbing', $row->inspector_label);
        $this->assertSame(RentalFaultReport::statusWord('under_review'), $row->stage, 'where the job stood, in the screen\'s own words (never the raw status)');
        $this->assertNotNull($row->told_at);
        $this->assertNotNull($row->owner_told_at, 'the owner was told');
        $this->assertSame(RentalFaultReport::ARRANGED_INSPECT_NO_FEE, $f->arranged_basis);
        $this->assertSame(RentalFaultReport::APPROVAL_NOT_REQUIRED, $f->owner_approval_status, 'nothing is put to the owner to approve');

        // the contractor got the job email + their link (existing round 2 behaviour)
        $this->assertNotEmpty(array_filter($this->sent, fn ($m) => $m[0] === $p->email), 'the contractor is told');
        // the owner was told at once: the sentence, who, why
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, 1);
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => $m->hasTo($this->landlord->email)
            && str_contains($m->sentence, 'Your agent Agnes Agent sent Free Visit Plumbing to look at this and give a quote.') && str_contains($m->sentence, 'charges no call-out fee'));
        $this->assertContains('authorisation_recorded', $f->updates()->pluck('update_type')->all());
    }

    public function test_a_contractor_with_a_fee_or_an_unknown_fee_cannot_be_sent_without_the_owner(): void
    {
        $f = $this->fault();
        $fee = $this->supplier('Fee Plumbing', 'yes', 750);
        $unknown = $this->supplier('Mystery Plumbing');

        $this->inspect($f, $fee)->assertSessionHasErrors(['rental_fault_report' => 'Fee Plumbing charges a call-out fee of R750. The owner must authorise this first.']);
        $this->inspect($f, $unknown)->assertSessionHasErrors('rental_fault_report');
        $f = $f->fresh();
        $this->assertNull($f->rental_work_order_id, 'no work order');
        $this->assertSame(0, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count(), 'no record');
        $this->assertEmpty($this->sent, 'nobody was emailed');
        Mail::assertNothingQueued();
    }

    public function test_the_owners_permission_covers_a_fee_inside_it_and_the_record_says_so(): void
    {
        $this->allowance(1000.0);
        $f = $this->fault();
        $p = $this->supplier('Covered Plumbing', 'yes', 750);
        $this->inspect($f, $p)->assertRedirect();
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->sole();
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_CALLOUT_SETTING, $row->basis);
        $this->assertEquals(750.0, (float) $row->callout_fee);
        $this->assertEquals(1000.0, (float) $row->limit_amount);
        $this->assertSame(RentalFaultReport::ARRANGED_INSPECT_CALLOUT, $f->fresh()->arranged_basis);
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => str_contains($m->sentence, 'under your call-out permission of R1,000.00. The call-out fee is R750.00.'));

        // over the permission: refused
        $g = $this->fault();
        $this->inspect($g, $this->supplier('Dear Plumbing', 'yes', 1200))->assertSessionHasErrors('rental_fault_report');
        $this->assertNull($g->fresh()->rental_work_order_id);
    }

    public function test_our_own_crew_has_no_call_out_fee_and_gets_a_job_card(): void
    {
        $f = $this->fault();
        $crew = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Team Maintenance', 'is_active' => true]);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-inspect', $f), ['assignment_type' => 'internal', 'rental_crew_id' => $crew->id])->assertRedirect();
        $f = $f->fresh();
        $this->assertNotNull($f->rental_work_order_id);
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->sole();
        $this->assertSame(RentalAuthorisation::BASIS_AGENT_NO_CALLOUT_FEE, $row->basis);
        $this->assertSame('our own maintenance crew', $row->inspector_label);
        $this->assertSame(1, \App\Models\RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $f->rental_work_order_id)->count());
    }

    public function test_the_owner_version_must_be_saved_first_and_there_is_no_work_order_without_authority(): void
    {
        $f = $this->fault(false);
        $p = $this->supplier('Free Visit Plumbing', 'no');
        $this->inspect($f, $p)->assertSessionHasErrors(['rental_fault_report' => 'Save what the owner will see first - it is what the owner will read.']);
        $this->assertNull($f->fresh()->rental_work_order_id);

        // the ordinary "Create work order" form cannot make one for a fault nobody has authorised
        $g = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $g), ['assignment_type' => 'internal'])
            ->assertSessionHasErrors(['rental_fault_report' => 'No one has been authorised to look at this yet. Send a contractor to inspect (when the visit costs the owner nothing), or have the owner authorise it.']);
        $this->assertNull($g->fresh()->rental_work_order_id);
        $this->assertNotNull($g->workOrderBlockReason());
        $this->assertNull($g->workOrderBlockReason(true), 'only the Decision 1 step that writes the record may pass');
    }

    // ── Send to owner to authorise ───────────────────────────────────────────

    public function test_send_to_owner_carries_the_proposed_contractor_and_fee_to_the_owner(): void
    {
        $f = $this->fault();
        $p = $this->supplier('Fee Plumbing', 'yes', 750, []);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner-to-authorise', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id])->assertRedirect();
        $f = $f->fresh();
        $this->assertSame(RentalFaultReport::STATUS_AWAITING_APPROVAL, $f->status);
        $this->assertNotNull($f->sent_to_owner_at);
        $this->assertSame($p->id, $f->proposed_supplier_id);
        $this->assertNull($f->rental_work_order_id, 'nothing is sent to the contractor yet');
        $this->assertSame(0, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count(), 'no authorisation until the owner (or the agent for them) decides');
        $this->assertContains('sent_to_owner_to_authorise', $f->updates()->pluck('update_type')->all());

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->assertOk()->json('fault_report');
        $this->assertTrue($show['awaiting_decision']);
        $this->assertSame('Fee Plumbing', $show['proposed']['name']);
        $this->assertSame('Call-out fee: R750', $show['proposed']['callout'], "the owner sees the fee BEFORE they choose");
        $f2 = $this->fault();
        $free = $this->supplier('Free Plumbing', 'no');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner-to-authorise', $f2), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $free->id])->assertRedirect();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->assertSame('No call-out fee.', $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f2->id}")->json('fault_report.proposed.callout'));

        // a second press is refused
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner-to-authorise', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id])
            ->assertSessionHasErrors('rental_fault_report');
    }

    // ── the owner on their link / the owner's own request ────────────────────

    public function test_the_owners_press_on_their_link_leaves_an_owner_link_record(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->sole();
        $this->assertSame(RentalAuthorisation::BASIS_OWNER_LINK, $row->basis);
        $this->assertSame('authorised', $row->outcome);
        $this->assertSame($this->landlord->id, $row->by_contact_id);
        $this->assertSame($this->landlord->id, $row->owner_contact_id);
        $this->assertSame(RentalFaultReport::statusWord('awaiting_approval'), $row->stage);
        $this->assertNull($row->by_user_id);
        $this->assertTrue($f->fresh()->hasInspectionAuthority());
        // the owner's own press is NOT presented as "your agent recorded" - they pressed it
        $this->assertNull($this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report.authorisation'));
    }

    public function test_the_owners_own_work_request_is_their_decision_one_authority(): void
    {
        $svc = app(\App\Services\Rentals\RentalFaultReportService::class);
        $fault = $svc->reportOwnerRequest($this->property, $this->landlord, ['lease_id' => $this->lease->id, 'rental_fault_type_id' => $this->faultType->id, 'title' => 'Leaking tap', 'description' => 'Please fix the tap'], 'Use your usual plumber');
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $fault->id)->sole();
        $this->assertSame(RentalAuthorisation::BASIS_OWNER_LINK, $row->basis);
        $this->assertSame('authorised', $row->outcome);
        $this->assertTrue($fault->hasInspectionAuthority());
    }

    // ── Record the owner's decision (Decision 1) ─────────────────────────────

    private function record(RentalFaultReport $f, array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $f), array_merge([
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call', 'told_at' => now()->subHour()->format('Y-m-d\TH:i'), 'note' => 'Lenny phoned: send a plumber to look.',
        ], $over));
    }

    public function test_recording_the_owners_decision_saves_it_under_the_agents_name_and_tells_the_owner_the_same_sentence(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        $this->record($f, ['proof' => UploadedFile::fake()->image('whatsapp.png')])->assertRedirect()->assertSessionHasNoErrors();
        $f = $f->fresh();

        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->status);
        $this->assertSame('agency_appoints', $f->approval_route);
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->sole();
        $this->assertSame(RentalAuthorisation::BASIS_OWNER_INSTRUCTION, $row->basis);
        $this->assertSame('phone_call', $row->told_via);
        $this->assertSame($this->agent->id, $row->by_user_id);
        $this->assertSame($this->landlord->id, $row->owner_contact_id);
        $this->assertSame('Lenny phoned: send a plumber to look.', $row->note);
        $this->assertNotNull($row->proof_path, 'the optional proof was kept');
        $this->assertNotNull($row->owner_told_at);

        $sentence = app(RentalAuthorisationService::class)->sentenceFor($row->load(['byUser']));
        $this->assertMatchesRegularExpression('/^Authorised by your agent Agnes Agent on your instruction, given by phone call on \d{1,2} \w{3} \d{4} \d{2}:\d{2}\.$/', $sentence);
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => $m->hasTo($this->landlord->email) && $m->sentence === $sentence && $m->note === 'Lenny phoned: send a plumber to look.');

        // the owner's portal shows the same sentence, the note and a way to query it
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $auth = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->assertOk()->json('fault_report.authorisation');
        $this->assertSame($sentence, $auth['sentence']);
        $this->assertSame('Lenny phoned: send a plumber to look.', $auth['note']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/query", ['note' => 'I did not say that'])->assertCreated()->assertJsonPath('authorisation.queries.0.note', fn ($n) => str_contains($n, 'I did not say that'));
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->fresh()->status, 'a query stops nothing');
    }

    public function test_the_record_has_its_required_fields_and_a_declined_one_closes_the_fault(): void
    {
        $f = $this->fault();
        $this->record($f, ['note' => ''])->assertSessionHasErrors('note');
        $this->record($f, ['told_via' => 'carrier_pigeon'])->assertSessionHasErrors('told_via');
        $this->record($f, ['owner_contact_id' => 999999])->assertSessionHasErrors('owner_decision');
        $this->record($f, ['told_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('owner_decision');
        $this->assertSame(0, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count());

        $this->record($f, ['decision' => 'declined', 'note' => 'Lenny does not want it done'])->assertSessionHasNoErrors();
        $f = $f->fresh();
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $f->status);
        $this->assertSame('declined', RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->sole()->outcome);
    }

    public function test_a_captured_decision_is_replaced_by_a_newer_one_until_a_work_order_exists_and_the_history_stays(): void
    {
        $f = $this->fault();
        $this->record($f, ['decision' => 'declined', 'note' => 'No thanks'])->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $f->fresh()->status);

        // the owner changed their mind
        $this->record($f, ['note' => 'Lenny called back: yes, go ahead'])->assertSessionHasNoErrors();
        $f = $f->fresh();
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->status);
        $this->assertNull($f->outcome, 'the automatic "owner declined" outcome was taken back');
        $rows = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->orderBy('id')->get();
        $this->assertCount(2, $rows, 'full history');
        $this->assertNotNull($rows[0]->superseded_at);
        $this->assertSame($rows[1]->id, $rows[0]->superseded_by_id);
        $this->assertNull($rows[1]->superseded_at);
        $this->assertContains('authorisation_superseded', $f->updates()->pluck('update_type')->all());

        // once a work order exists it is locked
        app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($f, $this->agent, ['assignment_type' => 'internal']);
        $this->record($f->fresh(), ['note' => 'Changed my mind again'])->assertSessionHasErrors('owner_decision');
        $this->assertCount(2, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->get());
    }

    public function test_an_agent_cannot_replace_a_decision_the_owner_pressed_on_their_own_link(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/decision", ['decision' => 'decline', 'note' => 'No'])->assertOk();
        $this->record($f->fresh())->assertSessionHasErrors(['owner_decision' => 'The owner has already decided on their own link.']);
    }

    public function test_the_record_is_scoped_to_the_agency_and_needs_the_permission(): void
    {
        $f = $this->fault();
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'ot-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main', 'code' => 'O-' . $otherAgency->id, 'is_active' => true]);
        $stranger = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $this->actingAs($stranger)->post(route('corex.rental-fault-reports.owner-decision.store', $f), ['owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'email', 'note' => 'x'])->assertStatus(404);
        $this->actingAs($stranger)->post(route('corex.rental-fault-reports.send-to-inspect', $f), ['assignment_type' => 'internal'])->assertStatus(404);
        $this->actingAs($stranger)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'email', 'at' => now()->format('Y-m-d\TH:i'), 'note' => 'x'])->assertStatus(404);
        $this->assertSame(0, RentalAuthorisation::withoutGlobalScopes()->count());
    }

    // ── the top of the agent's fault page ────────────────────────────────────

    public function test_the_tenant_picture_shows_the_report_the_ticks_and_the_agents_confirmation_in_order(): void
    {
        $f = $this->fault(true, ['tenant_self_checks' => [['text' => 'Close the main valve', 'ticked' => true], ['text' => 'Check the washer', 'ticked' => false]]]);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'whatsapp', 'at' => now()->subMinutes(30)->format('Y-m-d\TH:i'), 'note' => 'Tina says it still drips after the valve is shut'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $f = $f->fresh();
        $this->assertSame('whatsapp', $f->tenant_confirmed_via);
        $this->assertSame($this->agent->id, $f->tenant_confirmed_by_user_id);
        $this->assertContains('tenant_confirmed', $f->updates()->pluck('update_type')->all());

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $iReported = strpos($html, 'What the tenant reported');
        $iChecked = strpos($html, 'What the tenant says they checked');
        $iConfirmed = strpos($html, 'Confirmed with the tenant');
        $this->assertNotFalse($iReported);
        $this->assertTrue($iReported < $iChecked && $iChecked < $iConfirmed, 'the blocks are in the ruled order');
        $this->assertStringContainsString('TENANT ORIGINAL: the kitchen tap drips all night', $html);
        $this->assertStringContainsString('data-self-check="ticked"', $html);
        $this->assertStringContainsString('data-self-check="not-ticked"', $html);
        $this->assertStringContainsString('By WhatsApp on', $html);
        $this->assertStringContainsString('Tina says it still drips after the valve is shut', $html);

        // the tenant's original is read-only here: no edit form carries it
        $this->assertStringNotContainsString('name="description"', $html);
    }

    public function test_confirmation_needs_how_when_and_a_note_and_a_newer_one_keeps_the_old_in_the_history(): void
    {
        $f = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'telepathy', 'at' => now()->format('Y-m-d\TH:i'), 'note' => 'x'])->assertSessionHasErrors('via');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'email', 'at' => now()->format('Y-m-d\TH:i'), 'note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'email', 'at' => now()->addDay()->format('Y-m-d\TH:i'), 'note' => 'x'])->assertSessionHasErrors('tenant_confirmation');
        $this->assertNull($f->fresh()->tenant_confirmed_via);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'phone_call', 'at' => now()->subHour()->format('Y-m-d\TH:i'), 'note' => 'First call'])->assertSessionHasNoErrors();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'in_person', 'at' => now()->format('Y-m-d\TH:i'), 'note' => 'Second visit'])->assertSessionHasNoErrors();
        $this->assertSame('in_person', $f->fresh()->tenant_confirmed_via);
        $notes = $f->updates()->where('update_type', 'tenant_confirmed')->pluck('note')->implode(' | ');
        $this->assertStringContainsString('First call', $notes);
        $this->assertStringContainsString('Second visit', $notes);
    }

    public function test_the_fault_page_has_decision_one_with_two_buttons_and_no_approve_within_limit(): void
    {
        $f = $this->fault();
        $this->supplier('Free Visit Plumbing', 'no');
        $this->supplier('Fee Plumbing', 'yes', 750);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();

        $this->assertStringContainsString('data-decision-one', $html);
        $this->assertStringContainsString('data-send-to-inspect', $html);
        $this->assertStringContainsString('data-send-to-owner-to-authorise', $html);
        $this->assertStringContainsString('Send to owner to authorise', $html);
        $this->assertStringContainsString("data-owner-decision-open", $html);
        $this->assertStringContainsString('Our own crew', $html);
        $this->assertStringContainsString('inspect_allowed', $html, 'the page knows which contractors may be sent on the agent\'s own authority');
        $this->assertStringContainsString('Fee Plumbing charges a call-out fee of R750. The owner must authorise this first.', $html);
        $this->assertStringNotContainsString('Approve within agent limit', $html, 'approval against a limit exists only at Decision 2');
        $this->assertStringNotContainsString('data-go-ahead-toggle', $html);
        $this->assertStringNotContainsString('data-early-work-order', $html);
    }

    // ── the tenant's ticks reach the agent ───────────────────────────────────

    public function test_the_tenant_portal_stores_the_ticks_cleaned(): void
    {
        $cu = $this->login($this->tenant);
        Sanctum::actingAs($cu, ['client']);
        $payload = json_encode([['text' => 'Close the valve', 'ticked' => true], ['text' => '<b>Check</b> the washer', 'ticked' => false], ['text' => '', 'ticked' => true], 'junk']);
        $res = $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Tap', 'description' => 'Drips', 'self_checks' => $payload,
        ]);
        $res->assertStatus(201);
        $fault = RentalFaultReport::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([['text' => 'Close the valve', 'ticked' => true], ['text' => 'Check the washer', 'ticked' => false]], $fault->tenant_self_checks);
    }

    // ── the fault page, in working order (Johan's walk of QA1, 13 Oct) ───────

    public function test_the_fault_page_is_in_working_order_top_to_bottom_and_speaks_plain_words(): void
    {
        $f = $this->fault(false);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();

        $tenant = strpos($html, 'data-tenant-picture');
        $owner = strpos($html, 'id="owner-version-card"');
        $who = strpos($html, 'data-decision-one');
        $this->assertTrue($tenant !== false && $owner !== false && $who !== false);
        $this->assertLessThan($owner, $tenant, 'what the tenant reported/checked/confirmed comes first');
        $this->assertLessThan($who, $owner, '"What the owner will see" comes BEFORE "Who goes to look first?" - no scrolling back up to save');
        $this->assertStringContainsString('What the owner will see', $html);
        $this->assertStringContainsString('Who goes to look first?', $html);
        $this->assertStringContainsString('Nothing is sent until you press a button.', $html);
        $this->assertStringNotContainsString('Decision 1', $html);
        $this->assertStringNotContainsString('Decision 2', $html);

        // ONE way to the owner; ONE save button on the owner version
        $this->assertStringNotContainsString('Save and send to owner', $html);
        $this->assertStringNotContainsString('Send saved version to owner', $html);
        $this->assertStringNotContainsString('Save draft', $html);
        $this->assertSame(1, substr_count($html, 'data-owner-version-save'));
        $this->assertStringContainsString('Send to owner to authorise', $html);

        // the loose detail lines that repeated "What the tenant reported" are gone; Cancel report is in the page header
        foreach (['Channel:</span>', 'Reported at:</span>', 'Captured by:</span>', 'Reported by:</span>'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
        $this->assertLessThan($tenant, strpos($html, 'data-cancel-report'), 'Cancel report sits in the header actions');

        // plain wording for a fault with no ticks
        $this->assertStringContainsString('No self-checks were shown for this fault.', $html);
        $this->assertStringNotContainsString('or reported before they existed', $html);
    }

    public function test_send_to_owner_to_authorise_works_with_nobody_picked_and_the_press_saves_what_the_owner_will_see(): void
    {
        $f = $this->fault(false);
        $this->assertNull($f->owner_version_saved_at);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner-to-authorise', $f), [
            'owner_title' => 'Tap leaking in the kitchen', 'owner_description' => 'It drips all night.', 'owner_agent_note' => 'A plumber can fix it this week.',
        ])->assertSessionHasNoErrors();

        $f = $f->fresh();
        $this->assertNotNull($f->owner_version_saved_at, 'the press saved the draft');
        $this->assertSame('Tap leaking in the kitchen', $f->owner_title);
        $this->assertNotNull($f->sent_to_owner_at, 'and the owner was asked');
        $this->assertNull($f->proposed_supplier_id, 'nobody proposed - the owner is simply asked "send someone to look and quote?"');
        $this->assertSame(RentalFaultReport::STATUS_AWAITING_APPROVAL, $f->status);
    }

    public function test_send_to_inspect_also_saves_the_draft_but_refuses_an_empty_title(): void
    {
        $p = $this->supplier('Free Visit Plumbing', 'no');
        $bad = $this->fault(false);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-inspect', $bad), [
            'assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id, 'owner_title' => '',
        ])->assertSessionHasErrors('owner_title');
        $this->assertNull($bad->fresh()->rental_work_order_id, 'nothing is sent when the draft is not valid');

        $f = $this->fault(false);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-inspect', $f), [
            'assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id, 'owner_title' => 'Tap leaking', 'owner_description' => 'Drips.',
        ])->assertSessionHasNoErrors();
        $f = $f->fresh();
        $this->assertNotNull($f->rental_work_order_id);
        $this->assertSame('Tap leaking', $f->owner_title);
    }

    public function test_a_contractor_with_no_email_or_mobile_cannot_be_sent_the_job(): void
    {
        $ghost = $this->supplier('Ghost Plumbing', 'no', null, ['email' => null, 'phone' => null]);
        $f = $this->fault();
        $svc = app(FaultInspectionService::class);
        $map = $svc->noticeMapFor($f, app(\App\Services\Rentals\RentalFaultContractorService::class)->pickerFor($f));
        $this->assertFalse($map[(string) $ghost->id]['inspect_allowed']);
        $this->assertSame('No email or mobile on file - they cannot be sent the job.', $map[(string) $ghost->id]['text']);

        $this->inspect($f, $ghost)->assertSessionHasErrors('rental_fault_report');
        $this->assertNull($f->fresh()->rental_work_order_id);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->getContent();
        $this->assertStringContainsString('No email or mobile on file - they cannot be sent the job', $html);
        $this->assertStringNotContainsString('NO CONTACT DETAILS', $html);
    }

    // ── cc4's independent walk of QA1: the staff fault page said things twice ─

    public function test_the_fault_page_does_not_repeat_itself_and_the_header_buttons_share_one_size(): void
    {
        $f = $this->fault(false, ['tenant_self_checks' => [
            ['text' => "Your agent hasn't recorded where the main water valve is — check near the meter/boundary.", 'ticked' => true],
            ['text' => 'Catch the water in a bucket', 'ticked' => false],
        ]]);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();

        // the header action row: one class, one size (CSS partial included once)
        $this->assertStringContainsString('data-header-actions', $html);
        $this->assertSame(1, substr_count($html, '[data-header-actions] a.corex-btn-outline'));
        // the tenant's tick is worded for the AGENT, with a link to set the missing place; never a placeholder
        $text = html_entity_decode(strip_tags((string) preg_replace('#<(style|script).*?</\1>#si', '', $html)));
        $this->assertStringContainsString('Main water valve location not recorded on this property', $text);
        $this->assertStringContainsString('data-set-property-field="rental_main_water_valve_location"', $html);
        $this->assertStringNotContainsString("Your agent hasn't recorded", $text);
        $this->assertStringNotContainsString('{{', $text);

        // sent to the owner: "waiting" is said once, the lines that repeated it are gone
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner-to-authorise', $f), ['owner_title' => 'Tap leaking', 'owner_description' => 'x'])->assertSessionHasNoErrors();
        $after = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f->fresh()))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-waiting-for-owner', $after);
        $this->assertStringNotContainsString('Owner approval:', $after);
        $this->assertStringContainsString('data-already-with-owner', $after);
    }

    public function test_saving_the_same_owner_version_again_does_not_write_a_second_history_line(): void
    {
        $f = $this->fault(false);
        $words = ['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'x', 'owner_photo_ids' => []];
        $f->saveOwnerVersion($words, $this->agent);
        $f->fresh()->saveOwnerVersion($words, $this->agent);   // the "Save" press, then a decision button with the same words
        $count = $f->fresh()->updates()->where('update_type', \App\Models\RentalFaultReportUpdate::TYPE_OWNER_VERSION_SAVED)->count();
        $this->assertSame(1, $count);
        $f->fresh()->saveOwnerVersion(array_merge($words, ['owner_title' => 'Kitchen tap leaking badly']), $this->agent);
        $this->assertSame(2, $f->fresh()->updates()->where('update_type', \App\Models\RentalFaultReportUpdate::TYPE_OWNER_VERSION_SAVED)->count(), 'a real change is logged');
    }

    public function test_the_banner_after_send_to_inspect_is_written_for_the_agent_and_a_phone_decision_never_says_verbal_note(): void
    {
        $f = $this->fault();
        $p = $this->supplier('Free Visit Plumbing', 'no');
        $this->inspect($f, $p)->assertRedirect();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f->fresh()))->assertOk()->getContent();
        $text = html_entity_decode(strip_tags((string) preg_replace('#<(style|script).*?</\\1>#si', '', $html)));
        $this->assertMatchesRegularExpression('/The owner was told on \d{1,2} \w{3} \d{4} \d\d:\d\d: Free Visit Plumbing is going to look and quote, no call-out fee\. The owner can stop or query this from their link; you are told if they do\./', $text);
        $this->assertStringNotContainsString('They are informed, not asked', $text);
        $this->assertStringNotContainsString('so the visit costs you nothing', $text, 'the owner\'s email sentence is not pasted onto the agent\'s screen');
        // the record says where the job stood in plain words, not "under_review"
        $this->assertStringContainsString('job was:', $text);
        $this->assertDoesNotMatchRegularExpression('/job was: (under_review|reported|ordered)/', $text);

        // a decision recorded as a phone call is called a phone call everywhere on the staff page
        $g = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $g), [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call', 'told_at' => '2026-10-13T06:20', 'note' => 'Owner phoned',
        ])->assertSessionHasNoErrors();
        $page = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $g->fresh()))->assertOk()->getContent();
        $this->assertStringNotContainsString('verbal note', strtolower($page));
        $this->assertStringContainsString('Captured by the agent (phone call)', $page);
        // and the time typed is the time shown (06:20 stored as 06:20, not two hours early)
        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $g->id)->where('point', 'inspect')->latest('id')->first();
        $this->assertSame('06:20', $row->fresh()->told_at->format('H:i'));
        $this->assertStringContainsString('13 Oct 2026 06:20', app(RentalAuthorisationService::class)->sentenceFor($row->fresh()->load('byUser')));
    }

    // ── 14 Oct 2026 (cc5's walk of QA1, lease 106): the tenant must never be told "Owner approved" when the owner was never asked ──

    public function test_the_tenant_is_never_told_owner_approved_when_the_agent_sends_someone_to_look(): void
    {
        $f = $this->fault();
        $this->inspect($f, $this->supplier('Free Visit Plumbing', 'no'))->assertRedirect();
        $f = $f->fresh();

        $this->assertNotNull($f->arranged_by_agent_at);
        $this->assertNull($f->decision(), 'the owner was neither asked nor recorded');
        $labels = array_column(app(RentalFaultProgressService::class)->forFault($f, 'tenant')['steps'], 'label');
        $this->assertNotContains('Owner approved', $labels);
        $this->assertContains('Someone is coming to look', $labels);
        // the mail the tenant got at the moment the work order was raised says the true thing (it was written BEFORE the fault was marked as arranged)
        Mail::assertNotQueued(RentalTenantStatusChangeMail::class, fn ($m) => str_contains($m->stepLabel, 'Owner approved'));
        Mail::assertQueued(RentalTenantStatusChangeMail::class, fn ($m) => $m->stepLabel === 'Someone is coming to look');
    }

    public function test_a_work_order_with_no_owner_step_at_all_is_approved_by_the_agent_never_the_owner(): void
    {
        $f = $this->fault();
        $this->inspect($f, $this->supplier('Free Visit Plumbing', 'no'))->assertRedirect();
        // the state between "work order raised" and "fault marked as arranged" (what the old order of writes left for an instant)
        $f = $f->fresh();
        $f->forceFill(['arranged_by_agent_at' => null, 'arranged_basis' => null])->save();

        $t = app(RentalFaultProgressService::class)->forFault($f->fresh(), 'tenant');
        $byKey = array_column($t['steps'], 'label', 'key');
        $this->assertSame('Your agent is arranging the repair', $byKey['sent_to_owner']);
        $this->assertSame('Repair approved by your agent', $byKey['owner_decided'], 'approved - but not claimed to be the owner\'s doing');
        foreach (['tenant', 'owner', 'agent'] as $aud) {
            $this->assertNotContains('Owner approved', array_column(app(RentalFaultProgressService::class)->forFault($f->fresh(), $aud)['steps'], 'label'), $aud);
        }
        $this->assertNotContains('You approved', array_column(app(RentalFaultProgressService::class)->forFault($f->fresh(), 'owner')['steps'], 'label'));
    }

    public function test_a_real_owner_decision_still_reads_owner_approved_for_the_tenant(): void
    {
        $f = $this->fault();
        $f->fresh()->requestApproval($this->agent);
        $f->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'portal']);
        $this->assertSame('Owner approved', app(RentalFaultProgressService::class)->forFault($f->fresh(), 'tenant')['current_label']);
    }

    // ── quote-first (Johan, 12 Oct 2026): "owner said send a contractor out for a quote" ──

    public function test_the_agents_option_uses_johans_wording_and_keeps_the_channel_picker(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('Spoke / WhatsApped owner — owner said send a contractor out for a quote', $html);
        $this->assertStringNotContainsString('> Send someone to look and quote</label>', $html, 'the agent form no longer uses the old wording');
        foreach (['value="phone_call"', 'value="whatsapp"', 'value="email"'] as $channel) {
            $this->assertStringContainsString($channel, $html);
        }
    }

    public function test_after_a_captured_quote_instruction_every_audience_reads_waiting_for_the_contractors_quote_until_the_quote_lands(): void
    {
        $f = $this->fault();
        $f->requestApproval($this->agent);
        $this->record($f->fresh(), ['told_via' => 'whatsapp', 'note' => 'Lenny WhatsApped: get the plumber to quote'])->assertSessionHasNoErrors();
        $f = $f->fresh();
        $supplier = $this->supplier('Quote Plumbing');
        $result = app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($f, $this->agent, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id]);
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($result['work_order']->id ?? $f->fresh()->rental_work_order_id);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status, 'a job raised from the owner\'s go-ahead goes straight out to the contractor');
        $auth = app(\App\Services\Rentals\RentalAuthorisationService::class);
        $view = app(\App\Services\Rentals\RentalWorkOrderClientViewService::class);
        $progress = app(RentalFaultProgressService::class);
        $words = "Waiting for the contractor's quote";

        $this->assertFalse($auth->hasCostAuthority($wo), 'authority to send someone to QUOTE is never authority for the cost');
        $this->assertTrue($view->awaitingContractorQuote($wo));
        foreach (['tenant', 'landlord', 'agent'] as $aud) {
            $this->assertSame($words, $view->stageLabel($wo, $aud), $aud);
        }
        foreach (['tenant', 'owner', 'agent'] as $aud) {
            $p = $progress->forFault($f->fresh(), $aud);
            $this->assertSame($words, $p['current_label'], $aud);
        }
        // the owner's own screen keeps saying the instruction was captured by the agent, with the channel
        $payload = $auth->ownerPayload($f->fresh());
        $this->assertStringContainsString('on your instruction, given by WhatsApp', $payload['sentence']);

        // the quote lands: the waiting line goes and the ordinary cost-authority flow takes over (the owner is asked for the amount)
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 4200, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the tap'], $this->agent);
        $wo->selectQuote($quote, $this->agent);
        $this->assertFalse($view->awaitingContractorQuote($wo->fresh()));
        $this->assertNotSame($words, $progress->forFault($f->fresh(), 'owner')['current_label']);
        $this->assertNotSame($words, $view->stageLabel($wo->fresh(), 'landlord'));
    }

    public function test_the_waiting_line_does_not_appear_without_a_quote_instruction_or_for_the_owners_own_contractor(): void
    {
        $view = app(\App\Services\Rentals\RentalWorkOrderClientViewService::class);
        $f = $this->fault();
        $wo = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->agent->id, 'title' => 'Tap', 'description' => 'Tap', 'trade_type' => 'plumbing',
            'status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $this->assertFalse($view->awaitingContractorQuote($wo), 'no look-and-quote authority recorded');
        RentalAuthorisation::withoutGlobalScopes()->forceCreate([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'point' => RentalAuthorisation::POINT_INSPECT, 'outcome' => RentalAuthorisation::OUTCOME_AUTHORISED,
            'basis' => 'owner_instruction', 'told_at' => now(), 'by_user_id' => $this->agent->id, 'created_at' => now(),
        ]);
        $this->assertTrue($view->awaitingContractorQuote($wo->fresh()));
        $wo->forceFill(['assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, 'contractor_name' => 'Owner Bob'])->save();
        $this->assertFalse($view->awaitingContractorQuote($wo->fresh()), 'the owner arranges and pays their own contractor');
    }
}
