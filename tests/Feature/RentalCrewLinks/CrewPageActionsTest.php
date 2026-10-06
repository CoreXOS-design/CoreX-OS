<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Events\Rentals\RentalJobCardCrewPhotosAdded;
use App\Mail\Rentals\RentalJobCardCrewCompletedLandlordMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Contact;
use App\Models\RentalCrew;
use App\Models\RentalCrewLinkEvent;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * rental-work-orders.md §14.29 — what a crew does on a card opened FROM the crew
 * page: tick, photos, mark work completed. Each goes through Build 1's
 * CrewJobService with a `crew_page` context and each leaves two marks: the
 * card's own history line ("via crew page — {crew}") and a row in the crew's
 * link log.
 *
 * Input paths proven: tick on/off (plain + XHR); a task id from another card is a
 * 404; a card that is not this crew's / is closed / is draft changes NOTHING;
 * photos — both types, caption, uploaded_via=crew_page, no actor, one history
 * line + one event per submit, idempotent client key, invalid type / 11 files /
 * non-image / no file all refused with nothing stored; complete — needs a typed
 * name AND the tick, stores name + time + IP + device + via=crew_page, does NOT
 * close the card, a second attempt is refused; the landlord email still goes
 * through the agency mailbox dispatcher for this route; completion + photos flow
 * into the tenant and landlord views.
 */
