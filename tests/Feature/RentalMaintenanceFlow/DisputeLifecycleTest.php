<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalDisputeSentBackMail;
use App\Mail\Rentals\RentalLandlordDisputeMail;
use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.10.6–§17.10.9 — the dispute lifecycle, end to end.
 *
 * Input paths proven: both records go Disputed; a COMPLETED card is reopened with its worker and agent sign-offs snapshotted
 * into the round and reset; the close is refused (card, work order, the office screen) while disputed and the close is
 * never half-done; "Send back" for the crew (fresh link replaces the old, mail via the agency mailbox with the tenant's note
 * and photos) and for a contractor (no link); the crew's page shows the banner, the photos only there, and "Report fixed";
 * a new round returns both to In progress, stamps the old round resolved and asks the tenant again; every round stays in the
 * history; owner mail and "send straight to the crew" follow their settings; the agent AND the branch manager are told;
 * scope and permission on the office actions.
 */
final class DisputeLifecycleTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalWorkOrder $workOrder;
    private RentalJobCard $card;
    private RentalWorkCompletionRound $round;
    private $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->notifier = \Mockery::spy(NotificationDispatcher::class);
        $this->app->instance(NotificationDispatcher::class, $this->notifier);

        [$this->workOrder, $this->card] = $this->internalJob();
        $this->round = $this->crewReportsDone($this->card);
    }

    /** The crew's link completion (sign-off + the listener's round), the way a real crew does it. */
    private function crewReportsDone(RentalJobCard $card, ?string $raw = null): RentalWorkCompletionRound
    {
        $raw ??= app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];
        $this->post("/secure/job-cards/{$raw}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();

        return RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $card->rental_work_order_id)->orderByDesc('round_no')->firstOrFail();
    }

    private function dispute(?RentalWorkCompletionRound $round = null, string $note = 'The tap still drips after the repair', array $photos = []): RentalWorkCompletionRound
    {
        $round ??= $this->round;
        app(RentalCompletionService::class)->respond($round, false, $note, $photos, ['contact' => $this->tenant, 'via' => 'portal']);

        return $round->fresh();
    }

    /** T1 (9 Oct 2026): "Send back" is the agent's own act - the ONLY thing that reopens a job the tenant said is not fixed. */
    private function sendBack(?RentalWorkOrder $wo = null): void
    {
        app(RentalCompletionService::class)->sendBack(($wo ?? $this->workOrder)->fresh(), $this->admin);
    }

    private function photos(): array
    {
        return [UploadedFile::fake()->image('drip1.jpg'), UploadedFile::fake()->image('drip2.jpg')];
    }

    // ── The tenant's "not fixed" is a record; the agent's send-back reopens ─

    public function test_a_dispute_is_stored_and_reopens_nothing_until_the_agent_sends_it_back(): void
    {
        $round = $this->dispute();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $round->outcome);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status, 'T1: not reopened by itself');
        $this->assertNotSame(RentalJobCard::STATUS_DISPUTED, $this->card->fresh()->status);
        $this->assertTrue($this->workOrder->fresh()->hasUnresolvedTenantDispute());
        $this->assertSame(0, $this->workOrder->updates()->where('update_type', 'dispute_opened')->count());

        $this->sendBack();

        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalJobCard::STATUS_DISPUTED, $this->card->fresh()->status);
        $this->assertTrue($this->workOrder->fresh()->hasOpenDispute());
        $this->assertFalse($this->card->fresh()->isClosed(), 'disputed is an OPEN state');

        $woLog = $this->workOrder->updates()->where('update_type', 'dispute_opened')->sole();
        $this->assertStringContainsString('The tap still drips', $woLog->note);
        $this->assertSame('disputed', $woLog->to_status);
        $this->assertSame(1, $this->card->updates()->where('update_type', 'dispute_opened')->count());
    }

    public function test_a_completed_card_is_reopened_and_its_sign_offs_are_snapshotted_then_reset(): void
    {
        // crew done (round 1 is already open) → agent signs off → the card and work order close together
        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);
        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);

        $round = $this->dispute();
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status, 'T1: the tenant\'s answer does not reopen a closed job');
        $this->sendBack();
        $round = $round->fresh();

        $card = $this->card->fresh();
        $this->assertSame(RentalJobCard::STATUS_DISPUTED, $card->status);
        $this->assertNull($card->completed_at, 'a completed job is reopened by the agent\'s send-back');
        $this->assertNull($card->worker_signed_off_at);
        $this->assertNull($card->worker_sign_off_name);
        $this->assertNull($card->agent_signed_off_at);
        $this->assertNull($this->workOrder->fresh()->completed_at);

        $snapshot = $round->sign_off_snapshot;
        $this->assertSame('completed', $snapshot['status_before']);
        $this->assertSame('Sipho Dlamini', $snapshot['worker_sign_off_name']);
        $this->assertSame('crew_link', $snapshot['worker_sign_off_via']);
        $this->assertNotNull($snapshot['worker_signed_off_at']);
        $this->assertNotNull($snapshot['agent_signed_off_at']);
        $this->assertSame($this->admin->id, $snapshot['agent_signed_off_by_user_id']);
        $this->assertSame(1, $card->updates()->where('update_type', 'reopened')->count());
    }

    public function test_reopening_a_closed_card_does_not_bring_its_old_crew_link_back_to_life(): void
    {
        $oldRaw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card->fresh(), $this->admin)['raw_token'];
        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);
        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);
        $this->get('/secure/job-cards/' . $oldRaw)->assertSee('no longer available');   // closed: dead

        $this->dispute();

        $this->get('/secure/job-cards/' . $oldRaw)->assertSee('no longer available');   // still closed: dead until the agent's "Send back"
        $fresh = app(RentalCompletionService::class)->sendBackWithResult($this->workOrder->fresh(), $this->admin)['link_url'];
        $this->get($fresh)->assertOk()->assertSee('The tenant says this is not complete');
    }

    public function test_a_confirmation_never_reopens_anything(): void
    {
        app(RentalCompletionService::class)->respond($this->round, true, null, [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
        $this->assertNotSame(RentalJobCard::STATUS_DISPUTED, $this->card->fresh()->status);
        $this->assertNotNull($this->card->fresh()->worker_signed_off_at, 'the crew sign-off stands');
    }

    // ── The close is refused while disputed, and is never half done ──────

    public function test_a_tenant_dispute_never_stops_the_agent_closing_the_job(): void
    {
        // T1 (Johan, 9 Oct 2026): "agent can close on word and evidence from the crew or contractor" - the tenant's "not fixed" is a record.
        $this->dispute();

        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.complete', $card))->assertSessionHasNoErrors();

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome, 'the tenant\'s answer stays on the record');
        $this->assertTrue($this->workOrder->fresh()->hasUnresolvedTenantDispute(), 'and still raises its needs-action row');
    }

    public function test_a_job_the_agent_sent_back_can_be_closed_again_by_the_agent(): void
    {
        $this->dispute();
        $this->sendBack();
        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $this->workOrder), ['paid_by' => 'owner'])->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
    }

    public function test_the_card_and_its_work_order_close_together_or_not_at_all(): void
    {
        // §17.23 defect #1 — a refusal from the work order used to be swallowed, leaving a CLOSED card on an OPEN work order.
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['completion_requires_photo' => true]);
        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);

        try {
            app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);
            $this->fail('the missing completed photo must surface');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('completed" photo is required', $e->getMessage());
        }

        $this->assertNotSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status, 'the card close was rolled back with it');
        $this->assertNull($this->card->fresh()->completed_at);
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);

        // the same through the office screen: a message, not a 500
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.complete', $this->card))->assertSessionHasErrors('rental_job_card');

        $this->workOrder->photos()->create(['agency_id' => $this->agency->id, 'photo_type' => 'completed', 'storage_path' => '/storage/x.jpg', 'file_size_bytes' => 10]);
        app(RentalJobCardService::class)->complete($this->card->fresh(), $this->admin);
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
    }

    public function test_closing_is_allowed_while_a_round_is_merely_waiting_for_the_tenant(): void
    {
        // §17.22 Decision 2 — the office may close and invoice inside the window.
        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);

        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
    }

    // ── Send back to the crew ────────────────────────────────────────────

    public function test_send_back_to_the_crew_issues_a_fresh_link_and_mails_the_tenants_note_and_photos(): void
    {
        $oldRaw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
        $this->dispute(null, 'The tap still drips after the repair', $this->photos());

        $response = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.send-back', $this->workOrder), ['return_to' => 'job_card']);

        $response->assertRedirect(route('corex.rental-job-cards.show', $this->card))->assertSessionHasNoErrors();
        $freshUrl = session('crew_link_url');
        $this->assertStringContainsString('/secure/job-cards/', $freshUrl);

        $sent = $this->mailer->sentOf(RentalDisputeSentBackMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame($this->crew->email, $sent[0][0]);
        $this->assertSame($this->admin->id, $sent[0][1]->sendingAgentId(), 'sent AS the agent who pressed the button');
        $html = $sent[0][1]->render();
        $this->assertStringContainsString('The tap still drips', $html);
        $this->assertStringContainsString($freshUrl, $html);
        $urls = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $this->round->id)->pluck('storage_path')->all();
        $this->assertCount(2, $urls);
        foreach ($urls as $url) {
            $this->assertStringContainsString($url, $html, 'every tenant photo is linked');
        }
        $this->assertStringNotContainsString('Thandi', $html, 'the crew is told what is wrong, not who said it');
        $this->assertStringNotContainsString($this->tenant->email, $html);

        // the earlier link is dead, the fresh one is live
        $this->get('/secure/job-cards/' . $oldRaw)->assertSee('no longer available');
        $this->get($freshUrl)->assertOk()->assertSee('The tenant says this is not complete');

        $this->assertSame(1, $this->workOrder->updates()->where('update_type', 'dispute_sent_back')->count());
        $this->assertSame(1, $this->card->updates()->where('update_type', 'dispute_sent_back')->count());
        $this->assertStringContainsString($this->crew->email, $this->workOrder->updates()->where('update_type', 'dispute_sent_back')->first()->note);
    }

    public function test_send_back_still_works_when_the_crew_has_no_email_or_links_are_switched_off(): void
    {
        $this->dispute();
        $this->crew->forceFill(['email' => null])->save();

        $result = app(RentalCompletionService::class)->sendBackWithResult($this->workOrder->fresh(), $this->admin);
        $this->assertFalse($result['emailed']);
        $this->assertNotNull($result['link_url'], 'the office is given the fresh link to pass on another way');
        $this->assertStringContainsString('no email address on file', $result['message']);
        $this->assertCount(0, $this->mailer->sentOf(RentalDisputeSentBackMail::class));

        $this->crew->forceFill(['email' => 'team.back@example.invalid'])->save();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);
        $result = app(RentalCompletionService::class)->sendBackWithResult($this->workOrder->fresh(), $this->admin);
        $this->assertTrue($result['emailed']);
        $this->assertNull($result['link_url']);
        $this->assertStringNotContainsString('/secure/job-cards/', $this->mailer->sentOf(RentalDisputeSentBackMail::class)[0][1]->render());
    }

    public function test_only_a_disputed_work_order_can_be_sent_back(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.send-back', $this->workOrder))->assertSessionHasErrors('completion');

        $this->assertCount(0, $this->mailer->sentOf(RentalDisputeSentBackMail::class));
    }

    // ── The crew's view of a dispute ─────────────────────────────────────

    public function test_the_crews_page_shows_the_banner_the_photos_only_there_and_report_fixed(): void
    {
        $this->dispute(null, 'The tap still drips after the repair', $this->photos());
        $this->sendBack();
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card->fresh(), $this->admin)['raw_token'];

        $html = $this->get('/secure/job-cards/' . $raw)->assertOk()->getContent();

        $this->assertStringContainsString('The tenant says this is not complete', $html);
        $this->assertStringContainsString('The tap still drips after the repair', $html);
        $this->assertStringContainsString('Report fixed', $html);
        $this->assertStringNotContainsString('Mark work completed', $html);
        $photoUrls = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $this->round->id)->pluck('storage_path')->all();
        $this->assertCount(2, $photoUrls);
        foreach ($photoUrls as $url) {
            $this->assertSame(1, substr_count($html, 'href="' . $url . '"'), "dispute photo shown exactly once — inside the banner, not also in the general gallery: {$url}");
        }
        $this->assertStringNotContainsString('Thandi', $html);
        $this->assertStringNotContainsString($this->tenant->email, $html);
    }

    public function test_a_normal_job_shows_no_dispute_banner_and_the_usual_button(): void
    {
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
        $this->card->fresh()->forceFill(['worker_signed_off_at' => null, 'worker_sign_off_name' => null])->save();

        $html = $this->get('/secure/job-cards/' . $raw)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-dispute-banner', $html);
        $this->assertStringContainsString('Mark work completed', $html);
    }

    // ── Reported fixed → the next round ──────────────────────────────────

    public function test_reporting_it_fixed_opens_the_next_round_returns_both_to_in_progress_and_asks_the_tenant_again(): void
    {
        $this->dispute(null, 'The tap still drips after the repair', $this->photos());
        $result = app(RentalCompletionService::class)->sendBackWithResult($this->workOrder->fresh(), $this->admin);
        $raw = basename(parse_url($result['link_url'], PHP_URL_PATH));

        $second = $this->crewReportsDone($this->card->fresh(), $raw);

        $this->assertSame(2, $second->round_no);
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $this->workOrder->fresh()->status);
        $this->assertSame(RentalJobCard::STATUS_IN_PROGRESS, $this->card->fresh()->status);
        $this->assertNotNull($this->round->fresh()->dispute_resolved_at, 'the old round is stamped resolved');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome, 'and its history is untouched');
        $this->assertNotNull($this->card->fresh()->worker_signed_off_at, 'the crew signed again');
        $this->assertCount(2, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class), 'the tenant is told again');
        $this->assertFalse($this->workOrder->fresh()->hasOpenDispute());

        // every round and every reopening is in the logs
        $rounds = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $this->workOrder->id)->orderBy('round_no')->get();
        $this->assertSame([1, 2], $rounds->pluck('round_no')->all());
        $this->assertSame(['disputed', 'awaiting_tenant'], $rounds->pluck('outcome')->all());
        $this->assertSame(2, $this->workOrder->updates()->where('update_type', 'work_reported_done')->count());
        $this->assertSame(1, $this->workOrder->updates()->where('update_type', 'dispute_opened')->count());
        $this->assertSame(1, $this->workOrder->updates()->where('update_type', 'dispute_sent_back')->count());
        $this->assertSame(1, $this->card->updates()->where('update_type', 'dispute_opened')->count());
    }

    public function test_a_disputed_job_can_be_disputed_again_in_the_next_round_and_closed_once_confirmed(): void
    {
        $this->dispute();
        $this->sendBack();
        $second = $this->crewReportsDone($this->card->fresh());
        $this->dispute($second, 'Now the pipe underneath is wet');
        $this->sendBack();

        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
        $third = $this->crewReportsDone($this->card->fresh());
        app(RentalCompletionService::class)->respond($third, true, null, [], ['contact' => $this->tenant, 'via' => 'portal']);

        $card = $this->card->fresh();
        $card->agentSignOff($this->admin);
        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);   // the dispute is resolved — the close is allowed again

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->workOrder->fresh()->status);
        $this->assertSame([1, 2, 3], RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $this->workOrder->id)->orderBy('round_no')->pluck('round_no')->all());
    }

    // ── Who is told ──────────────────────────────────────────────────────

    public function test_the_agent_and_the_branch_manager_are_told_and_the_owner_is_emailed_per_the_setting(): void
    {
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager']);

        $this->dispute(null, 'The tap still drips after the repair', $this->photos());

        $notified = [];
        $this->notifier->shouldHaveReceived('fire')->withArgs(function ($user, $key) use (&$notified) {
            if ($key === 'rental_work_order.disputed') {
                $notified[] = $user->id;
            }

            return $key === 'rental_work_order.disputed';
        });
        $this->assertEqualsCanonicalizing([$this->admin->id, $manager->id], $notified);

        $owner = $this->mailer->sentOf(RentalLandlordDisputeMail::class);
        $this->assertCount(1, $owner);
        $this->assertSame($this->landlord->email, $owner[0][0]);
        $html = $owner[0][1]->render();
        $this->assertStringContainsString('The tap still drips', $html);
        $this->assertStringContainsString('the work to be put right', $html);
        $this->assertStringNotContainsString($this->tenant->email, $html);
        $text = strip_tags(preg_replace('#<style.*?</style>#s', '', $html));
        $this->assertDoesNotMatchRegularExpression('/\bR\s?\d/', $text, 'no amounts in the owner dispute mail');
    }

    public function test_the_owner_is_not_emailed_when_the_setting_is_off(): void
    {
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_dispute' => false]);

        $this->dispute();

        $this->assertCount(0, $this->mailer->sentOf(RentalLandlordDisputeMail::class));
        $this->assertTrue($this->workOrder->fresh()->hasUnresolvedTenantDispute(), 'the dispute itself is unaffected');
    }

    public function test_the_crew_is_sent_back_straight_away_only_when_the_setting_is_on(): void
    {
        $this->dispute();
        $this->assertCount(0, $this->mailer->sentOf(RentalDisputeSentBackMail::class), 'default: the office looks first');

        [$wo2, $card2] = $this->internalJob(['title' => 'Second job']);
        $round2 = $this->crewReportsDone($card2);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['dispute_notify_crew_immediately' => true]);

        $this->dispute($round2);

        $sent = $this->mailer->sentOf(RentalDisputeSentBackMail::class);
        $this->assertCount(1, $sent, 'on: the crew is emailed the moment the tenant disputes');
        $this->assertSame($this->crew->email, $sent[0][0]);
        $this->assertStringContainsString('/secure/job-cards/', $sent[0][1]->render());
        $this->assertSame(1, $wo2->updates()->where('update_type', 'dispute_sent_back')->count());
    }

    // ── External work ────────────────────────────────────────────────────

    public function test_an_external_job_is_disputed_sent_back_to_the_contractor_and_reported_done_again(): void
    {
        $external = $this->externalJob();
        $round = app(RentalCompletionService::class)->recordContractorDone($external, ['reported_via' => 'phone', 'note' => 'All fixed'], $this->admin);
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $external->fresh()->status);

        app(RentalCompletionService::class)->respond($round, false, 'Pipe still sweating at the joint', [], ['contact' => $this->tenant, 'via' => 'portal']);
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $external->fresh()->status, 'T1: nothing reopens until the agent sends it back');
        $this->assertSame(0, RentalJobCard::where('rental_work_order_id', $external->id)->count());

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.send-back', $external))->assertSessionHasNoErrors();
        $sent = $this->mailer->sentOf(RentalDisputeSentBackMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame($external->supplier->email, $sent[0][0]);
        $this->assertStringContainsString('Pipe still sweating', $sent[0][1]->render());
        $this->assertStringNotContainsString('/secure/', $sent[0][1]->render(), 'a contractor gets no link — the office captures their completion');
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_work_order_id', $external->id)->count());

        // the contractor reports it fixed → the next round
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $external->fresh()), ['reported_via' => 'phone', 'note' => 'Re-sealed the joint'])->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $external->fresh()->status);
        $this->assertSame(2, RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $external->id)->count());
        $this->assertNotNull($round->fresh()->dispute_resolved_at);
    }

    public function test_a_disputed_external_job_can_be_closed_by_the_complete_form(): void
    {
        $external = $this->externalJob();
        $round = app(RentalCompletionService::class)->recordContractorDone($external, ['reported_via' => 'phone'], $this->admin);
        app(RentalCompletionService::class)->respond($round, false, 'Still leaking underneath', [], ['contact' => $this->tenant, 'via' => 'portal']);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $external), ['paid_by' => 'owner', 'cost_amount' => 300])
            ->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $external->fresh()->status, 'T1: the agent closes on the contractor\'s word');
    }

    // ── The office screens ───────────────────────────────────────────────

    public function test_the_office_screens_show_the_dispute_panel_and_the_send_back_button_only_to_those_who_may_use_it(): void
    {
        $this->dispute(null, 'The tap still drips after the repair', $this->photos());

        foreach ([route('corex.rental-work-orders.show', $this->workOrder), route('corex.rental-job-cards.show', $this->card)] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('The tenant says this is not complete', $html, $url);
            $this->assertStringContainsString('The tap still drips', $html, $url);
            $this->assertStringContainsString('Send back to crew', $html, $url);
            $this->assertStringContainsString('Completion check', $html, $url);
            $this->assertStringContainsString('Round 1', $html, $url);
        }

        $viewer = $this->userWith([
            'rental_work_orders.view' => 'all', 'rental_job_cards.view' => 'all',
        ], 'agent', ['rental_work_orders.view', 'rental_job_cards.view', 'rental_work_orders.manage_completion']);
        $html = $this->actingAs($viewer)->get(route('corex.rental-work-orders.show', $this->workOrder))->assertOk()->getContent();
        $this->assertStringContainsString('The tenant says this is not complete', $html);
        $this->assertStringNotContainsString('Send back to crew', $html, 'no permission, no button');
        $this->actingAs($viewer)->post(route('corex.rental-work-orders.send-back', $this->workOrder))->assertStatus(403);
    }

    public function test_send_back_is_scoped_to_the_users_own_branch_or_records_and_blocks_another_agency(): void
    {
        $this->dispute();
        $ownOnly = $this->userWith(['rental_work_orders.view' => 'own', 'rental_work_orders.manage_completion' => 'own'], 'clerk', ['rental_work_orders.view', 'rental_work_orders.manage_completion']);
        $this->actingAs($ownOnly)->post(route('corex.rental-work-orders.send-back', $this->workOrder))->assertStatus(403);
        $this->assertCount(0, $this->mailer->sentOf(RentalDisputeSentBackMail::class));

        $theirs = $this->foreignWorkOrder(['status' => 'disputed']);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.send-back', $theirs->id))->assertStatus(404);
    }

    public function test_a_disputed_work_order_can_still_be_cancelled(): void
    {
        $this->dispute();

        $this->workOrder->fresh()->cancel($this->admin, 'Owner decided to sell');

        $this->assertSame(RentalWorkOrder::STATUS_CANCELLED, $this->workOrder->fresh()->status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome, 'the round is history and stays');
    }
}
