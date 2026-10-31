<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31.5 (S5, one click per stage) - the contractor's link finishes a job in ONE form: the photos of the finished work and
 * "Work finished" are one submit, and what was found / the cause are optional (they never stop the job being reported done).
 */
final class OneFormJobDoneTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->approvalWorld('OneFormDone');
    }

    /** @return array{0: RentalWorkOrder, 1: string} an authorised job, ordered, with the raw link token */
    private function job(): array
    {
        $this->ownerLimits(500.0);
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'contractor_response' => 'accepted']);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        $wo = $wo->fresh();

        return [$wo, app(RentalSecureAccessTokenService::class)->issueFor($wo, $this->admin)['raw_token']];
    }

    public function test_the_finish_form_carries_the_photos_and_the_one_button(): void
    {
        [, $raw] = $this->job();
        $html = $this->get(route('rentals.secure-link.show', $raw))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<form[^>]*mark-done[^>]*enctype="multipart/form-data"[^>]*data-contractor-done-form>.*name="photos\[\]".*Work finished#s', $html);
    }

    public function test_photos_and_done_are_one_submit_and_the_photos_land_as_completed(): void
    {
        [$wo, $raw] = $this->job();

        $this->post(route('rentals.secure-link.mark-done', $raw), ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $types = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->pluck('photo_type')->all();
        $this->assertSame([RentalWorkOrder::PHOTO_COMPLETED, RentalWorkOrder::PHOTO_COMPLETED], $types);
        $this->assertTrue(app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($wo->fresh()), 'reported finished in the same press');
    }

    public function test_photos_are_optional_and_so_is_everything_else(): void
    {
        [$wo, $raw] = $this->job();

        $this->post(route('rentals.secure-link.mark-done', $raw), [])->assertSessionHasNoErrors();

        $this->assertSame(0, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
        $this->assertTrue(app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($wo->fresh()));
    }

    public function test_a_file_that_is_not_a_photo_is_refused_in_words_and_nothing_is_reported_done(): void
    {
        [$wo, $raw] = $this->job();

        $this->post(route('rentals.secure-link.mark-done', $raw), ['photos' => [UploadedFile::fake()->create('x.exe', 10)]])->assertSessionHasErrors('photos.0');

        $this->assertFalse(app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($wo->fresh()));
    }

    public function test_what_was_found_is_optional_on_the_link(): void
    {
        $fields = file_get_contents(resource_path('views/rentals/_report-back-fields.blade.php'));
        $this->assertStringNotContainsString('required minlength="3"', $fields);
        $this->assertStringContainsString('(optional)', $fields);
    }

    public function test_the_crew_form_is_photos_a_name_and_one_button_with_no_separate_tick(): void
    {
        $body = file_get_contents(resource_path('views/rentals/crew-link/_job-body.blade.php'));
        $this->assertStringContainsString('class="cj-complete-form" enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('name="photos[]"', $body);
        $this->assertStringNotContainsString('name="confirm"', $body, 'pressing Job done IS the confirmation');
        $this->assertStringContainsString("'Job done'", $body);
    }
}
