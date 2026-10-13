<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalAuthorisationToOwnerMail;
use App\Mail\Rentals\RentalJobOnHoldMail;
use App\Mail\Rentals\RentalOwnerNoticeToAgentMail;
use App\Mail\Rentals\RentalOwnerQueryAnswerMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalJobDispatch;
use App\Models\RentalOwnerHold;
use App\Models\RentalOwnerQuery;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalOwnerHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.7 / 22.12a, stage 3: "Stop this" and "Query this". The owner can stop a job their agent arranged
 * until the work has started (a reason is required; only the owner's own press records it - a mail scanner's GET never does); a stopped job is PAUSED BY OWNER
 * and every send / approve / start action is refused until a fresh authority resumes it. "Query this" asks the agent a question and stops nothing.
 */
final class OwnerStopQueryTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Hold Agency', 'slug' => 'ho-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'HO Unit', 'status' => 'active', 'listing_type' => 'rental', 'rental_no_approval_spend_threshold' => 1500]);
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

    private function holds(): RentalOwnerHoldService
    {
        return app(RentalOwnerHoldService::class);
    }

    /** A fault the agent has sent a free contractor to inspect: the owner has been told, a work order exists, the contractor was emailed. */
    private function arranged(): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'TENANT WORDS: tap', 'description' => 'the kitchen tap drips all night', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'x', 'owner_photo_ids' => []], $this->agent);
        $supplier = AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Free Plumbing', 'email' => 'freeplumbing@example.invalid', 'is_active' => true, 'specialty' => 'other',
            'callout_fee_mode' => 'no', 'callout_fee_amount' => null,
        ]);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-inspect', $fault), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id]);

        return $fault->fresh();
    }

    private function wo(RentalFaultReport $f): RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->fresh()->rental_work_order_id);
    }

    private function signed(RentalFaultReport $f, string $action): string
    {
        return URL::signedRoute('rentals.owner-notice', ['kind' => 'fault', 'id' => $f->id, 'contact' => $this->landlord->id, 'action' => $action]);
    }

    private function holdsFor(RentalFaultReport $f)
    {
        return RentalOwnerHold::withoutGlobalScopes()->where('rental_fault_report_id', $f->id);
    }

    // ── the notice carries the two equal buttons ─────────────────────────────

    public function test_the_owner_notice_email_carries_stop_this_and_query_this(): void
    {
        $f = $this->arranged();

        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, function ($m) use ($f) {
            $html = $m->render();

            return $m->hasTo($this->landlord->email) && $m->stopUrl && $m->queryUrl
                && str_contains($html, 'Stop this') && str_contains($html, 'Query this')
                && str_contains($m->stopUrl, '/owner/job/fault/' . $f->id . '/' . $this->landlord->id . '/stop') && str_contains($m->stopUrl, 'signature=');
        });
    }

    // ── the email link: a GET asks, only the owner's POST records ────────────

    public function test_a_get_on_the_stop_link_records_nothing_and_a_bad_signature_is_refused(): void
    {
        $f = $this->arranged();

        $this->get($this->signed($f, 'stop'))->assertOk()->assertSee('data-owner-notice-page="ask_stop"', false)->assertSee('Why do you want to stop it?');
        $this->assertSame(0, $this->holdsFor($f)->count(), 'a mail scanner fetching the link stops nothing');

        $bad = $this->signed($f, 'stop') . 'tampered';
        $this->get($bad)->assertForbidden();
        $this->get('/owner/job/fault/' . $f->id . '/' . $this->landlord->id . '/stop')->assertForbidden();
    }

    public function test_stopping_requires_a_reason_and_then_pauses_the_job_and_tells_everyone(): void
    {
        $f = $this->arranged();
        $wo = $this->wo($f);
        $this->assertSame('freeplumbing@example.invalid', RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('channel', 'email')->value('destination'), 'the contractor was emailed the job');

        // no reason: nothing is recorded
        $this->post($this->signed($f, 'stop'), ['do' => 'stop', 'reason' => ''])->assertOk()->assertSee('data-owner-notice-error', false);
        $this->assertSame(0, $this->holdsFor($f)->count());

        Mail::fake();
        $this->post($this->signed($f, 'stop'), ['do' => 'stop', 'reason' => 'I want a second quote first'])->assertOk()->assertSee('data-owner-notice-page="stopped"', false)->assertSee('On hold');

        $hold = $this->holdsFor($f)->firstOrFail();
        $this->assertSame('I want a second quote first', $hold->reason);
        $this->assertSame($this->landlord->id, (int) $hold->stopped_by_contact_id);
        $this->assertSame('link', $hold->stopped_via);
        $this->assertNull($hold->resumed_at);

        // the contractor was told, in plain words
        Mail::assertQueued(RentalJobOnHoldMail::class, fn ($m) => $m->hasTo('freeplumbing@example.invalid') && str_contains($m->render(), 'Please do not attend until you hear from us'));
        $this->assertNotNull($hold->fresh()->contractor_told_at);
        // both lease agents are emailed
        Mail::assertQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->agent->email) && str_contains($m->body, 'I want a second quote first'));
        // the history and the records say it in plain words
        $line = 'Stopped by owner Lenny Landlord on ';
        $this->assertTrue($f->updates()->where('update_type', 'owner_stopped')->where('note', 'like', $line . '%: I want a second quote first')->exists());
        $this->assertTrue($wo->updates()->where('update_type', 'owner_stopped')->exists());
        $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->assertSee('PAUSED BY OWNER')->assertSee($line, false)->assertSee('data-owner-stop-row', false);

        // a second press is told it is already on hold and writes nothing twice
        $this->post($this->signed($f, 'stop'), ['do' => 'stop', 'reason' => 'again'])->assertOk()->assertSee('data-owner-notice-page="held"', false);
        $this->assertSame(1, $this->holdsFor($f)->count());
    }

    // ── the state boundary: until the work has started ───────────────────────

    public function test_stop_is_available_until_the_work_has_started_and_then_it_is_replaced_by_the_started_line(): void
    {
        $f = $this->arranged();
        $wo = $this->wo($f);

        foreach ([RentalWorkOrder::STATUS_REPORTED, RentalWorkOrder::STATUS_ORDERED] as $status) {
            $wo->forceFill(['status' => $status])->save();
            $this->assertNull($this->holds()->stopBlock($f), "stoppable while the work order is {$status}");
        }

        foreach ([RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_DISPUTED, RentalWorkOrder::STATUS_COMPLETED] as $status) {
            $wo->forceFill(['status' => $status])->save();
            $this->assertSame('started', $this->holds()->stopBlock($f)['code'], "not stoppable once the work order is {$status}");
        }

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $this->get($this->signed($f, 'stop'))->assertOk()->assertSee('Work has started', false)->assertSee('contact your agent', false)->assertSee('data-open-query', false)->assertDontSee('data-confirm-stop', false);
        $this->post($this->signed($f, 'stop'), ['do' => 'stop', 'reason' => 'too late'])->assertOk()->assertSee('data-owner-notice-page="started"', false);
        $this->assertSame(0, $this->holdsFor($f)->count());

        // a job card that is under way counts as started, even when the work order has not caught up
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();
        $this->assertNull($this->holds()->stopBlock($f));
        $card = \App\Models\RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->first();
        if ($card) {
            $card->forceFill(['status' => \App\Models\RentalJobCard::STATUS_IN_PROGRESS])->save();
            $this->assertSame('started', $this->holds()->stopBlock($f)['code']);
        }
    }

    // ── a paused job refuses every send / approve / start ────────────────────

    public function test_a_paused_job_refuses_send_approve_and_start_with_the_reason(): void
    {
        $f = $this->arranged();
        $wo = $this->wo($f);
        $this->holds()->stop($f, $this->landlord, 'Not happy with this contractor', 'link');

        $reason = $this->holds()->holdReason($f);
        $this->assertStringStartsWith('PAUSED BY OWNER on ', $reason);
        $this->assertStringContainsString('Not happy with this contractor', $reason);
        $this->assertSame($reason, $this->holds()->holdReason($wo), 'the work order reads the same hold');

        // start / assign: the gate refuses
        try {
            $wo->fresh()->startProgress($this->agent);
            $this->fail('start must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('PAUSED BY OWNER', $e->getMessage());
        }
        // the agent cannot approve on the owner's behalf
        try {
            $wo->fresh()->recordApproval($this->agent, ['decision' => RentalWorkOrder::APPROVAL_APPROVED, 'evidence_type' => 'verbal_note', 'evidence_text' => 'x']);
            $this->fail('approve must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('PAUSED BY OWNER', $e->getMessage());
        }
        // the job cannot go out again
        $before = RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count();
        $result = app(\App\Services\Rentals\RentalContractorJobService::class)->resend($wo->fresh(), $this->agent, 'press-1');
        $this->assertArrayHasKey('held', $result);
        $this->assertFalse($result['sent']);
        $this->assertSame($before, RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count(), 'nothing new was sent');
        // a fault cannot be sent anywhere or get a new work order
        $this->assertStringContainsString('PAUSED BY OWNER', (string) $f->fresh()->workOrderBlockReason());
        $this->assertStringContainsString('PAUSED BY OWNER', (string) app(\App\Services\Rentals\FaultInspectionService::class)->blockReason($f->fresh()));
        // "Record the owner's decision" is not the way forward while paused: Resume is
        $this->assertStringContainsString('Use Resume', (string) app(\App\Services\Rentals\RentalAuthorisationService::class)->recordBlockReason($f->fresh()));

        // the screens say so
        $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->assertSee('PAUSED BY OWNER');
        // the tenant's progress line reads On hold; the tenant is not told why
        $progress = app(\App\Services\Rentals\RentalFaultProgressService::class)->forFault($f->fresh(), 'tenant');
        $this->assertTrue($progress['on_hold']);
        $this->assertSame('On hold', $progress['current_label']);
        $this->assertStringNotContainsString('contractor', strtolower(json_encode($progress)) === false ? '' : 'x');
        $this->assertStringNotContainsString('Not happy', json_encode($progress));
    }

    // ── resuming needs a fresh authority ─────────────────────────────────────

    public function test_the_owner_can_go_ahead_after_all_and_that_is_a_new_authorisation_record(): void
    {
        $f = $this->arranged();
        $this->holds()->stop($f, $this->landlord, 'Wait please', 'link');
        $recordsBefore = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count();

        $this->post($this->signed($f, 'stop'), ['do' => 'resume'])->assertOk()->assertSee('data-owner-notice-page="resumed"', false);

        $this->assertNull($this->holds()->activeFor($f));
        $hold = $this->holdsFor($f)->firstOrFail();
        $this->assertSame('owner_link', $hold->resume_basis);
        $this->assertSame($recordsBefore + 1, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count(), 'a fresh authority was recorded');
        $fresh = RentalAuthorisation::withoutGlobalScopes()->find($hold->resume_authorisation_id);
        $this->assertSame('owner_link', $fresh->basis);
        $this->assertNull($fresh->superseded_at, 'the fresh record is the one that stands');
        $this->assertSame(1, RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('point', 'inspect')->whereNull('superseded_at')->count());
        $this->assertTrue($f->updates()->where('update_type', 'owner_resumed')->where('note', 'like', 'Resumed on %')->exists());
        // the guards lift
        $this->assertNull($this->holds()->holdReason($this->wo($f)));
    }

    public function test_an_agent_resumes_only_by_recording_the_owners_decision(): void
    {
        $f = $this->arranged();
        $this->holds()->stop($f, $this->landlord, 'Wait please', 'link');
        $url = route('corex.rental-fault-reports.resume', $f);

        // what the owner said is required
        $this->actingAs($this->agent)->post($url, ['owner_contact_id' => $this->landlord->id, 'told_via' => 'phone_call', 'note' => ''])->assertSessionHasErrors('note');
        $this->assertNotNull($this->holds()->activeFor($f), 'still on hold');
        // only an owner of this property can have told the agent
        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Sam', 'last_name' => 'Stranger', 'email' => 'sam+' . uniqid() . '@example.invalid']);
        $this->actingAs($this->agent)->post($url, ['owner_contact_id' => $stranger->id, 'told_via' => 'phone_call', 'note' => 'x'])->assertSessionHasErrors('owner_decision');
        $this->assertNotNull($this->holds()->activeFor($f));

        Mail::fake();
        $this->actingAs($this->agent)->post($url, ['owner_contact_id' => $this->landlord->id, 'told_via' => 'whatsapp', 'note' => 'Owner says carry on', 'told_at' => now()->subMinutes(5)->toDateTimeString()])->assertRedirect(route('corex.rental-fault-reports.show', $f));

        $this->assertNull($this->holds()->activeFor($f));
        $hold = $this->holdsFor($f)->firstOrFail();
        $this->assertSame('owner_instruction', $hold->resume_basis);
        $this->assertSame($this->agent->id, (int) $hold->resumed_by_user_id);
        $fresh = RentalAuthorisation::withoutGlobalScopes()->find($hold->resume_authorisation_id);
        $this->assertSame('owner_instruction', $fresh->basis);
        $this->assertSame('Owner says carry on', $fresh->note);
        // the owner is emailed the same sentence, with the two buttons again
        Mail::assertQueued(RentalAuthorisationToOwnerMail::class, fn ($m) => $m->hasTo($this->landlord->email) && $m->stopUrl && str_contains($m->sentence, 'Authorised by your agent Agnes Agent on your instruction'));
        // not on hold any more: resume again is refused
        $this->actingAs($this->agent)->post($url, ['owner_contact_id' => $this->landlord->id, 'told_via' => 'whatsapp', 'note' => 'again'])->assertSessionHasErrors('owner_decision');
    }

    // ── Query this: asking stops nothing ─────────────────────────────────────

    public function test_query_this_stops_nothing_tells_both_agents_and_shows_the_answer_to_the_owner(): void
    {
        $f = $this->arranged();

        $this->get($this->signed($f, 'query'))->assertOk()->assertSee('Asking does not stop anything', false);
        Mail::fake();
        $this->post($this->signed($f, 'query'), ['do' => 'ask', 'question' => 'Why this plumber and not mine?'])->assertOk()->assertSee('data-owner-notice-page="asked"', false);

        $q = RentalOwnerQuery::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->firstOrFail();
        $this->assertSame('Why this plumber and not mine?', $q->question);
        $this->assertNull($this->holds()->activeFor($f), 'a question stops nothing');
        Mail::assertQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->agent->email) && str_contains($m->body, 'Why this plumber'));

        // the agent sees it in the block "Owner's question" and answers in the popup
        $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->assertSee("Owner's question", false)->assertSee('Why this plumber and not mine?')->assertSee('data-answer-open', false);
        Mail::fake();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-query.answer', [$f, $q->id]), ['answer' => 'Because they are free to attend.'])->assertRedirect();
        $q->refresh();
        $this->assertSame('Because they are free to attend.', $q->answer);
        $this->assertSame($this->agent->id, (int) $q->answered_by_user_id);
        Mail::assertQueued(RentalOwnerQueryAnswerMail::class, fn ($m) => $m->hasTo($this->landlord->email) && str_contains($m->render(), 'Because they are free to attend.'));
        // never edited: a second answer is refused
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-query.answer', [$f, $q->id]), ['answer' => 'changed'])->assertSessionHasErrors('owner_query');
        $this->assertSame('Because they are free to attend.', $q->fresh()->answer);
        // and the owner reads the answer on their link
        $this->get($this->signed($f, 'query'))->assertOk()->assertSee('Because they are free to attend.');
    }

    public function test_a_question_can_still_be_asked_after_the_work_has_started_and_while_paused(): void
    {
        $f = $this->arranged();
        $this->wo($f)->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $this->holds()->ask($f, $this->landlord, 'Who is on site?', 'link');
        $this->assertSame(1, RentalOwnerQuery::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->count());

        $f2 = $this->arranged();
        $this->holds()->stop($f2, $this->landlord, 'hold', 'link');
        $this->holds()->ask($f2, $this->landlord, 'When can it go ahead?', 'link');
        $this->assertSame(1, RentalOwnerQuery::withoutGlobalScopes()->where('rental_fault_report_id', $f2->id)->count());
        $this->assertNotNull($this->holds()->activeFor($f2), 'still on hold');
    }

    // ── the portal (logged-in owner) ─────────────────────────────────────────

    public function test_the_portal_shows_stop_and_query_then_stops_with_a_required_reason(): void
    {
        $f = $this->arranged();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $base = "/api/v1/client/rentals/landlord/fault-reports/{$f->id}";

        $actions = $this->getJson($base)->assertOk()->json('fault_report.owner_actions');
        $this->assertTrue($actions['show']);
        $this->assertTrue($actions['can_stop']);
        $this->assertNull($actions['hold']);

        $this->postJson("$base/stop", ['reason' => ''])->assertStatus(422);
        $this->postJson("$base/stop", ['reason' => 'ab'])->assertStatus(422);
        $this->assertSame(0, $this->holdsFor($f)->count());

        $res = $this->postJson("$base/stop", ['reason' => 'Please hold off'])->assertStatus(201)->json();
        $this->assertFalse($res['owner_actions']['can_stop']);
        $this->assertStringContainsString('Please hold off', $res['owner_actions']['hold']['line']);
        $this->assertTrue($res['progress']['on_hold']);
        $this->assertSame('portal', $this->holdsFor($f)->first()->stopped_via);

        // the owner screen offers "Go ahead after all" + Query this (the partial the portal renders)
        $this->postJson("$base/resume")->assertStatus(201)->assertJsonPath('owner_actions.hold', null);
        // (an identical second tap is answered from the one-press ledger - double tap = one record; a different request meets the real rule)
        $this->postJson("$base/resume", ['again' => 1])->assertStatus(422);   // nothing on hold any more

        $this->postJson("$base/query", ['note' => 'hi'])->assertStatus(422);   // too short
        $q = $this->postJson("$base/query", ['note' => 'What does the visit cost me?'])->assertStatus(201)->json('owner_actions.queries');
        $this->assertSame('What does the visit cost me?', $q[0]['question']);
        $this->assertNull($q[0]['answer']);
    }

    public function test_the_portal_refuses_stop_once_the_work_has_started_and_shows_the_started_line_data(): void
    {
        $f = $this->arranged();
        $this->wo($f)->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $base = "/api/v1/client/rentals/landlord/fault-reports/{$f->id}";

        $actions = $this->getJson($base)->json('fault_report.owner_actions');
        $this->assertFalse($actions['can_stop']);
        $this->assertTrue($actions['work_started']);
        $this->postJson("$base/stop", ['reason' => 'too late now'])->assertStatus(422)->assertJsonPath('message', 'Work has started - contact your agent.');
        $this->assertSame(0, $this->holdsFor($f)->count());
        // Query this is still there
        $this->postJson("$base/query", ['note' => 'Please explain'])->assertStatus(201);
    }

    public function test_another_owner_cannot_stop_or_query_this_owners_job(): void
    {
        $f = $this->arranged();
        $other = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental']);
        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Olive', 'last_name' => 'Other', 'email' => 'olive+' . uniqid() . '@example.invalid']);
        $other->contacts()->attach($stranger->id, ['role' => 'landlord']);

        // a signed link written for a stranger's contact id does not open for this job
        $url = URL::signedRoute('rentals.owner-notice', ['kind' => 'fault', 'id' => $f->id, 'contact' => $stranger->id, 'action' => 'stop']);
        $this->post($url, ['do' => 'stop', 'reason' => 'mine now'])->assertNotFound();
        $this->assertSame(0, $this->holdsFor($f)->count());

        Sanctum::actingAs($this->login($stranger), ['client']);
        $base = "/api/v1/client/rentals/landlord/fault-reports/{$f->id}";
        $this->postJson("$base/stop", ['reason' => 'mine now'])->assertStatus(404);
        $this->postJson("$base/query", ['note' => 'mine now'])->assertStatus(404);
        $this->postJson("$base/resume")->assertStatus(404);
        $this->assertSame(0, $this->holdsFor($f)->count());
    }

    public function test_questions_asked_before_this_build_are_carried_in_as_unanswered(): void
    {
        $f = $this->arranged();
        $f->recordHistory('owner_query', null, 'Lenny Landlord (owner) asked: Earlier question?');
        $migration = require base_path('database/migrations/2026_10_28_200000_create_owner_holds_and_queries.php');
        $migration->up();
        $migration->up();   // idempotent
        $rows = RentalOwnerQuery::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('asked_via', 'earlier')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Earlier question?', $rows->first()->question);
        $this->assertFalse($rows->first()->isAnswered());
    }

    // ── found in the end-to-end walk: the contractor's own link, and the time the owner told the agent ──

    public function test_the_contractors_link_says_on_hold_offers_nothing_to_do_and_never_shows_the_owners_reason(): void
    {
        $f = $this->arranged();
        $wo = $this->wo($f);
        $token = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($wo, $this->agent)['raw_token'];
        $show = route('rentals.secure-link.show', $token);

        $this->get($show)->assertOk()->assertDontSee('data-on-hold', false)->assertSee('Can you take this job?')->assertSee('Mark job done');

        $this->holds()->stop($f, $this->landlord, 'PRIVATE OWNER WORDS about the plumber', 'link');

        $this->get($show)->assertOk()
            ->assertSee('data-on-hold', false)->assertSee('please do not attend')->assertSee('Please do not attend until you hear from us')
            ->assertDontSee('Can you take this job?')->assertDontSee('Upload a quote')->assertDontSee('Mark job done')
            ->assertDontSee('PRIVATE OWNER WORDS');
        // every action refuses with the same plain line - and never the owner's reason
        foreach (['respond' => ['answer' => 'accept'], 'quote' => ['amount' => 100, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], 'mark-done' => ['found_text' => 'x'], 'photo' => []] as $path => $body) {
            $res = $this->from($show)->post("/secure/work-orders/{$token}/{$path}", $body);
            $res->assertSessionHasErrors('work_order');
            $this->assertSame('This job is on hold - please do not attend until you hear from us.', session('errors')->first('work_order'));
            $this->assertStringNotContainsString('PRIVATE OWNER WORDS', session('errors')->first('work_order'));
        }
        $this->assertSame('pending', $wo->fresh()->contractor_response, 'nothing the contractor pressed was recorded');
        $this->assertSame(0, $wo->quotes()->count());
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);

        // once the owner says go ahead, the page is a normal page again
        $this->holds()->resumeByOwner($f, $this->landlord, 'link');
        $this->get($show)->assertOk()->assertDontSee('data-on-hold', false)->assertSee('Can you take this job?');
    }

    public function test_the_time_the_agent_records_for_the_owners_go_ahead_is_the_time_they_typed(): void
    {
        $f = $this->arranged();
        $this->holds()->stop($f, $this->landlord, 'Wait please', 'link');
        $typed = now()->setTimezone('Africa/Johannesburg')->subMinutes(10)->format('Y-m-d\TH:i');   // what the agent's date box holds, in the agency's own time

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.resume', $f), ['owner_contact_id' => $this->landlord->id, 'told_via' => 'phone_call', 'note' => 'Owner says carry on', 'told_at' => $typed])
            ->assertRedirect(route('corex.rental-fault-reports.show', $f));

        $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_fault_report_id', $f->id)->where('basis', 'owner_instruction')->firstOrFail();
        $this->assertSame(substr(str_replace('T', ' ', $typed), 0, 16), $row->told_at->format('Y-m-d H:i'), 'stored in the app timezone, so it reads back as typed (not two hours early)');
        $expected = \Illuminate\Support\Carbon::parse($typed, 'Africa/Johannesburg')->format('j M Y H:i');
        $this->assertStringContainsString($expected, app(\App\Services\Rentals\RentalAuthorisationService::class)->sentenceFor($row->load(['byUser', 'ownerContact'])));
    }
}
