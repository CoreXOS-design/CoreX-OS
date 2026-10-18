<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Models\RentalJobCard;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.10.3 / §17.10.4 — the tenant answers "is this finished?" three ways that all go through
 * RentalCompletionService::respond(): the public response link, the portal endpoint (and its old `confirm` alias), and the
 * office on their behalf.
 *
 * Input paths proven: confirm and dispute (note + photos) on the link; required-empty (no answer, no note, a 4-character note),
 * too many photos, a non-image; a repeat answer (refused, nothing changes); a forged / revoked / expired / wrong-purpose /
 * archived link (ONE identical "unavailable" page); a window that has passed; the throttle; the portal endpoint for the
 * tenant, another tenant (404), another agency (404) and nobody (401); the alias with and without an open round; the office on
 * behalf, including after the window but before the nightly settle.
 */
final class TenantCompletionResponseTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalWorkOrder $workOrder;
    private RentalJobCard $card;
    private RentalWorkCompletionRound $round;
    private string $raw;
    private $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->notifier = \Mockery::spy(NotificationDispatcher::class);
        $this->app->instance(NotificationDispatcher::class, $this->notifier);

        [$this->workOrder, $this->card] = $this->internalJob();
        $this->card->forceFill(['worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Sipho Dlamini', 'worker_sign_off_via' => 'crew_link'])->save();
        $this->round = app(RentalCompletionService::class)->openRound($this->workOrder, [
            'reported_by_label' => 'Sipho Dlamini', 'reported_via' => 'crew_link', 'rental_job_card_id' => $this->card->id,
        ]);
        $this->raw = $this->rawFromMail();
    }

    private function rawFromMail(): string
    {
        $mail = $this->mailer->sentOf(RentalTenantCompletionCheckMail::class)[0][1];
        preg_match('#/secure/completion/([A-Za-z0-9]{64})#', $mail->url, $m);

        return $m[1];
    }

    private function url(?string $raw = null): string
    {
        return '/secure/completion/' . ($raw ?? $this->raw);
    }

    // ── The link: page, confirm, dispute ─────────────────────────────────

    public function test_the_link_page_shows_the_job_who_reported_it_and_both_answers_and_never_a_price(): void
    {
        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('Fix the geyser', $html);
        $this->assertStringContainsString('14 Ocean View Drive', $html);
        $this->assertStringContainsString('Our maintenance team', $html, 'who reported it: the agency\'s team label');
        $this->assertStringNotContainsString('Sipho Dlamini', $html, 'never the crew member\'s name (8 Oct 2026)');
        $this->assertStringContainsString('All done, thanks', $html);
        $this->assertStringContainsString('Not complete / still wrong', $html);
        $this->assertStringContainsString('optional', $html, 'T1: the answer is optional - no answer-by date');
        $this->assertStringNotContainsString('R ', strip_tags(preg_replace('#<(style|script).*?</\1>#s', '', $html)));
    }

    public function test_confirming_records_the_answer_mirrors_it_and_tells_the_office(): void
    {
        $this->post($this->url(), ['answer' => 'fixed'])->assertRedirect($this->url())->assertSessionHasNoErrors();

        $round = $this->round->fresh();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $round->outcome);
        $this->assertSame(RentalWorkCompletionRound::RESPONDED_LINK, $round->responded_via);
        $this->assertNotNull($round->responded_at);
        $this->assertNull($round->responded_by_user_id);
        $wo = $this->workOrder->fresh();
        $this->assertTrue((bool) $wo->tenant_confirmed_fixed, 'the legacy columns mirror the latest answer');
        $this->assertNotNull($wo->tenant_confirmed_at);
        $this->assertTrue((bool) $this->card->fresh()->tenant_confirmed_fixed);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $wo->status, 'a confirmation never changes the stage');
        $this->assertSame(1, $wo->updates()->where('update_type', 'completion_response')->count());
        $this->notifier->shouldHaveReceived('fire')->withArgs(fn ($u, $key) => $key === 'rental_work_order.completion_confirmed')->once();

        $this->get($this->url())->assertOk()->assertSee('You answered on')->assertSee('the work is done');
    }

    public function test_saying_it_is_not_complete_stores_the_note_and_photos_and_opens_the_dispute(): void
    {
        $this->post($this->url(), [
            'answer' => 'not_fixed', 'note' => 'The tap still drips when you turn it off',
            'photos' => [UploadedFile::fake()->image('drip1.jpg'), UploadedFile::fake()->image('drip2.jpg')],
        ])->assertRedirect($this->url())->assertSessionHasNoErrors();

        $round = $this->round->fresh();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $round->outcome);
        $this->assertSame('The tap still drips when you turn it off', $round->response_note);
        $photos = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->get();
        $this->assertCount(2, $photos);
        $this->assertSame([RentalWorkOrder::PHOTO_DISPUTE], $photos->pluck('photo_type')->unique()->all());
        $this->assertSame([RentalWorkOrderPhoto::VIA_TENANT], $photos->pluck('uploaded_via')->unique()->all());
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status, 'T1: stored, not reopened');
        $this->assertNotSame(RentalJobCard::STATUS_DISPUTED, $this->card->fresh()->status);
        $this->assertFalse((bool) $this->workOrder->fresh()->tenant_confirmed_fixed, 'the mirror says "not fixed"');

        $this->get($this->url())->assertOk()->assertSee('You answered on')->assertSee('the work is not complete')->assertSee('The tap still drips');
    }

    public function test_a_second_answer_on_the_same_link_is_refused_and_changes_nothing(): void
    {
        $this->post($this->url(), ['answer' => 'fixed'])->assertRedirect();
        $answeredAt = $this->round->fresh()->responded_at;

        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => 'Actually it is still broken'])->assertRedirect($this->url());

        $round = $this->round->fresh();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $round->outcome);
        $this->assertTrue($answeredAt->equalTo($round->responded_at));
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
        $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->count());
    }

    // ── Input space ──────────────────────────────────────────────────────

    public function test_required_and_malformed_answers_are_rejected_with_messages_and_change_nothing(): void
    {
        $this->post($this->url(), [])->assertSessionHasErrors('answer');
        $this->post($this->url(), ['answer' => 'maybe'])->assertSessionHasErrors('answer');
        $this->post($this->url(), ['answer' => 'not_fixed'])->assertSessionHasErrors('note');
        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => '   '])->assertSessionHasErrors('note');
        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => 'abcd'])->assertSessionHasErrors('note');
        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => 'Still leaking badly', 'photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11))])->assertSessionHasErrors('photos');
        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => 'Still leaking badly', 'photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => str_repeat('x', 2001)])->assertSessionHasErrors('note');

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status);
    }

    public function test_exactly_ten_photos_and_a_five_character_note_are_accepted(): void
    {
        $this->post($this->url(), [
            'answer' => 'not_fixed', 'note' => 'Leaks',
            'photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 10)),
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $this->round->id)->count());
    }

    // ── Link problems: one identical page ────────────────────────────────

    public function test_every_dead_link_renders_the_same_unavailable_page(): void
    {
        $forged = $this->get($this->url(str_repeat('a', 64)))->assertOk()->getContent();
        $this->assertStringContainsString('no longer available', $forged);

        RentalSecureAccessToken::withoutGlobalScopes()->where('rental_completion_round_id', $this->round->id)->update(['revoked_at' => now()]);
        $revoked = $this->get($this->url())->assertOk()->getContent();

        RentalSecureAccessToken::withoutGlobalScopes()->where('rental_completion_round_id', $this->round->id)->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $expired = $this->get($this->url())->assertOk()->getContent();

        $crewRaw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
        $wrongPurpose = $this->get($this->url($crewRaw))->assertOk()->getContent();

        $this->assertSame($forged, $revoked);
        $this->assertSame($forged, $expired);
        $this->assertSame($forged, $wrongPurpose);

        // and a POST to a dead link answers nothing
        $this->post($this->url(), ['answer' => 'fixed'])->assertOk()->assertSee('no longer available');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
    }

    public function test_an_archived_work_order_makes_its_link_unavailable(): void
    {
        $this->workOrder->delete();

        $this->get($this->url())->assertOk()->assertSee('no longer available');
        $this->post($this->url(), ['answer' => 'fixed'])->assertOk()->assertSee('no longer available');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
    }

    public function test_after_the_old_window_the_link_still_takes_an_answer(): void
    {
        // T1: no cut-off - "silence = accepted" is retired, the answer is an optional record at any time.
        $this->round->forceFill(['window_ends_at' => now()->subHour()])->save();

        $this->get($this->url())->assertOk()->assertDontSee('response period has ended');
        $this->post($this->url(), ['answer' => 'not_fixed', 'note' => 'Still broken after all'])->assertRedirect($this->url());

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status, 'and it reopens nothing by itself');
    }

    public function test_a_cancelled_job_closes_the_link(): void
    {
        $this->workOrder->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        $this->get($this->url())->assertOk()->assertSee('no longer available');
    }

    public function test_the_link_is_rate_limited(): void
    {
        $last = null;
        for ($i = 0; $i < 32; $i++) {
            $last = $this->get($this->url(str_repeat('b', 64)));
        }

        $last->assertStatus(429);
    }

    // ── The portal ───────────────────────────────────────────────────────

    public function test_the_tenant_confirms_through_the_portal(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->postJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}/completion-response", ['fixed' => true])
            ->assertOk()->assertJsonPath('work_order.id', $this->workOrder->id);

        $round = $this->round->fresh();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $round->outcome);
        $this->assertSame(RentalWorkCompletionRound::RESPONDED_PORTAL, $round->responded_via);
        $this->assertSame($this->tenant->id, $round->responded_by_contact_id, 'a portal answer is attributed to the person');
    }

    public function test_the_tenant_disputes_through_the_portal_with_photos_and_a_note_is_required(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $url = "/api/v1/client/rentals/work-orders/{$this->workOrder->id}/completion-response";

        $this->postJson($url, ['fixed' => false])->assertStatus(422);
        $this->postJson($url, ['fixed' => false, 'note' => 'abc'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'what is still wrong'));
        $this->postJson($url, [])->assertStatus(422);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);

        $this->post($url, ['fixed' => '0', 'note' => 'Paint is peeling already', 'photos' => [UploadedFile::fake()->image('peel.jpg')]], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome);
        $this->assertSame(1, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $this->round->id)->count());
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status, 'T1: stored, not reopened');
    }

    public function test_the_portal_refuses_a_repeat_answer_in_plain_words(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $url = "/api/v1/client/rentals/work-orders/{$this->workOrder->id}/completion-response";

        $this->round->forceFill(['window_ends_at' => now()->subMinute()])->save();
        $this->postJson($url, ['fixed' => true])->assertOk();   // T1: the old window no longer cuts the tenant off
        $this->postJson($url, ['fixed' => false, 'note' => 'Changed my mind'])->assertStatus(422)->assertJsonPath('message', 'This check has already been answered.');
    }

    public function test_another_tenant_another_agency_and_nobody_cannot_answer(): void
    {
        // every fixture first: a record built while a portal user is authenticated is stamped by THEIR identity
        $otherProperty = $this->makeProperty($this->agency, $this->admin, '4 Beach Road, Uvongo');
        $neighbour = $this->makeTenant($this->agency, $this->makeLease($this->agency, $otherProperty), ['first_name' => 'Bob']);
        [, , , , $capeTenant] = $this->otherAgencyWorld();
        $url = "/api/v1/client/rentals/work-orders/{$this->workOrder->id}/completion-response";

        $this->postJson($url, ['fixed' => true])->assertStatus(401);

        Sanctum::actingAs($this->clientUserFor($neighbour), ['client']);
        $this->postJson($url, ['fixed' => true])->assertStatus(404);

        Sanctum::actingAs($this->clientUserFor($capeTenant), ['client']);
        $this->postJson($url, ['fixed' => true])->assertStatus(404);

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
    }

    public function test_the_old_confirm_endpoint_answers_the_open_round(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->postJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}/confirm", ['fixed' => false, 'note' => 'Still not heating up'])->assertOk();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $this->round->fresh()->outcome);
        $this->assertNotSame(RentalWorkOrder::STATUS_DISPUTED, $this->workOrder->fresh()->status, 'T1: stored, not reopened');
    }

    public function test_the_old_confirm_endpoint_still_behaves_as_before_with_no_open_round(): void
    {
        [$closed] = $this->internalJob();
        $closed->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED, 'completed_at' => now(), 'paid_by' => 'owner'])->save();
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->postJson("/api/v1/client/rentals/work-orders/{$closed->id}/confirm", ['fixed' => true])->assertOk()->assertJsonPath('work_order.tenant_confirmed_fixed', true);

        [$open] = $this->internalJob();   // not completed, no round: the old rule still refuses
        $this->postJson("/api/v1/client/rentals/work-orders/{$open->id}/confirm", ['fixed' => true])->assertStatus(422);
    }

    // ── The office on their behalf ───────────────────────────────────────

    public function test_the_office_records_the_tenants_phone_answer_with_photos(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.completion-rounds.answer', [$this->workOrder, $this->round->id]), [
            'answer' => 'not_fixed', 'note' => 'Tenant phoned: the leak is back', 'return_to' => 'job_card',
            'photos' => [UploadedFile::fake()->image('whatsapp.jpg')],
        ])->assertRedirect(route('corex.rental-job-cards.show', $this->card))->assertSessionHasNoErrors();

        $round = $this->round->fresh();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $round->outcome);
        $this->assertSame(RentalWorkCompletionRound::RESPONDED_OFFICE_ON_BEHALF, $round->responded_via);
        $this->assertSame($this->admin->id, $round->responded_by_user_id);
        $this->assertNull($round->responded_by_contact_id);
        $photo = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->sole();
        $this->assertSame(RentalWorkOrderPhoto::VIA_OFFICE, $photo->uploaded_via);
        $this->assertSame($this->admin->id, $photo->uploaded_by_user_id);
    }

    public function test_the_office_validates_and_refuses_what_makes_no_sense(): void
    {
        $url = route('corex.rental-work-orders.completion-rounds.answer', [$this->workOrder, $this->round->id]);

        $this->actingAs($this->admin)->post($url, [])->assertSessionHasErrors('answer');
        $this->actingAs($this->admin)->post($url, ['answer' => 'not_fixed'])->assertSessionHasErrors('completion');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);

        $this->actingAs($this->admin)->post($url, ['answer' => 'fixed'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($url, ['answer' => 'not_fixed', 'note' => 'Second thoughts'])->assertSessionHasErrors('completion');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $this->round->fresh()->outcome);

        // another work order's round id is a 404, never answered through this one
        [$other] = $this->internalJob();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.completion-rounds.answer', [$other, $this->round->id]), ['answer' => 'fixed'])->assertStatus(404);
    }

    public function test_the_office_may_record_a_late_phone_answer_until_the_nightly_settle_has_run(): void
    {
        $this->round->forceFill(['window_ends_at' => now()->subHours(3)])->save();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.completion-rounds.answer', [$this->workOrder, $this->round->id]), ['answer' => 'fixed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $this->round->fresh()->outcome);
    }

    public function test_recording_the_answer_needs_the_completion_permission(): void
    {
        $without = $this->userWith(['rental_work_orders.view' => 'all'], 'agent', ['rental_work_orders.view', 'rental_work_orders.manage_completion']);

        $this->actingAs($without)->post(route('corex.rental-work-orders.completion-rounds.answer', [$this->workOrder, $this->round->id]), ['answer' => 'fixed'])->assertStatus(403);

        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $this->round->fresh()->outcome);
    }
}
