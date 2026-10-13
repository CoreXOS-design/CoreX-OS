<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
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
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalFaultProgressService;
use App\Services\Rentals\RentalMailDispatcher;
use App\Support\AppDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 - the owner-facing and contractor-facing defects found in the 13 Oct 2026 end-to-end walk of the deployed QA1
 * (.ai/specs/rentals-faults-work-orders.md section 22.14): the decision-needed email names who and what it costs; the owner's progress and
 * decision screens tell the truth about the route taken; "Yes, send <name>" is one press; the contractor's page asks in the right order;
 * the agency's display name has no dangling "T/A"; and every time a person types is stored in the app timezone (->utc() stored 05:43 as 03:43).
 */
final class OwnerContractorWalkFixesTest extends TestCase
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
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function __construct() {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void {}
        });
        $this->agency = Agency::create(['name' => 'Walk Agency', 'trading_name' => 'Acme Properties T/A', 'slug' => 'wk-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'WK Unit', 'status' => 'active', 'listing_type' => 'rental']);
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

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    private function supplier(string $name, string $mode = 'no', ?float $amount = null): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)) . '@example.invalid', 'is_active' => true, 'specialty' => 'other',
            'callout_fee_mode' => $mode, 'callout_fee_amount' => $amount,
        ]);
    }

    private function fault(): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'TENANT WORDS: tap', 'description' => 'the kitchen tap drips all night', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'x', 'owner_photo_ids' => []], $this->agent);

        return $fault->fresh();
    }

    /** The agent sent the fault to the owner to authorise, proposing $supplier. */
    private function sentToOwner(?AgencyServiceProvider $supplier): RentalFaultReport
    {
        $f = $this->fault();
        $body = $supplier ? ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id] : [];
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner-to-authorise', $f), $body)->assertRedirect();

        return $f->fresh();
    }

    private function inspectSent(): RentalFaultReport
    {
        $f = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-inspect', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier('Free Plumbing')->id]);

        return $f->fresh();
    }

    private function steps(RentalFaultReport $f, string $aud): array
    {
        $p = app(RentalFaultProgressService::class)->forFault($f->fresh(), $aud);

        return array_column($p['steps'], 'label', 'key');
    }

    // ── item 3: the owner's "decision needed" email names who and what it costs ─────────────────

    public function test_the_decision_email_names_the_proposed_contractor_and_the_call_out_fee(): void
    {
        $f = $this->sentToOwner($this->supplier('Fee Plumbing', 'yes', 750.0));

        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, function ($m) {
            $html = $m->render();

            return $m->hasTo($this->landlord->email)
                && str_contains($html, 'Fee Plumbing') && str_contains($html, 'R750')
                && str_contains($html, 'You can say yes, choose someone else, or decline')
                && ! str_contains($html, 'approve or decline it, and tell us who should handle the repair')
                && str_contains($m->envelope()->subject, 'Your agent needs your go-ahead');
        });
    }

    public function test_the_decision_email_says_a_free_visit_costs_nothing_and_copes_with_nobody_proposed(): void
    {
        $this->sentToOwner($this->supplier('Free Plumbing', 'no'));
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, fn ($m) => str_contains($m->render(), 'Free Plumbing charges no call-out fee, so the visit costs you nothing.'));

        Mail::fake();
        $this->sentToOwner(null);
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, fn ($m) => str_contains($m->render(), 'send someone to look at this') && ! str_contains($m->render(), 'data-proposed-line'));
    }

    // ── item 5: "Yes, send <name>" is one press ─────────────────────────────────────────────────

    public function test_yes_send_the_suggested_contractor_is_one_press_and_records_that_contractor(): void
    {
        $sup = $this->supplier('Fee Plumbing', 'yes', 750.0);
        $f = $this->sentToOwner($sup);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $base = "/api/v1/client/rentals/landlord/fault-reports/{$f->id}";

        $show = $this->getJson($base)->assertOk()->json('fault_report');
        $this->assertSame('Fee Plumbing', $show['proposed']['name']);

        $this->postJson("$base/decision", ['decision' => 'approve', 'handled_by' => 'proposed', 'note' => 'Fine, thanks'])->assertOk();

        $f = $f->fresh();
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $f->owner_approval_status);
        $d = $f->decision();
        $this->assertSame($sup->id, (int) $d->agency_service_provider_id, 'the contractor the agent suggested');
        $this->assertSame(RentalFaultReport::ROUTE_AGENCY_APPOINTS, $d->approval_route);
        $this->assertSame('Fine, thanks', $f->ownerComment()['text']);
    }

    public function test_yes_send_with_nobody_suggested_is_refused_in_plain_words(): void
    {
        $f = $this->sentToOwner(null);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}/decision", ['decision' => 'approve', 'handled_by' => 'proposed'])
            ->assertStatus(422)->assertJsonPath('message', 'Your agent has not suggested anyone for this one - please choose who should look and quote.');
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $f->fresh()->owner_approval_status);
    }

    public function test_the_portal_offers_yes_choose_someone_else_and_decline(): void
    {
        $blade = file_get_contents(base_path('resources/views/rentals/portal/_fault-detail.blade.php'));
        foreach (['data-owner-yes', 'data-owner-someone-else', 'data-owner-decline', "'Yes, send '", 'Choose someone else', 'Nothing is spent yet'] as $needle) {
            $this->assertStringContainsString($needle, $blade);
        }
        $this->assertStringNotContainsString("Decided by the owner on the portal link", $blade);
    }

    // ── items 4 + 9: the progress line follows the route actually taken ─────────────────────────

    public function test_before_any_route_there_is_one_neutral_step_not_two_owner_steps(): void
    {
        $f = $this->fault();
        foreach (['tenant', 'owner'] as $aud) {
            $s = $this->steps($f, $aud);
            $this->assertArrayHasKey('sent_to_owner', $s);
            $this->assertArrayNotHasKey('owner_decided', $s, "($aud) no 'Owner approved' / 'You approved' step before anything says the owner is asked");
            $this->assertStringNotContainsString('approv', strtolower(implode(' | ', $s)));
        }
        $this->assertSame('Your agent is arranging who comes to look', $this->steps($f, 'tenant')['sent_to_owner']);
    }

    public function test_when_the_owner_is_asked_the_step_before_their_answer_is_a_question_not_you_approved(): void
    {
        $f = $this->sentToOwner($this->supplier('Free Plumbing'));
        $owner = $this->steps($f, 'owner');
        $this->assertSame('Sent to you for approval', $owner['sent_to_owner']);
        $this->assertSame('Your decision', $owner['owner_decided']);
        $this->assertNotContains('You approved', $owner);
        $this->assertSame('Waiting for the owner', $this->steps($f, 'tenant')['owner_decided']);
    }

    public function test_a_decision_the_agent_wrote_down_has_no_false_sent_to_owner_step(): void
    {
        $f = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $f), [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call', 'note' => 'Owner phoned',
        ])->assertRedirect();

        foreach (['tenant', 'owner', 'agent'] as $aud) {
            $s = $this->steps($f, $aud);
            $this->assertArrayNotHasKey('sent_to_owner', $s, "($aud) the owner was never sent anything");
            $this->assertArrayHasKey('owner_decided', $s);
        }
        $this->assertSame('Recorded on your instruction', $this->steps($f, 'owner')['owner_decided']);
    }

    public function test_the_owner_is_never_told_decided_by_the_owner_on_the_portal_link_and_dates_are_not_repeated(): void
    {
        $f = $this->sentToOwner($this->supplier('Free Plumbing'));
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $base = "/api/v1/client/rentals/landlord/fault-reports/{$f->id}";
        $this->postJson("$base/decision", ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();

        $fr = $this->getJson($base)->json('fault_report');
        $this->assertNull($fr['decision']['how']);
        $this->assertTrue($fr['decision']['via_link']);
        $this->assertStringNotContainsString('portal link', json_encode($fr));
    }

    public function test_an_agent_recorded_decision_reaches_the_owner_once_through_the_sentence_without_a_how_line(): void
    {
        $f = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $f), [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call', 'note' => 'Owner phoned',
        ])->assertRedirect();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $fr = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report');

        $this->assertStringContainsString('Authorised by your agent Agnes Agent on your instruction', $fr['authorisation']['sentence']);
        $this->assertNull($fr['decision']['how'], 'no "Captured by the agent (verbal note)"');
        $this->assertFalse($fr['decision']['via_link']);
        $blade = file_get_contents(base_path('resources/views/rentals/portal/_fault-detail.blade.php'));
        $this->assertStringContainsString('faultDetail.awaiting_decision || (faultDetail.decision && faultDetail.decision.via_link)', $blade, 'the decision section is not repeated for an agent-recorded decision');
    }

    // ── item 6: the contractor's page ───────────────────────────────────────────────────────────

    private function contractorPage(): array
    {
        $f = $this->inspectSent();
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->fresh()->rental_work_order_id);
        $token = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($wo, $this->agent)['raw_token'];

        return [$wo, $token, route('rentals.secure-link.show', $token)];
    }

    public function test_the_contractor_sees_accept_or_decline_and_the_quote_but_not_what_did_you_find_until_they_accept(): void
    {
        [$wo, $token, $show] = $this->contractorPage();

        $this->get($show)->assertOk()
            ->assertSee('This is a visit to look at the problem and give a quote', false)
            ->assertSee('Accept the job')->assertSee('Decline the job')->assertSee('Upload a quote')
            ->assertSee('needed if you do not attach a document', false)->assertSee('needed if you do not write the details', false)
            ->assertDontSee('Mark job done')->assertDontSee('What did you find?')->assertDontSee('Upload after photos');
        // the order on the page: answer, then quote
        $html = $this->get($show)->getContent();
        $this->assertLessThan(strpos($html, 'Upload a quote'), strpos($html, 'Can you take this job?'));

        // the server holds the same order
        $this->from($show)->post("/secure/work-orders/{$token}/mark-done", ['found_text' => 'x'])->assertSessionHasErrors('work_order');
        $this->assertSame('Please accept the job first.', session('errors')->first('work_order'));
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->from($show)->post("/secure/work-orders/{$token}/photo", [])->assertSessionHasErrors('work_order');
    }

    public function test_after_accepting_the_buttons_are_gone_and_what_did_you_find_appears(): void
    {
        [$wo, $token, $show] = $this->contractorPage();
        $this->post("/secure/work-orders/{$token}/respond", ['answer' => 'accept'])->assertRedirect($show);

        $this->get($show)->assertOk()
            ->assertSee('Accepted the job')->assertDontSee('Accept the job')->assertDontSee('Decline the job')->assertDontSee('If you cannot take it')
            ->assertSee('Upload a quote')->assertSee('Mark job done')->assertSee('What did you find?')->assertSee('Upload after photos');
    }

    public function test_after_declining_there_is_nothing_more_to_do_on_the_page(): void
    {
        [$wo, $token, $show] = $this->contractorPage();
        $this->post("/secure/work-orders/{$token}/respond", ['answer' => 'decline', 'reason' => 'Fully booked'])->assertRedirect($show);

        $this->get($show)->assertOk()->assertSee('Declined the job')->assertSee('Fully booked')
            ->assertDontSee('Accept the job')->assertDontSee('Upload a quote')->assertDontSee('Mark job done');
    }

    // ── item 14: the agency's display name ──────────────────────────────────────────────────────

    public function test_the_contractor_page_and_email_use_the_agencys_display_name_not_the_half_filled_trading_name(): void
    {
        [$wo, $token, $show] = $this->contractorPage();

        $this->get($show)->assertOk()->assertSee('from Walk Agency')->assertDontSee('T/A');
        $this->assertSame('Walk Agency', Agency::displayNameFor($this->agency));
        $this->assertSame('Your agency', Agency::displayNameFor(null));
        $this->assertSame('Only Trading', Agency::displayNameFor(new Agency(['name' => ' ', 'trading_name' => 'Only Trading'])));
    }

    // ── item 2: the time class ──────────────────────────────────────────────────────────────────

    public function test_a_typed_time_is_stored_in_the_app_timezone_and_reads_back_as_typed(): void
    {
        $this->assertSame('Africa/Johannesburg', config('app.timezone'));
        $at = AppDate::parse('2026-10-13T05:35', 'Africa/Johannesburg');
        $this->assertSame('2026-10-13 05:35:00', $at->format('Y-m-d H:i:s'), 'what is written to the datetime column');
        $this->assertSame('13 Oct 2026 05:35', AppDate::dateTime($at, 'Africa/Johannesburg'));
        $this->assertSame('13 Oct 2026', AppDate::day($at));
        // an agency in another timezone: the moment is kept, written in app time
        $cpt = AppDate::parse('2026-10-13T05:35', 'UTC');
        $this->assertSame('2026-10-13 07:35:00', $cpt->format('Y-m-d H:i:s'));
        $this->expectException(\InvalidArgumentException::class);
        AppDate::parse('not a time', 'Africa/Johannesburg');
    }

    public function test_every_place_a_person_types_a_time_in_the_fault_flow_stores_it_as_typed(): void
    {
        $typed = now()->setTimezone('Africa/Johannesburg')->subMinutes(30)->format('Y-m-d\TH:i');
        $hhmm = substr($typed, 11, 5);

        // 1. "Confirmed with the tenant"
        $f = $this->fault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.tenant-confirmation', $f), ['via' => 'phone_call', 'at' => $typed, 'note' => 'Phoned'])->assertRedirect();
        $this->assertSame($hhmm, $f->fresh()->tenant_confirmed_at->format('H:i'));

        // 2. "Record the owner's decision" - the record, the approval and the sentence the owner reads
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-decision.store', $f), [
            'owner_contact_id' => $this->landlord->id, 'decision' => 'authorise', 'told_via' => 'phone_call', 'told_at' => $typed, 'note' => 'Owner phoned',
        ])->assertRedirect();
        $row = \App\Models\RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->firstOrFail();
        $this->assertSame($hhmm, $row->told_at->format('H:i'));
        $this->assertStringContainsString($hhmm, app(\App\Services\Rentals\RentalAuthorisationService::class)->sentenceFor($row->load(['byUser', 'ownerContact'])));
        $this->assertSame($hhmm, $f->fresh()->decision()->decided_at->format('H:i'));
    }
}
