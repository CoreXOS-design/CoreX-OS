<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalJobCardClientViewService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Cleanup 7 Oct 2026 (.ai/specs/rental-work-orders.md §17.29):
 *   A. the office's photo form on the job card saves the photo and RETURNS TO THE CARD with a message (it used to show the raw
 *      JSON a script gets — the form posted to an endpoint that only ever answered JSON), errors included; a script that asks
 *      for JSON still gets JSON;
 *   B1. the work-order approval line is worded for the state the job is in (never "Why was this approved?" while it is waiting);
 *   B2. the crew link does not offer "Mark work completed" on a job nobody has authorised (the server refuses it either way).
 */
final class JobCardPhotoUploadAndWrongStateTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function photo(string $name = 'leak.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600);
    }

    // ── A. job-card photo upload ──────────────────────────────────────────────────────

    public function test_the_office_photo_form_saves_the_photo_and_returns_to_the_card_with_a_message(): void
    {
        $this->approvalWorld('Photo Upload');
        [$card] = $this->internalJob(100.0);

        $response = $this->actingAs($this->admin)->post(route('corex.rental-job-cards.photos.store', $card), [
            'photo_type' => 'completed', 'photo' => $this->photo(),
        ]);

        $response->assertRedirect(route('corex.rental-job-cards.show', $card))
            ->assertSessionHas('success', 'Completed photo uploaded.')
            ->assertSessionHas('jc_open_photos', true)
            ->assertSessionHasNoErrors();
        $saved = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->get();
        $this->assertCount(1, $saved);
        $this->assertSame('completed', $saved[0]->photo_type);
        $this->assertSame($this->admin->id, $saved[0]->uploaded_by_user_id);
        $this->assertSame($card->rental_work_order_id, $saved[0]->rental_work_order_id, 'cross-referenced onto the linked work order');

        // …and the card shows it, with the Photos block already open.
        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
        $this->assertStringContainsString($saved[0]->storage_path, $html);
        $this->assertMatchesRegularExpression('/<div id="jc-photos" class="space-y-3">/', $html, 'the photo block is open right after an upload');
    }

    public function test_a_normal_visit_to_the_card_still_has_the_photos_block_collapsed(): void
    {
        $this->approvalWorld('Photo Collapsed');
        [$card] = $this->internalJob(100.0);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();

        $this->assertStringContainsString('<div id="jc-photos" class="hidden space-y-3">', $html);
    }

    public function test_a_bad_upload_goes_back_to_the_card_with_the_message_and_saves_nothing(): void
    {
        $this->approvalWorld('Photo Errors');
        [$card] = $this->internalJob(100.0);
        $show = route('corex.rental-job-cards.show', $card);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.photos.store', $card), ['photo_type' => 'completed'])
            ->assertRedirect($show)->assertSessionHasErrors(['photo' => 'Choose a photo to upload.']);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.photos.store', $card), [
            'photo_type' => 'completed', 'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertRedirect($show)->assertSessionHasErrors(['photo' => 'The photo must be a JPG, PNG, WEBP or HEIC image.']);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.photos.store', $card), [
            'photo_type' => 'sideways', 'photo' => $this->photo(),
        ])->assertRedirect($show)->assertSessionHasErrors('photo_type');

        $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->count());
        $followed = $this->actingAs($this->admin)->followingRedirects()->post(route('corex.rental-job-cards.photos.store', $card), ['photo_type' => 'completed']);
        $followed->assertOk()->assertSee('Choose a photo to upload.');
    }

    public function test_a_script_that_asks_for_json_still_gets_json(): void
    {
        $this->approvalWorld('Photo Json');
        [$card] = $this->internalJob(100.0);

        $this->actingAs($this->admin)->postJson(route('corex.rental-job-cards.photos.store', $card), ['photo_type' => 'in_progress', 'photo' => $this->photo()])
            ->assertCreated()->assertJsonPath('photo_type', 'in_progress')->assertJsonPath('rental_job_card_id', $card->id);

        $this->actingAs($this->admin)->postJson(route('corex.rental-job-cards.photos.store', $card), ['photo_type' => 'in_progress'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_an_office_photo_reaches_the_tenant_and_landlord_by_the_agency_photo_rule(): void
    {
        // §14.27.1 Q7 / §14.29 — the rule is by photo TYPE, not by who uploaded: an office "completed" photo shows, a "before" one never does.
        $this->approvalWorld('Photo Clients');
        [$card] = $this->internalJob(100.0);
        foreach (['completed', 'in_progress', 'reported'] as $type) {
            $this->actingAs($this->admin)->post(route('corex.rental-job-cards.photos.store', $card), ['photo_type' => $type, 'photo' => $this->photo("{$type}.jpg")])->assertSessionHasNoErrors();
        }

        $visible = app(RentalJobCardClientViewService::class)->photosForCard($card->fresh())->pluck('photo_type')->sort()->values()->all();

        $this->assertSame(['completed', 'in_progress'], $visible);
    }

    public function test_another_agencys_job_card_cannot_be_uploaded_to(): void
    {
        $this->approvalWorld('Photo Scope');
        [$card] = $this->internalJob(100.0);
        $this->actingAs($this->otherAgencyAdmin())->post(route('corex.rental-job-cards.photos.store', $card), ['photo_type' => 'completed', 'photo' => $this->photo()])->assertNotFound();
        $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->count());
    }

    // ── B1. the approval line follows the state ───────────────────────────────────────

    public function test_the_approval_line_is_worded_for_the_state_of_the_job(): void
    {
        $this->approvalWorld('Approval Words');
        [$card] = $this->internalJob(1000.0);          // over the default R500 limit
        $wo = $this->sendQuote($card);                  // → waiting for the owner

        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
        foreach ([route('corex.rental-work-orders.show', $wo), route('corex.rental-job-cards.show', $card)] as $url) {
            $text = $this->visibleText($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
            $this->assertStringContainsString("Why is the owner's approval needed?", $text, $url);
            $this->assertStringNotContainsString('Why was this approved?', $text, $url);
        }

        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok by email']);
        foreach ([route('corex.rental-work-orders.show', $wo), route('corex.rental-job-cards.show', $card)] as $url) {
            $text = $this->visibleText($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
            $this->assertStringContainsString('Why was this approved?', $text, $url);
            $this->assertStringNotContainsString("Why is the owner's approval needed?", $text, $url);
        }
    }

    public function test_a_declined_job_and_a_job_with_no_decision_are_not_called_approved(): void
    {
        $this->approvalWorld('Approval Words Two');
        [$card] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'declined', 'evidence_type' => 'email', 'evidence_text' => 'too dear']);
        $text = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent());
        $this->assertStringContainsString('Why was this declined?', $text);
        $this->assertStringNotContainsString('Why was this approved?', $text);

        $external = $this->externalWorkOrder();
        $text = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $external))->assertOk()->getContent());
        $this->assertStringContainsString('Approval decision:', $text);
        $this->assertStringNotContainsString('Why was this approved?', $text);
    }

    // ── B2. the crew link and "Mark work completed" ───────────────────────────────────

    /** The page plus the address its "Mark work completed" form would post to (the class name also sits in the page's own CSS/JS). */
    private function crewLinkPage(RentalJobCard $card): array
    {
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];

        return [$this->get(url('/secure/job-cards/' . $raw))->assertOk()->getContent(), route('rentals.crew-job.complete', $raw)];
    }

    public function test_the_crew_link_does_not_offer_mark_completed_on_a_job_nobody_has_authorised(): void
    {
        $this->approvalWorld('Crew Unauthorised');
        [$card] = $this->internalJob(1000.0);     // over the limit, no quote approved → not authorised
        $this->sendQuote($card);
        $card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()])->save();

        [$html, $completeUrl] = $this->crewLinkPage($card->fresh());

        $this->assertStringNotContainsString('action="' . $completeUrl . '"', $html);
        $this->assertStringContainsString('This job has not been approved yet', $html);
        $this->assertStringNotContainsString('R1,000', $html, 'no amount ever reaches the crew page');
    }

    public function test_the_crew_link_offers_mark_completed_once_the_job_is_authorised(): void
    {
        $this->approvalWorld('Crew Authorised');
        [$card, $wo] = $this->internalJob(1000.0);
        $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok by email']);
        $card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()])->save();

        [$html, $completeUrl] = $this->crewLinkPage($card->fresh());

        $this->assertStringContainsString('action="' . $completeUrl . '"', $html);
        $this->assertStringNotContainsString('This job has not been approved yet', $html);
    }

    public function test_a_job_inside_the_no_approval_limit_is_offered_mark_completed(): void
    {
        $this->approvalWorld('Crew Inside Limit');
        [$card] = $this->internalJob(100.0);
        $card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()])->save();

        [$html, $completeUrl] = $this->crewLinkPage($card->fresh());
        $this->assertStringContainsString('action="' . $completeUrl . '"', $html);
    }

    public function test_the_server_still_refuses_a_hand_made_completion_on_an_unauthorised_job(): void
    {
        $this->approvalWorld('Crew Refused');
        [$card] = $this->internalJob(1000.0);
        $this->sendQuote($card);
        $card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()])->save();
        $raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($card->fresh(), $this->admin)['raw_token'];

        $this->post(route('rentals.crew-job.complete', $raw), ['full_name' => 'Pat Plumber', 'confirm' => 1])->assertSessionHasErrors();

        $this->assertNull($card->fresh()->worker_signed_off_at);
    }

    private function otherAgencyAdmin(): \App\Models\User
    {
        $agency = \App\Models\Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Other Branch', 'agency_id' => $agency->id]);

        return \App\Models\User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }
}
