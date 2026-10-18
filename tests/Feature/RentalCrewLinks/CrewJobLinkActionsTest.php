<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.28 — what the crew can DO from the link,
 * through the real public routes: tick, photo upload (types / limits /
 * idempotent client keys), mark completed (typed name + tick; records
 * timestamp / IP / device / via; never closes the card), and the refusals once
 * the card is closed.
 */
final class CrewJobLinkActionsTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Actions');
        $this->card = $this->makeJobCard();
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin);
        $this->base = '/secure/job-cards/' . $issued['raw_token'];
    }

    // ── tick ────────────────────────────────────────────────────────────

    public function test_tick_toggles_a_task_and_answers_json_for_the_page_script(): void
    {
        $task = $this->card->tasks()->first();

        $this->postJson("{$this->base}/tasks/{$task->id}/tick")->assertOk()->assertJson(['is_done' => true]);
        $this->assertTrue($task->fresh()->is_done);

        $this->postJson("{$this->base}/tasks/{$task->id}/tick")->assertOk()->assertJson(['is_done' => false]);
    }

    public function test_a_plain_form_tick_redirects_back_to_the_job(): void
    {
        $task = $this->card->tasks()->first();

        $this->post("{$this->base}/tasks/{$task->id}/tick")->assertRedirect($this->base);
        $this->assertTrue($task->fresh()->is_done);
    }

    // ── photos ──────────────────────────────────────────────────────────

    public function test_photos_upload_with_type_caption_and_via(): void
    {
        $this->post("{$this->base}/photos", [
            'photo_type' => 'completed', 'caption' => 'New element in',
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ])->assertRedirect($this->base)->assertSessionHas('success', '2 photos added.');

        $photos = RentalWorkOrderPhoto::where('rental_job_card_id', $this->card->id)->get();
        $this->assertCount(2, $photos);
        $this->assertSame('completed', $photos[0]->photo_type);
        $this->assertSame('New element in', $photos[0]->caption);
        $this->assertSame('crew_link', $photos[0]->uploaded_via);
        $this->assertNull($photos[0]->uploaded_by_user_id);
    }

    public function test_a_re_sent_client_key_does_not_double_post(): void
    {
        $key = '5d2c0c4e-6f0e-4f55-a8a7-1d3b6a9c7e21';
        $payload = fn () => ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->image('a.jpg')], 'client_keys' => [$key]];

        $this->post("{$this->base}/photos", $payload())->assertRedirect();
        $this->post("{$this->base}/photos", $payload())->assertRedirect()->assertSessionHas('success', 'Those photos were already added.');

        $this->assertSame(1, RentalWorkOrderPhoto::where('rental_job_card_id', $this->card->id)->count());
    }

    public function test_photo_validation_type_count_and_file_kind(): void
    {
        $this->post("{$this->base}/photos", ['photo_type' => 'reported', 'photos' => [UploadedFile::fake()->image('a.jpg')]])
            ->assertSessionHasErrors('photo_type');
        $this->post("{$this->base}/photos", ['photo_type' => 'in_progress'])->assertSessionHasErrors('photos');
        $this->post("{$this->base}/photos", ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors('photos.0');
        $eleven = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11));
        $this->post("{$this->base}/photos", ['photo_type' => 'in_progress', 'photos' => $eleven])->assertSessionHasErrors('photos');
        $this->post("{$this->base}/photos", ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->image('big.jpg')->size(51201)]])
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, RentalWorkOrderPhoto::where('rental_job_card_id', $this->card->id)->count());
    }

    // ── mark completed ──────────────────────────────────────────────────

    public function test_mark_completed_records_name_ip_device_and_via_and_does_not_close_the_card(): void
    {
        Event::fake([RentalJobCardCrewCompleted::class]);

        $this->withHeaders(['User-Agent' => 'CrewPhone/9.1'])->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])
            ->assertRedirect($this->base)->assertSessionHas('success');

        $card = $this->card->fresh();
        $this->assertSame('Sipho Dlamini', $card->worker_sign_off_name);
        $this->assertNotNull($card->worker_signed_off_at);
        $this->assertSame('crew_link', $card->worker_sign_off_via);
        $this->assertSame('198.51.100.7', $card->worker_sign_off_ip);
        $this->assertSame('CrewPhone/9.1', $card->worker_sign_off_device);
        $this->assertNull($card->worker_signed_off_by_user_id);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->status);
        $this->assertNull($card->completed_at);
        $this->assertNull($card->agent_signed_off_at);
        Event::assertDispatched(RentalJobCardCrewCompleted::class);

        // the link is still live so late photos can be added, and the button is gone
        $this->get($this->base)->assertOk()->assertSee('Completed — signed by Sipho Dlamini')->assertDontSee('class="cj-complete-form"', false);
        $this->post("{$this->base}/photos", ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('late.jpg')]])->assertRedirect();
        $this->assertSame(1, RentalWorkOrderPhoto::where('rental_job_card_id', $this->card->id)->count());
    }

    public function test_mark_completed_needs_the_name_and_the_tick(): void
    {
        $this->post("{$this->base}/complete", ['full_name' => '', 'confirm' => '1'])->assertSessionHasErrors('full_name');
        $this->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini'])->assertSessionHasErrors('confirm');

        $this->assertNull($this->card->fresh()->worker_signed_off_at);
    }

    public function test_a_second_completion_from_the_link_is_refused(): void
    {
        $this->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);
        $this->post("{$this->base}/complete", ['full_name' => 'Someone Else', 'confirm' => '1'])->assertSessionHasErrors('full_name');

        $this->assertSame('Sipho Dlamini', $this->card->fresh()->worker_sign_off_name);
    }

    public function test_every_action_is_in_the_card_history_with_no_user_actor(): void
    {
        $task = $this->card->tasks()->first();
        $this->postJson("{$this->base}/tasks/{$task->id}/tick");
        $this->post("{$this->base}/photos", ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->image('a.jpg')]]);
        $this->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);
        $this->get($this->base);

        $types = $this->card->updates()->pluck('update_type')->all();
        foreach (['link_issued', 'task_ticked', 'crew_photos_added', 'crew_completed'] as $expected) {
            $this->assertContains($expected, $types);
        }
        $crewRows = $this->card->updates()->whereIn('update_type', ['task_ticked', 'crew_photos_added', 'crew_completed'])->get();
        $this->assertTrue($crewRows->every(fn ($u) => $u->created_by_user_id === null));
        $this->assertTrue($crewRows->contains(fn ($u) => str_contains((string) $u->note, 'via crew link — Team 1')));
    }

    // ── closed cards ────────────────────────────────────────────────────

    public function test_once_the_card_is_closed_every_action_is_refused_as_unavailable(): void
    {
        $task = $this->card->tasks()->first();
        $this->card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        $this->postJson("{$this->base}/tasks/{$task->id}/tick")->assertOk()->assertDontSee('is_done');
        $this->post("{$this->base}/photos", ['photo_type' => 'in_progress', 'photos' => [UploadedFile::fake()->image('a.jpg')]])->assertSee('no longer available');
        $this->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSee('no longer available');

        $this->assertFalse($task->fresh()->is_done);
        $this->assertSame(0, RentalWorkOrderPhoto::where('rental_job_card_id', $this->card->id)->count());
        $this->assertNull($this->card->fresh()->worker_signed_off_at);
    }
}
