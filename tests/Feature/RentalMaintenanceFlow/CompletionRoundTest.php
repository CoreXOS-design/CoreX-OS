<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalJobCardCrewCompletedLandlordMail;
use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Models\LeaseTenant;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.10.1–§17.10.3 — a "work reported done" ROUND opens from each of the five reporting
 * routes (crew link, crew page, signed copy, the office's "Worker — done", "Contractor reports done"), the tenant is told
 * through the agency mailbox path with a one-click link, and the round records what was decided: asked / no tenant / no
 * email / switched off. The window is stored when the round opens, so a later settings change never moves it.
 *
 * Input paths proven: all five routes; `no_tenant` (vacancy), `no_email` (blank and malformed address), `disabled`;
 * window stored and not moved; one check per job (a repeat report does not ask twice); the crew completion survives a
 * cancelled work order; nothing is ever sent as a plain Mailable.
 */
final class CompletionRoundTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
    }

    private function rounds(RentalWorkOrder $workOrder)
    {
        return RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $workOrder->id)->orderBy('round_no')->get();
    }

    // ── The five reporting routes ────────────────────────────────────────

    public function test_the_crew_link_opens_a_round_and_the_tenant_is_emailed_a_one_click_link(): void
    {
        [$workOrder, $card] = $this->internalJob();
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];

        $this->post("/secure/job-cards/{$raw}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();

        $round = $this->rounds($workOrder)->sole();
        $this->assertSame(1, $round->round_no);
        $this->assertSame(RentalWorkCompletionRound::VIA_CREW_LINK, $round->reported_via);
        $this->assertSame('Sipho Dlamini', $round->reported_by_label);
        $this->assertSame($card->id, $round->rental_job_card_id);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $round->outcome);
        $this->assertSame(RentalWorkCompletionRound::NOTIFY_SENT, $round->fresh()->tenant_notify_status);
        $this->assertNotNull($round->fresh()->tenant_notified_at);

        $sent = $this->mailer->sentOf(RentalTenantCompletionCheckMail::class);
        $this->assertCount(1, $sent);
        $this->assertSame($this->tenant->email, $sent[0][0]);
        $this->assertSame($this->admin->id, $sent[0][1]->sendingAgentId(), 'sent AS the property\'s responsible agent');
        $html = $sent[0][1]->render();
        $this->assertStringContainsString('Sipho Dlamini', $html);
        $this->assertStringContainsString('14 Ocean View Drive', $html);
        $this->assertMatchesRegularExpression('#/secure/completion/[A-Za-z0-9]{64}#', $html);
        $this->assertStringNotContainsString('R ', strip_tags(preg_replace('#<style.*?</style>#s', '', $html)), 'the tenant is never shown a price');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_the_response_link_is_a_hashed_token_with_one_live_link_per_round(): void
    {
        [$workOrder, $card] = $this->internalJob();
        $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link', 'rental_job_card_id' => $card->id]);

        $tokens = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->get();
        $this->assertCount(1, $tokens);
        $token = $tokens->first();
        $this->assertSame(RentalSecureAccessToken::PURPOSE_TENANT_COMPLETION, $token->purpose);
        $this->assertSame(64, strlen($token->token_hash), 'only the SHA-256 is stored');
        $this->assertNull($token->rental_work_order_id, 'exactly one target');
        $this->assertNull($token->rental_job_card_id);
        $this->assertTrue($token->isLive());
        $this->assertTrue($token->expires_at->equalTo($round->fresh()->window_ends_at->copy()->addDays(7)), 'expires 7 days after the window ends');

        $again = app(RentalSecureAccessTokenService::class)->issueForCompletionRound($round->fresh());
        $this->assertNotNull(RentalSecureAccessToken::withoutGlobalScopes()->find($token->id)->revoked_at, 'issuing again kills the earlier link');
        $this->assertTrue($again['token']->isLive());
    }

    public function test_the_crew_page_the_signed_copy_and_the_office_sign_off_each_open_a_round(): void
    {
        // crew page
        [$wo1, $card1] = $this->internalJob();
        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $this->post("/secure/crews/{$page}/job-cards/{$card1->id}/complete", ['full_name' => 'Page Crew', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkCompletionRound::VIA_CREW_PAGE, $this->rounds($wo1)->sole()->reported_via);

        // signed copy
        [$wo2, $card2] = $this->internalJob();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.signed-copy.store', $card2), [
            'signed_copy' => UploadedFile::fake()->create('signed.pdf', 200, 'application/pdf'), 'signed_by_name' => 'Paper Crew',
        ])->assertSessionHasNoErrors();
        $round = $this->rounds($wo2)->sole();
        $this->assertSame(RentalWorkCompletionRound::VIA_SIGNED_COPY, $round->reported_via);
        $this->assertSame('Paper Crew', $round->reported_by_label);
        $this->assertSame($this->admin->id, $round->reported_by_user_id);

        // the office's "Worker — done"
        [$wo3, $card3] = $this->internalJob();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card3), ['worker_sign_off_name' => 'Desk Entry'])->assertSessionHasNoErrors();
        $round = $this->rounds($wo3)->sole();
        $this->assertSame(RentalWorkCompletionRound::VIA_OFFICE, $round->reported_via);
        $this->assertSame('Desk Entry', $round->reported_by_label);

        $this->assertCount(3, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class), 'one tenant mail per round');
    }

    public function test_contractor_reports_done_opens_a_round_keeps_it_in_progress_and_stores_the_photos(): void
    {
        $workOrder = $this->externalJob();

        $response = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $workOrder), [
            'date_done' => now()->toDateString(), 'reported_via' => 'whatsapp', 'note' => 'Pipe replaced, tested for leaks',
            'photos' => [UploadedFile::fake()->image('after1.jpg'), UploadedFile::fake()->image('after2.jpg')],
        ]);

        $response->assertRedirect(route('corex.rental-work-orders.show', $workOrder))->assertSessionHasNoErrors();
        $round = $this->rounds($workOrder)->sole();
        $this->assertSame(RentalWorkCompletionRound::VIA_CONTRACTOR_CAPTURED, $round->reported_via);
        $this->assertSame('Ramsgate Plumbing', $round->reported_by_label);
        $this->assertStringContainsString('WhatsApp', $round->reported_note);
        $this->assertStringContainsString('Pipe replaced', $round->reported_note);
        $this->assertSame($this->admin->id, $round->reported_by_user_id);
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $workOrder->fresh()->status, 'ordered moves to in progress; it is NOT closed');
        $photos = $workOrder->photos()->get();
        $this->assertCount(2, $photos);
        $this->assertSame(['completed'], $photos->pluck('photo_type')->unique()->all());
        $this->assertSame(['office'], $photos->pluck('uploaded_via')->unique()->all());
        $this->assertCount(1, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class));
    }

    public function test_contractor_reports_done_validates_and_is_refused_where_it_makes_no_sense(): void
    {
        $workOrder = $this->externalJob();
        $url = route('corex.rental-work-orders.contractor-done', $workOrder);

        $this->actingAs($this->admin)->post($url, ['date_done' => now()->toDateString()])->assertSessionHasErrors('reported_via');
        $this->actingAs($this->admin)->post($url, ['reported_via' => 'phone', 'date_done' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('date_done');
        $this->actingAs($this->admin)->post($url, ['reported_via' => 'carrier_pigeon'])->assertSessionHasErrors('reported_via');
        $this->actingAs($this->admin)->post($url, ['reported_via' => 'phone', 'photos' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, $this->rounds($workOrder)->count());

        // the lazy-but-valid shortcut: how they told you is all that is required
        $this->actingAs($this->admin)->post($url, ['reported_via' => 'phone'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rounds($workOrder)->count());

        // an internal job reports through the job card, a not-yet-ordered one has nothing to report
        [$internal] = $this->internalJob();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $internal), ['reported_via' => 'phone'])
            ->assertSessionHasErrors('completion');
        $fresh = $this->externalJob(['status' => RentalWorkOrder::STATUS_REPORTED, 'title' => 'Not yet ordered']);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $fresh), ['reported_via' => 'phone'])
            ->assertSessionHasErrors('completion');
        $this->assertSame(0, $this->rounds($internal)->count() + $this->rounds($fresh)->count());
    }

    // ── What the round records ───────────────────────────────────────────

    public function test_the_window_is_stored_when_the_round_opens_and_a_later_setting_change_never_moves_it(): void
    {
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['completion_response_window_days' => 9]);
        [$workOrder] = $this->internalJob();

        $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
        $this->assertTrue($round->window_ends_at->between(now()->addDays(9)->subMinute(), now()->addDays(9)->addMinute()));

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['completion_response_window_days' => 2]);
        $this->assertTrue($round->fresh()->window_ends_at->between(now()->addDays(9)->subMinute(), now()->addDays(9)->addMinute()), 'an open window never moves');
    }

    public function test_the_default_window_is_five_days_for_an_agency_that_never_set_one(): void
    {
        [$workOrder] = $this->internalJob();

        $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

        $this->assertTrue($round->window_ends_at->between(now()->addDays(5)->subMinute(), now()->addDays(5)->addMinute()));
    }

    public function test_a_tenancy_with_no_tenant_records_no_tenant_and_nothing_waits(): void
    {
        [$workOrder] = $this->internalJob(['lease_id' => null]);

        $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

        $this->assertSame(RentalWorkCompletionRound::NOTIFY_NO_TENANT, $round->tenant_notify_status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_NO_TENANT, $round->outcome);
        $this->assertNull($round->window_ends_at);
        $this->assertCount(0, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class));
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('purpose', 'tenant_completion')->count());
    }

    public function test_a_tenant_with_no_usable_email_keeps_the_round_open_for_a_phone_answer(): void
    {
        foreach (['blank' => null, 'malformed' => 'thandi at gmail dot com'] as $label => $email) {
            // straight to the table: the Contact model keeps contacts.email mirrored from its child email rows, which would undo it
            \Illuminate\Support\Facades\DB::table('contacts')->where('id', $this->tenant->id)->update(['email' => $email]);
            [$workOrder] = $this->internalJob();

            $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

            $this->assertSame(RentalWorkCompletionRound::NOTIFY_NO_EMAIL, $round->tenant_notify_status, $label);
            $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $round->outcome, $label);
            $this->assertNotNull($round->window_ends_at, $label);
            $this->assertStringContainsString('record their answer by phone', $round->statusText(), $label);
        }
        $this->assertCount(0, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class));
    }

    public function test_the_whole_check_can_be_switched_off(): void
    {
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_completion_check_enabled' => false]);
        [$workOrder] = $this->internalJob();

        $round = app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

        $this->assertSame(RentalWorkCompletionRound::NOTIFY_DISABLED, $round->tenant_notify_status);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_NO_TENANT, $round->outcome);
        $this->assertCount(0, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class));
        $this->assertStringContainsString('switched off', $round->statusText());
    }

    public function test_every_tenant_on_the_lease_is_emailed_and_a_failing_address_does_not_stop_the_rest(): void
    {
        $second = $this->makeContact($this->agency, ['first_name' => 'Sizwe', 'email' => 'sizwe.' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $second->id, 'is_primary' => false]);
        [$workOrder] = $this->internalJob();

        app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

        $sent = $this->mailer->sentOf(RentalTenantCompletionCheckMail::class);
        $this->assertEqualsCanonicalizing([$this->tenant->email, $second->email], array_column($sent, 0));
        $this->assertSame(RentalWorkCompletionRound::NOTIFY_SENT, $this->rounds($workOrder)->sole()->tenant_notify_status);
    }

    // ── One check per job ────────────────────────────────────────────────

    public function test_reporting_the_same_job_done_twice_does_not_ask_the_tenant_twice(): void
    {
        [$workOrder, $card] = $this->internalJob();
        $service = app(RentalCompletionService::class);

        $first = $service->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link', 'rental_job_card_id' => $card->id]);
        $second = $service->openRound($workOrder, ['reported_by_label' => 'Paper copy', 'reported_via' => 'signed_copy', 'rental_job_card_id' => $card->id]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->rounds($workOrder)->count());
        $this->assertCount(1, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class));
        $this->assertSame(2, $workOrder->updates()->where('update_type', 'work_reported_done')->count(), 'both reports are in the history');
    }

    // ── It must never break the crew's own completion ────────────────────

    public function test_a_cancelled_work_order_refuses_a_round_but_the_crews_completion_still_records(): void
    {
        [$workOrder, $card] = $this->internalJob();
        $workOrder->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        try {
            app(RentalCompletionService::class)->openRound($workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
            $this->fail('a cancelled work order cannot have work reported done');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];
        $this->post("/secure/job-cards/{$raw}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull($card->fresh()->worker_signed_off_at, 'the crew still sees "completed"');
        $this->assertSame(0, $this->rounds($workOrder)->count());
    }

    public function test_the_landlords_crew_completed_mail_is_unchanged_and_the_two_mails_do_not_cross(): void
    {
        [, $card] = $this->internalJob();
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];

        $this->post("/secure/job-cards/{$raw}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();

        $landlordMails = $this->mailer->sentOf(RentalJobCardCrewCompletedLandlordMail::class);
        $this->assertCount(1, $landlordMails);
        $this->assertSame($this->landlord->email, $landlordMails[0][0]);
        $this->assertCount(1, $this->mailer->sentOf(RentalTenantCompletionCheckMail::class));
        $this->assertNotSame($landlordMails[0][0], $this->mailer->sentOf(RentalTenantCompletionCheckMail::class)[0][0]);
    }

    // ── Scope and permission on the office route ─────────────────────────

    public function test_contractor_reports_done_needs_its_permission_and_the_right_scope(): void
    {
        $workOrder = $this->externalJob();
        $without = $this->userWith(['rental_work_orders.view' => 'all'], 'agent', ['rental_work_orders.view', 'rental_work_orders.manage_completion']);
        $this->actingAs($without)->post(route('corex.rental-work-orders.contractor-done', $workOrder), ['reported_via' => 'phone'])->assertStatus(403);

        $ownOnly = $this->userWith(['rental_work_orders.view' => 'own', 'rental_work_orders.manage_completion' => 'own'], 'clerk', ['rental_work_orders.view', 'rental_work_orders.manage_completion']);
        $this->actingAs($ownOnly)->post(route('corex.rental-work-orders.contractor-done', $workOrder), ['reported_via' => 'phone'])->assertStatus(403);
        $this->assertSame(0, $this->rounds($workOrder)->count());

        $theirs = $this->foreignWorkOrder();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $theirs->id), ['reported_via' => 'phone'])->assertStatus(404);
    }
}