final class CrewPageActionsTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private string $raw;
    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Actions');
        $this->raw = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $this->card = $this->makeJobCard();
    }

    private function url(string $suffix = '', ?RentalJobCard $card = null): string
    {
        return "/secure/crews/{$this->raw}/job-cards/" . ($card ?? $this->card)->id . $suffix;
    }

    private function history(?RentalJobCard $card = null): string
    {
        return ($card ?? $this->card)->updates()->get()->map(fn ($u) => $u->update_type . ': ' . $u->note)->implode(' | ');
    }

    private function crewEvents(string $event): int
    {
        return RentalCrewLinkEvent::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->where('event', $event)->count();
    }

    // ── tick ────────────────────────────────────────────────────────────

    public function test_ticking_a_task_works_toggles_and_is_logged_on_the_card_and_in_the_crew_log(): void
    {
        $task = $this->card->tasks()->first();
        $this->assertFalse((bool) $task->is_done);

        $this->post($this->url("/tasks/{$task->id}/tick"))->assertRedirect($this->url());
        $this->assertTrue((bool) $task->fresh()->is_done);
        $this->assertNull($task->fresh()->done_by_user_id, 'a crew member has no CoreX user');
        $this->assertStringContainsString('via crew page — Team 1', $this->history());
        $this->assertSame(1, $this->crewEvents('action'));

        $this->post($this->url("/tasks/{$task->id}/tick"))->assertRedirect();
        $this->assertFalse((bool) $task->fresh()->is_done, 'a second tap unticks');
    }

    public function test_an_ajax_tick_answers_with_json_and_no_page_reload(): void
    {
        $task = $this->card->tasks()->first();

        $this->postJson($this->url("/tasks/{$task->id}/tick"))->assertOk()->assertJson(['is_done' => true]);
        $this->postJson($this->url("/tasks/{$task->id}/tick"))->assertOk()->assertJson(['is_done' => false]);
    }

    public function test_a_task_id_from_another_card_is_a_404_and_changes_nothing(): void
    {
        $other = $this->makeJobCard(['title' => 'Other job of the same crew']);
        $foreignTask = $other->tasks()->first();

        $this->post($this->url("/tasks/{$foreignTask->id}/tick"))->assertStatus(404);

        $this->assertFalse((bool) $foreignTask->fresh()->is_done);
    }

    public function test_an_archived_task_cannot_be_ticked(): void
    {
        $task = $this->card->tasks()->first();
        $task->delete();

        $this->post($this->url("/tasks/{$task->id}/tick"))->assertStatus(404);
    }

    // ── cards that are not theirs / not open: nothing happens ──────────

    public function test_actions_on_another_crews_card_a_draft_card_or_a_closed_card_change_nothing(): void
    {
        $theirCrew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 2', 'created_by_user_id' => $this->admin->id]);
        $theirs = $this->makeJobCard([], $theirCrew);
        $draft = $this->makeJobCard(['status' => 'draft']);
        $closed = $this->makeJobCard(['status' => 'completed', 'completed_at' => now()]);

        foreach ([$theirs, $draft, $closed] as $card) {
            $task = $card->tasks()->first();
            $this->post($this->url("/tasks/{$task->id}/tick", $card))->assertStatus(404)->assertSee('no longer available');
            $this->post($this->url('/photos', $card), ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('p.jpg')]])->assertStatus(404);
            $this->post($this->url('/complete', $card), ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertStatus(404);

            $this->assertFalse((bool) $task->fresh()->is_done, 'tick must not land');
            $this->assertNull($card->fresh()->worker_signed_off_at, 'completion must not land');
            $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->count(), 'photos must not land');
        }
    }

    // ── photos ──────────────────────────────────────────────────────────

    public function test_photos_are_stored_as_crew_page_uploads_with_caption_no_actor_one_history_line_and_one_event(): void
    {
        Event::fake([RentalJobCardCrewPhotosAdded::class]);

        $this->post($this->url('/photos'), [
            'photo_type' => 'in_progress',
            'caption' => 'Element removed',
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ])->assertRedirect($this->url())->assertSessionHas('success')->assertSessionHasNoErrors();

        $photos = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $p) {
            $this->assertSame('in_progress', $p->photo_type);
            $this->assertSame('crew_page', $p->uploaded_via);
            $this->assertSame('Element removed', $p->caption);
            $this->assertNull($p->uploaded_by_user_id);
        }
        $this->assertSame(1, $this->card->updates()->where('update_type', 'crew_photos_added')->count(), 'one history line per submit, not per file');
        $this->assertStringContainsString('via crew page — Team 1', $this->history());
        $this->assertSame(1, $this->crewEvents('action'));
        Event::assertDispatched(RentalJobCardCrewPhotosAdded::class, fn ($e) => $e->via === 'crew_page');
    }

    public function test_a_completed_photo_with_no_caption_is_fine(): void
    {
        $this->post($this->url('/photos'), ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('done.jpg')]])->assertSessionHasNoErrors();

        $p = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->firstOrFail();
        $this->assertSame('completed', $p->photo_type);
        $this->assertNull($p->caption);
    }

    public function test_a_reposted_client_key_cannot_double_post_a_photo(): void
    {
        $key = (string) \Illuminate\Support\Str::uuid();
        $send = fn () => $this->post($this->url('/photos'), ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->image('a.jpg')], 'client_keys' => [$key]]);

        $send();
        $send();

        $this->assertSame(1, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->count());
    }

    public function test_bad_photo_submissions_are_refused_with_a_plain_message_and_nothing_stored(): void
    {
        $count = fn () => RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->count();

        // "reported" is the tenant's before-photo type — never the crew's to add.
        $this->post($this->url('/photos'), ['photo_type' => 'reported', 'photos' => [UploadedFile::fake()->image('a.jpg')]])->assertSessionHasErrors('photo_type');
        // More than 10 at once.
        $this->post($this->url('/photos'), ['photo_type' => 'completed', 'photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11))])->assertSessionHasErrors('photos');
        // Not a photo.
        $this->post($this->url('/photos'), ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]])->assertSessionHasErrors('photos.0');
        // Nothing chosen.
        $this->post($this->url('/photos'), ['photo_type' => 'completed'])->assertSessionHasErrors('photos');
        // Malformed key.
        $this->post($this->url('/photos'), ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('a.jpg')], 'client_keys' => ['not-a-uuid']])->assertSessionHasErrors('client_keys.0');

        $this->assertSame(0, $count());
        $this->assertSame(0, $this->crewEvents('action'));
    }

    // ── mark work completed ────────────────────────────────────────────

    public function test_mark_completed_records_name_time_ip_device_and_via_and_does_not_close_the_card(): void
    {
        Event::fake([RentalJobCardCrewCompleted::class]);

        $this->withHeaders(['User-Agent' => 'CrewPhone/9.1 (Android 14)'])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->post($this->url('/complete'), ['full_name' => '  Sipho   Dlamini ', 'confirm' => '1'])
            ->assertRedirect($this->url())->assertSessionHas('success')->assertSessionHasNoErrors();

        $card = $this->card->fresh();
        $this->assertNotNull($card->worker_signed_off_at);
        $this->assertSame('Sipho Dlamini', $card->worker_sign_off_name, 'whitespace tidied');
        $this->assertSame('crew_page', $card->worker_sign_off_via);
        $this->assertSame('203.0.113.77', $card->worker_sign_off_ip);
        $this->assertSame('CrewPhone/9.1 (Android 14)', $card->worker_sign_off_device);
        $this->assertNull($card->worker_signed_off_by_user_id);
        $this->assertNotNull($card->worker_signed_off_at);
        // Crew completion NEVER closes the card — the agent's sign-off does.
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->status);
        $this->assertNull($card->completed_at);
        $this->assertNull($card->agent_signed_off_at);
        $this->assertSame(1, $this->crewEvents('action'));
        Event::assertDispatched(RentalJobCardCrewCompleted::class, fn ($e) => $e->via === 'crew_page' && $e->signedByName === 'Sipho Dlamini');
    }

    public function test_after_completing_the_card_stays_on_the_page_marked_work_completed_and_the_form_is_replaced(): void
    {
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);

        $this->get("/secure/crews/{$this->raw}")->assertOk()->assertSee('Work completed');
        $job = $this->get($this->url())->assertOk();
        $job->assertSee('Completed — signed by Sipho Dlamini');
        $job->assertDontSee('Mark work completed</button>', false);
    }

    public function test_completion_needs_a_typed_name_and_the_tick(): void
    {
        $this->post($this->url('/complete'), ['full_name' => '', 'confirm' => '1'])->assertSessionHasErrors('full_name');
        $this->post($this->url('/complete'), ['full_name' => 'S', 'confirm' => '1'])->assertSessionHasErrors('full_name');
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini'])->assertSessionHasErrors('confirm');
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '0'])->assertSessionHasErrors('confirm');
        $this->post($this->url('/complete'), ['full_name' => str_repeat('x', 200), 'confirm' => '1'])->assertSessionHasErrors('full_name');

        $this->assertNull($this->card->fresh()->worker_signed_off_at);
        $this->assertSame(0, $this->crewEvents('action'));
    }

    public function test_a_second_completion_is_refused_with_a_plain_message_and_does_not_overwrite_the_first(): void
    {
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);

        $this->post($this->url('/complete'), ['full_name' => 'Someone Else', 'confirm' => '1'])
            ->assertRedirect($this->url())->assertSessionHasErrors('crew');

        $this->assertSame('Sipho Dlamini', $this->card->fresh()->worker_sign_off_name);
        $this->assertSame(1, $this->crewEvents('action'), 'the refused attempt is not logged as an action');
    }

    public function test_the_agent_signoff_then_closes_the_card_and_the_link_into_it_dies(): void
    {
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);
        $this->card->fresh()->agentSignOff($this->admin);
        app(\App\Services\Rentals\RentalJobCardService::class)->complete($this->card->fresh(), $this->admin);

        $this->get($this->url())->assertStatus(404)->assertSee('no longer available');
        $this->get("/secure/crews/{$this->raw}")->assertOk()->assertDontSee('data-job-id="' . $this->card->id . '"', false);
    }

    // ── the landlord email and the client views ────────────────────────

    public function test_a_crew_page_completion_emails_the_landlord_through_the_agency_mailbox_dispatcher(): void
    {
        $fake = new class extends RentalMailDispatcher {
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $fake);
        Mail::fake();
        $landlord = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlordson', 'email' => 'landlord@example.invalid']);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();

        $this->assertCount(1, $fake->sent, 'the same landlord email as the per-job link — the work is done either way');
        $this->assertSame('landlord@example.invalid', $fake->sent[0][0]);
        $this->assertInstanceOf(RentalJobCardCrewCompletedLandlordMail::class, $fake->sent[0][1]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_crew_page_completion_and_photos_flow_into_the_tenant_and_landlord_portal_views(): void
    {
        $this->attachTenant($this->card, 'Tina', 'Tenant');
        $lease = \App\Models\Lease::withoutGlobalScopes()->findOrFail($this->card->fresh()->lease_id);
        $tenant = \App\Models\LeaseTenant::query()->where('lease_id', $lease->id)->firstOrFail()->contact;
        $landlord = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlordson', 'email' => 'landlord2@example.invalid']);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');
        foreach ([$tenant, $landlord] as $c) {
            $cu = \App\Models\ClientUser::create(['email' => $c->email, 'current_agency_id' => $this->agency->id]);
            $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();
        }

        $this->post($this->url('/photos'), ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->image('a.jpg')]]);
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\ClientUser::find($tenant->fresh()->client_user_id), ['client']);
        $t = $this->getJson('/api/v1/client/rentals/job-cards/' . $this->card->id)->assertOk();
        $t->assertJsonPath('job_card.crew_completion.signed_by', 'Sipho Dlamini')->assertJsonPath('job_card.crew_completion.via', 'crew_page');
        $this->assertCount(1, $t->json('job_card.photos'));

        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\ClientUser::find($landlord->fresh()->client_user_id), ['client']);
        $l = $this->getJson('/api/v1/client/rentals/landlord/job-cards/' . $this->card->id)->assertOk();
        $l->assertJsonPath('job_card.crew_completion.via', 'crew_page');
        $this->assertCount(1, $l->json('job_card.photos'));
    }

    public function test_every_action_tags_the_card_history_with_the_crew_and_the_page_not_the_job_link(): void
    {
        $task = $this->card->tasks()->first();
        $this->post($this->url("/tasks/{$task->id}/tick"));
        $this->post($this->url('/photos'), ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('a.jpg')]]);
        $this->post($this->url('/complete'), ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);

        $history = $this->history();
        // Tick and photos carry "via crew page — {crew}"; the completion line (written by
        // RentalJobCard::recordCrewCompletion, Build 1) says "via crew page" with the signer's name and IP.
        $this->assertSame(2, substr_count($history, 'via crew page — Team 1'), $history);
        $this->assertStringContainsString('crew_completed: Sipho Dlamini — via crew page', $history);
        $this->assertStringNotContainsString('via crew link', $history);
    }
}
