<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Events\Rentals\RentalJobCardSignedCopyUploaded;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardSignedCopy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 / §14.28 — the wet-ink route: pdf /
 * jpg / png only, 10240 KB, PRIVATE disk, scoped download, an earlier upload is
 * kept and marked Superseded (never deleted), the upload records the same
 * worker sign-off as the link (via = signed_copy) and never closes the card;
 * refused on a cancelled card, filed-only on a completed one.
 */
final class RentalJobCardSignedCopyTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Signed Copy');
        $this->card = $this->makeJobCard();
    }

    private function upload(UploadedFile $file, string $by = 'Sipho Dlamini', ?RentalJobCard $card = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(
            route('corex.rental-job-cards.signed-copy.store', $card ?? $this->card),
            ['signed_copy' => $file, 'signed_by_name' => $by],
        );
    }

    public function test_a_pdf_is_stored_privately_and_records_the_crew_completion_without_closing_the_card(): void
    {
        Event::fake([RentalJobCardSignedCopyUploaded::class, RentalJobCardCrewCompleted::class]);

        $this->upload(UploadedFile::fake()->create('signed.pdf', 200, 'application/pdf'))->assertRedirect()->assertSessionHasNoErrors();

        $copy = RentalJobCardSignedCopy::firstOrFail();
        $this->assertSame($this->card->id, $copy->rental_job_card_id);
        $this->assertSame('signed.pdf', $copy->original_name);
        $this->assertSame('Sipho Dlamini', $copy->signed_by_name);
        $this->assertSame($this->admin->id, $copy->uploaded_by_user_id);
        $this->assertNull($copy->superseded_at);
        Storage::disk('local')->assertExists($copy->storage_path);
        Storage::disk('public')->assertMissing($copy->storage_path);
        $this->assertStringStartsWith("rental-job-card-signed-copies/{$this->card->id}/", $copy->storage_path);

        $card = $this->card->fresh();
        $this->assertSame('Sipho Dlamini', $card->worker_sign_off_name);
        $this->assertSame('signed_copy', $card->worker_sign_off_via);
        $this->assertSame($this->admin->id, $card->worker_signed_off_by_user_id);
        $this->assertNotNull($card->worker_sign_off_ip);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->status, 'a signed copy never closes the card');
        $this->assertNull($card->completed_at);

        $types = $card->updates()->pluck('update_type')->all();
        $this->assertContains('signed_copy_uploaded', $types);
        $this->assertContains('crew_completed', $types);
        Event::assertDispatched(RentalJobCardSignedCopyUploaded::class);
        Event::assertDispatched(RentalJobCardCrewCompleted::class, fn ($e) => $e->via === 'signed_copy');
    }

    public function test_jpg_and_png_are_accepted(): void
    {
        $this->upload(UploadedFile::fake()->image('scan.jpg'))->assertSessionHasNoErrors();
        $this->upload(UploadedFile::fake()->image('scan.png'))->assertSessionHasNoErrors();

        $this->assertSame(2, RentalJobCardSignedCopy::count());
    }

    public function test_other_file_kinds_and_oversize_files_are_rejected(): void
    {
        $this->upload(UploadedFile::fake()->create('notes.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->assertSessionHasErrors('signed_copy');
        $this->upload(UploadedFile::fake()->create('notes.txt', 5, 'text/plain'))->assertSessionHasErrors('signed_copy');
        $this->upload(UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'))->assertSessionHasErrors('signed_copy');
        $this->upload(UploadedFile::fake()->create('ok.pdf', 10240, 'application/pdf'))->assertSessionHasNoErrors();

        $this->assertSame(1, RentalJobCardSignedCopy::count());
    }

    public function test_the_signed_by_name_is_required(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.signed-copy.store', $this->card), [
            'signed_copy' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('signed_by_name');
        $this->assertSame(0, RentalJobCardSignedCopy::count());
    }

    public function test_a_newer_upload_keeps_and_supersedes_the_earlier_one_never_deleting_it(): void
    {
        $this->upload(UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'));
        $first = RentalJobCardSignedCopy::firstOrFail();

        $this->upload(UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'), 'Thabo Nkosi');

        $this->assertSame(2, RentalJobCardSignedCopy::count());
        $second = RentalJobCardSignedCopy::where('original_name', 'second.pdf')->firstOrFail();
        $first = $first->fresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($second->id, $first->superseded_by_id);
        $this->assertNull($second->superseded_at);
        Storage::disk('local')->assertExists($first->storage_path);
        Storage::disk('local')->assertExists($second->storage_path);
        $this->assertNull($first->deleted_at, 'no hard delete, no archive');
        $this->assertSame(1, $this->card->updates()->where('update_type', 'signed_copy_superseded')->count());
        // the later upload's name overwrites the crew completion
        $this->assertSame('Thabo Nkosi', $this->card->fresh()->worker_sign_off_name);
    }

    public function test_the_download_is_authenticated_and_scoped_to_the_card(): void
    {
        $this->upload(UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'));
        $copy = RentalJobCardSignedCopy::firstOrFail();
        $other = $this->makeJobCard(['title' => 'Other card']);

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.signed-copy.download', [$this->card, $copy->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // the same copy id under another card is a 404
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.signed-copy.download', [$other, $copy->id]))->assertNotFound();

        auth()->logout();
        $this->get(route('corex.rental-job-cards.signed-copy.download', [$this->card, $copy->id]))->assertRedirect();
    }

    public function test_an_own_scope_user_cannot_download_or_upload_against_another_users_card(): void
    {
        $agent = $this->agentWith(['rental_job_cards.view' => 'own', 'rental_job_cards.sign_off' => 'own']);

        $this->upload(UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'));
        $copy = RentalJobCardSignedCopy::firstOrFail();

        $this->actingAs($agent)->get(route('corex.rental-job-cards.signed-copy.download', [$this->card, $copy->id]))->assertForbidden();
        $this->upload(UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'), 'X Y', $this->card, $agent)->assertForbidden();
        $this->assertSame(1, RentalJobCardSignedCopy::count());
    }

    public function test_without_the_sign_off_permission_the_upload_is_forbidden(): void
    {
        $agent = $this->agentWith(['rental_job_cards.view' => 'all']);

        $this->upload(UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'), 'X Y', $this->card, $agent)->assertForbidden();
        $this->assertSame(0, RentalJobCardSignedCopy::count());
    }

    public function test_refused_on_a_cancelled_card(): void
    {
        $this->card->cancel($this->admin, 'not needed');

        $this->upload(UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'))->assertSessionHasErrors('signed_copy');
        $this->assertSame(0, RentalJobCardSignedCopy::count());
    }

    public function test_allowed_on_a_completed_card_and_only_files_the_paper(): void
    {
        Event::fake([RentalJobCardCrewCompleted::class]);
        $this->card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->upload(UploadedFile::fake()->create('late.pdf', 10, 'application/pdf'))->assertSessionHasNoErrors();

        $this->assertSame(1, RentalJobCardSignedCopy::count());
        $card = $this->card->fresh();
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $card->status);
        $this->assertNull($card->worker_signed_off_at, 'a completed card\'s completion is not changed');
        Event::assertNotDispatched(RentalJobCardCrewCompleted::class);
    }

    public function test_the_panel_lists_current_and_superseded_copies(): void
    {
        $this->upload(UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'));
        $this->upload(UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'));

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))
            ->assertOk()->assertSee('first.pdf')->assertSee('second.pdf')->assertSee('Superseded')->assertSee('Current')
            ->assertSee('Upload a newer signed copy');
    }
}
