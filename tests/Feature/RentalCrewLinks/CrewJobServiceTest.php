<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Events\Rentals\RentalJobCardCrewPhotosAdded;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 items 4-5 — the shared crew view
 * layer: what a crew sees (payload), what a crew can do (tick, photos, mark
 * completed) and what those actions write. Service-level; the public HTTP
 * routes are covered in CrewJobLinkActionsTest / CrewJobLinkViewTest.
 */
final class CrewJobServiceTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private CrewJobService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Service');
        $this->service = app(CrewJobService::class);
    }

    private function ctx(RentalJobCard $card): CrewViewContext
    {
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);

        return new CrewViewContext(
            agencyId: $this->agency->id, crewId: $this->crew->id, tokenId: $issued['token']->id,
            via: CrewViewContext::VIA_JOB_LINK,
            showPrices: RentalPortalSetting::crewLinkShowPricesFor($this->agency->id),
            showTenantContact: RentalPortalSetting::crewLinkShowTenantContactFor($this->agency->id),
            ip: '203.0.113.9', userAgent: 'TestPhone/1.0', actorLabel: 'via crew link — Team 1',
        );
    }

    // ── payload ─────────────────────────────────────────────────────────

    public function test_payload_has_the_crew_view_and_no_prices_by_default(): void
    {
        $card = $this->makeJobCard();
        $payload = $this->service->payload($card, $this->ctx($card));

        $this->assertSame('Fix the geyser', $payload['title']);
        $this->assertSame('Key is under the pot plant', $payload['access_notes']);
        $this->assertSame('Team 1', $payload['crew_name']);
        $this->assertCount(2, $payload['tasks']);
        $this->assertSame('Drain the geyser', $payload['tasks'][0]['description']);
        $this->assertSame('Geyser element', $payload['materials'][0]['description']);
        $this->assertSame('2', $payload['materials'][0]['quantity']);
        $this->assertSame('Plumber hour', $payload['labour'][0]['description']);
        $this->assertFalse($payload['show_prices']);
        $this->assertArrayNotHasKey('unit_price', $payload['materials'][0]);
        $this->assertArrayNotHasKey('line_total', $payload['labour'][0]);
        $this->assertNull($payload['total']);
        $this->assertNull($payload['tenant']);
        $this->assertNull($payload['crew_completed']);
        $this->assertTrue($payload['is_open']);
    }

    public function test_payload_never_carries_landlord_quote_approval_or_history(): void
    {
        $card = $this->makeJobCard();
        $payload = $this->service->payload($card, $this->ctx($card));

        $this->assertSame(
            ['access_notes', 'address', 'agency', 'crew_completed', 'crew_name', 'due_at', 'is_open', 'labour', 'map_url', 'materials', 'photos', 'scheduled_at', 'show_prices', 'status', 'status_label', 'tasks', 'tenant', 'title', 'total'],
            collect(array_keys($payload))->sort()->values()->all(),
        );
    }

    public function test_prices_appear_only_with_the_agency_setting(): void
    {
        $card = $this->makeJobCard();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_prices' => true]);

        $payload = $this->service->payload($card, $this->ctx($card));

        $this->assertTrue($payload['show_prices']);
        $this->assertSame('450.00', $payload['materials'][0]['unit_price']);
        $this->assertSame('900.00', $payload['materials'][0]['line_total']);
        $this->assertSame('1,800.00', $payload['total']);
    }

    public function test_tenant_name_and_phone_only_with_the_agency_setting(): void
    {
        $card = $this->makeJobCard();
        $this->attachTenant($card->fresh());

        $this->assertNull($this->service->payload($card->fresh(), $this->ctx($card))['tenant']);

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => true]);
        $tenant = $this->service->payload($card->fresh(), $this->ctx($card->fresh()))['tenant'];

        $this->assertSame('Tina Tenant', $tenant[0]['name']);
        $this->assertSame('0831112222', $tenant[0]['phone']);
    }

    public function test_reported_photos_are_not_shown_to_the_crew(): void
    {
        $card = $this->makeJobCard();
        RentalWorkOrderPhoto::create([
            'agency_id' => $this->agency->id, 'rental_job_card_id' => $card->id, 'photo_type' => 'reported',
            'storage_path' => '/storage/properties/1/reported.jpg', 'uploaded_by_user_id' => $this->admin->id,
        ]);
        RentalWorkOrderPhoto::create([
            'agency_id' => $this->agency->id, 'rental_job_card_id' => $card->id, 'photo_type' => 'in_progress',
            'storage_path' => '/storage/properties/1/progress.jpg', 'uploaded_by_user_id' => $this->admin->id,
        ]);

        $urls = collect($this->service->payload($card, $this->ctx($card))['photos'])->pluck('url')->all();

        $this->assertSame(['/storage/properties/1/progress.jpg'], $urls);
    }

    public function test_another_agencys_card_is_not_reachable(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        $foreign = new CrewViewContext(
            agencyId: $this->agency->id + 999, crewId: $ctx->crewId, tokenId: $ctx->tokenId, via: $ctx->via,
            showPrices: false, showTenantContact: false, ip: null, userAgent: null, actorLabel: 'x',
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->service->payload($card, $foreign);
    }

    // ── tick ────────────────────────────────────────────────────────────

    public function test_tick_toggles_and_logs_with_no_user_actor(): void
    {
        $card = $this->makeJobCard();
        $task = $card->tasks()->first();
        $ctx = $this->ctx($card);

        $this->service->tick($card, $task, $ctx);
        $this->assertTrue($task->fresh()->is_done);
        $this->assertNull($task->fresh()->done_by_user_id);

        $this->service->tick($card, $task->fresh(), $ctx);
        $this->assertFalse($task->fresh()->is_done);

        $update = $card->updates()->where('update_type', 'task_ticked')->orderBy('id')->first();
        $this->assertNull($update->created_by_user_id);
        $this->assertStringContainsString('Ticked: Drain the geyser', $update->note);
        $this->assertStringContainsString('via crew link — Team 1', $update->note);
    }

    public function test_a_task_of_another_card_is_a_404(): void
    {
        $card = $this->makeJobCard();
        $other = $this->makeJobCard(['title' => 'Other card']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->service->tick($card, $other->tasks()->first(), $this->ctx($card));
    }

    public function test_tick_refuses_a_closed_card(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        $card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        $this->expectException(\LogicException::class);
        $this->service->tick($card->fresh(), $card->tasks()->first(), $ctx);
    }

    // ── photos ──────────────────────────────────────────────────────────

    public function test_add_photos_stores_with_type_caption_via_and_no_user(): void
    {
        Event::fake([RentalJobCardCrewPhotosAdded::class]);
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);

        $count = $this->service->addPhotos($card, [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')], 'in_progress', ' Pipe replaced ', $ctx);

        $this->assertSame(2, $count);
        $photos = RentalWorkOrderPhoto::where('rental_job_card_id', $card->id)->get();
        $this->assertCount(2, $photos);
        $this->assertSame('in_progress', $photos[0]->photo_type);
        $this->assertSame('Pipe replaced', $photos[0]->caption);
        $this->assertSame('crew_link', $photos[0]->uploaded_via);
        $this->assertNull($photos[0]->uploaded_by_user_id);

        $logged = $card->updates()->where('update_type', 'crew_photos_added')->get();
        $this->assertCount(1, $logged, 'one history line per submit, not per file');
        $this->assertStringContainsString('2 in-progress photos', $logged[0]->note);
        Event::assertDispatched(RentalJobCardCrewPhotosAdded::class, fn ($e) => $e->count === 2 && $e->photoType === 'in_progress');
    }

    public function test_a_resent_client_key_does_not_double_post(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        $key = '9b6d6f5e-3c1a-4f9e-8d2b-7a1c5e0f4a11';

        $this->assertSame(1, $this->service->addPhotos($card, [UploadedFile::fake()->image('a.jpg')], 'completed', null, $ctx, [$key]));
        $this->assertSame(0, $this->service->addPhotos($card, [UploadedFile::fake()->image('a.jpg')], 'completed', null, $ctx, [$key]));

        $this->assertSame(1, RentalWorkOrderPhoto::where('rental_job_card_id', $card->id)->count());
    }

    public function test_photo_type_and_count_limits(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);

        try {
            $this->service->addPhotos($card, [UploadedFile::fake()->image('a.jpg')], 'reported', null, $ctx);
            $this->fail('reported is not a crew photo type');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $eleven = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11));
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addPhotos($card, $eleven, 'in_progress', null, $ctx);
    }

    public function test_photos_refused_on_a_closed_card(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        $card->forceFill(['status' => RentalJobCard::STATUS_CANCELLED])->save();

        $this->expectException(\LogicException::class);
        $this->service->addPhotos($card->fresh(), [UploadedFile::fake()->image('a.jpg')], 'in_progress', null, $ctx);
    }

    // ── mark completed ──────────────────────────────────────────────────

    public function test_mark_completed_records_name_time_ip_device_via_and_does_not_close_the_card(): void
    {
        Event::fake([RentalJobCardCrewCompleted::class]);
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);

        $this->service->markCompleted($card, '  Sipho   Dlamini ', true, $ctx);

        $card = $card->fresh();
        $this->assertNotNull($card->worker_signed_off_at);
        $this->assertSame('Sipho Dlamini', $card->worker_sign_off_name);
        $this->assertSame('crew_link', $card->worker_sign_off_via);
        $this->assertSame('203.0.113.9', $card->worker_sign_off_ip);
        $this->assertSame('TestPhone/1.0', $card->worker_sign_off_device);
        $this->assertNull($card->worker_signed_off_by_user_id);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->status, 'crew completion never closes the card');
        $this->assertNull($card->completed_at);
        $this->assertNull($card->agent_signed_off_at);

        $this->assertSame(1, $card->updates()->where('update_type', 'crew_completed')->count());
        Event::assertDispatched(RentalJobCardCrewCompleted::class, fn ($e) => $e->signedByName === 'Sipho Dlamini' && $e->via === 'crew_link');
    }

    public function test_mark_completed_needs_a_name_and_the_tick(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);

        foreach ([['', true], ['A', true], ['Sipho Dlamini', false]] as [$name, $tick]) {
            try {
                $this->service->markCompleted($card, $name, $tick, $ctx);
                $this->fail("accepted name '{$name}' tick=" . var_export($tick, true));
            } catch (\InvalidArgumentException) {
                $this->assertNull($card->fresh()->worker_signed_off_at);
            }
        }
    }

    public function test_mark_completed_cannot_be_repeated_from_the_link(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        $this->service->markCompleted($card, 'Sipho Dlamini', true, $ctx);

        $this->expectException(\LogicException::class);
        $this->service->markCompleted($card->fresh(), 'Someone Else', true, $ctx);
    }

    public function test_agent_sign_off_still_closes_the_card_after_a_crew_completion(): void
    {
        $card = $this->makeJobCard();
        $this->service->markCompleted($card, 'Sipho Dlamini', true, $this->ctx($card));

        $card = $card->fresh();
        $card->agentSignOff($this->admin);
        app(\App\Services\Rentals\RentalJobCardService::class)->complete($card->fresh(), $this->admin);

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $card->fresh()->status);
    }

    public function test_office_worker_sign_off_is_recorded_as_via_office(): void
    {
        $card = $this->makeJobCard();
        $card->workerSignOff($this->admin, 'Foreman Joe');

        $card = $card->fresh();
        $this->assertSame('office', $card->worker_sign_off_via);
        $this->assertSame($this->admin->id, $card->worker_signed_off_by_user_id);
    }

    // ── the shared Blade partial ────────────────────────────────────────

    private function renderBody(RentalJobCard $card): string
    {
        return view('rentals.crew-link._job-body', [
            'job' => $this->service->payload($card, $this->ctx($card)),
            'actions' => ['tick' => '/t/__TASK__/tick', 'photos' => '/t/photos', 'complete' => '/t/complete'],
        ])->render();
    }

    public function test_the_partial_renders_the_crew_view_with_the_two_actions(): void
    {
        $card = $this->makeJobCard();
        $html = $this->renderBody($card);

        $this->assertStringContainsString('Fix the geyser', $html);
        $this->assertStringContainsString('Key is under the pot plant', $html);
        $this->assertStringContainsString('1. Drain the geyser', $html);
        $this->assertStringContainsString('What to load', $html);
        $this->assertStringContainsString('Geyser element', $html);
        $this->assertStringContainsString('action="/t/' . $card->tasks()->first()->id . '/tick"', $html);
        $this->assertStringContainsString('name="photos[]"', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('Mark work completed', $html);
        $this->assertStringNotContainsString('R 900', $html, 'no prices by default');
    }

    public function test_the_partial_replaces_the_complete_button_once_the_crew_has_signed(): void
    {
        $card = $this->makeJobCard();
        $this->service->markCompleted($card, 'Sipho Dlamini', true, $this->ctx($card));

        $html = $this->renderBody($card->fresh());

        $this->assertStringContainsString('Completed — signed by Sipho Dlamini', $html);
        $this->assertStringNotContainsString('class="cj-complete-form"', $html);
    }

    // ── token service (shared interface) ────────────────────────────────

    public function test_issue_for_job_card_shows_raw_once_stores_only_the_hash_and_logs(): void
    {
        $card = $this->makeJobCard();
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);

        $this->assertSame(64, strlen($issued['raw_token']));
        $row = RentalSecureAccessToken::withoutGlobalScopes()->find($issued['token']->id);
        $this->assertSame(hash('sha256', $issued['raw_token']), $row->token_hash);
        $this->assertNotSame($issued['raw_token'], $row->token_hash);
        $this->assertSame('crew_job_card', $row->purpose);
        $this->assertSame($card->id, $row->rental_job_card_id);
        $this->assertNull($row->rental_work_order_id);
        $this->assertNull($row->rental_crew_id);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $row->expires_at->timestamp, 5);
        $this->assertSame(1, $card->updates()->where('update_type', 'link_issued')->count());
    }

    public function test_reissue_kills_the_old_link_on_the_very_next_resolve(): void
    {
        $card = $this->makeJobCard();
        $service = app(RentalSecureAccessTokenService::class);
        $first = $service->issueForJobCard($card, $this->admin);
        $this->assertNotNull($service->resolveLive($first['raw_token'], 'crew_job_card'));

        $second = $service->issueForJobCard($card, $this->admin);

        $this->assertNull($service->resolveLive($first['raw_token'], 'crew_job_card'));
        $this->assertNotNull($service->resolveLive($second['raw_token'], 'crew_job_card'));
    }

    public function test_resolve_live_is_purpose_checked(): void
    {
        $card = $this->makeJobCard();
        $service = app(RentalSecureAccessTokenService::class);
        $issued = $service->issueForJobCard($card, $this->admin);

        $this->assertNull($service->resolveLive($issued['raw_token'], RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER));
        $this->assertNull($service->resolveLive($issued['raw_token'], RentalSecureAccessToken::PURPOSE_CREW_STANDING));
        $this->assertNull($service->resolveLive('not-a-token', 'crew_job_card'));
    }

    public function test_link_is_dead_when_revoked_expired_closed_archived_or_switched_off(): void
    {
        $service = app(RentalSecureAccessTokenService::class);
        $live = fn (string $raw) => $service->resolveLive($raw, 'crew_job_card') !== null;

        $card = $this->makeJobCard();
        $issued = $service->issueForJobCard($card, $this->admin);
        $this->assertTrue($live($issued['raw_token']));

        // switched off
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);
        $this->assertFalse($live($issued['raw_token']));
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => true]);
        $this->assertTrue($live($issued['raw_token']));

        // expired
        RentalSecureAccessToken::withoutGlobalScopes()->whereKey($issued['token']->id)->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($live($issued['raw_token']));
        RentalSecureAccessToken::withoutGlobalScopes()->whereKey($issued['token']->id)->update(['expires_at' => null]);
        $this->assertTrue($live($issued['raw_token']), 'null expires_at = no expiry');

        // revoked
        $service->revoke(RentalSecureAccessToken::withoutGlobalScopes()->find($issued['token']->id));
        $this->assertFalse($live($issued['raw_token']));

        // closed / cancelled / archived
        foreach ([RentalJobCard::STATUS_COMPLETED, RentalJobCard::STATUS_CANCELLED] as $status) {
            $c = $this->makeJobCard(['title' => "Closed {$status}"]);
            $i = $service->issueForJobCard($c, $this->admin);
            $this->assertTrue($live($i['raw_token']));
            $c->forceFill(['status' => $status])->save();
            $this->assertFalse($live($i['raw_token']), "a {$status} card kills its link");
        }
        $a = $this->makeJobCard(['title' => 'Archived']);
        $i = $service->issueForJobCard($a, $this->admin);
        $a->delete();
        $this->assertFalse($live($i['raw_token']), 'an archived card kills its link');
    }

    public function test_the_settings_defaults(): void
    {
        $id = $this->agency->id;
        $this->assertTrue(RentalPortalSetting::crewLinksEnabledFor($id));
        $this->assertSame(14, RentalPortalSetting::crewJobLinkExpiryDaysFor($id));
        $this->assertFalse(RentalPortalSetting::crewLinkShowPricesFor($id));
        $this->assertFalse(RentalPortalSetting::crewLinkShowTenantContactFor($id));
        $this->assertTrue(RentalPortalSetting::notifyLandlordOnCrewCompletionFor($id));
    }

    // ── archived work never reaches the crew (same rule as the crew page + office card) ──

    public function test_an_archived_task_and_an_archived_line_are_left_out_of_the_crew_view_and_materials(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        $this->assertCount(2, $this->service->payload($card, $ctx)['tasks']);
        $this->assertCount(1, $this->service->payload($card, $ctx)['materials']);
        $this->assertCount(1, $this->service->payload($card, $ctx)['labour']);

        // An archived TASK (with its live lines still on it) and an archived general LINE.
        $partTask = \App\Models\RentalJobCardTask::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('description', 'Drain the geyser')->firstOrFail();
        $partTask->delete();
        $general = \App\Models\RentalJobCardLine::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_job_card_id' => $card->id, 'type' => 'part',
            'description' => 'Washers', 'unit' => 'each', 'quantity' => 4, 'unit_price' => 5, 'line_total' => 20,
        ]);
        $this->assertCount(1, $this->service->payload($card, $ctx)['materials'], 'the live general line shows while the archived task\'s line does not');
        $general->delete();

        $payload = $this->service->payload($card, $ctx);
        $this->assertSame(['Refill and test'], array_column($payload['tasks'], 'description'));
        $this->assertSame([], $payload['materials'], 'the archived task\'s part line and the archived general line are not loaded');
        $this->assertCount(1, $payload['labour']);
    }

    public function test_an_archived_line_on_a_live_task_is_left_out(): void
    {
        $card = $this->makeJobCard();
        $ctx = $this->ctx($card);
        \App\Models\RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('type', 'labour')->firstOrFail()->delete();

        $payload = $this->service->payload($card, $ctx);

        $this->assertCount(2, $payload['tasks'], 'the live tasks stay');
        $this->assertSame([], $payload['labour']);
        $this->assertCount(1, $payload['materials']);
    }

    public function test_the_per_job_page_does_not_show_archived_work(): void
    {
        $card = $this->makeJobCard();
        \App\Models\RentalJobCardTask::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('description', 'Drain the geyser')->firstOrFail()->delete();
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];

        $this->get('/secure/job-cards/' . $raw)->assertOk()
            ->assertSee('Refill and test')
            ->assertDontSee('Drain the geyser')
            ->assertDontSee('Geyser element');
    }
}
