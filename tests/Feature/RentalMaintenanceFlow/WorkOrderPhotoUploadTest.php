<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalJobCardClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 7 Oct 2026 (.ai/specs/rental-work-orders.md §17.30) — the work-order screen's photo form, the same defect the job card had
 * (§17.29 A): the form posted to an endpoint that only ever answered JSON, so a browser landed on a page of code. Now a normal
 * form post saves the photo and RETURNS TO THE WORK ORDER with a message and the thumbnail; errors come back the same way and
 * save nothing; a script that asks for JSON still gets JSON.
 */
final class WorkOrderPhotoUploadTest extends TestCase
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

    private function photoCount(int $workOrderId): int
    {
        return RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $workOrderId)->count();
    }

    public function test_the_office_photo_form_saves_the_photo_and_returns_to_the_work_order_with_a_message_and_the_thumbnail(): void
    {
        $this->approvalWorld('WO Photo Upload');
        $wo = $this->externalWorkOrder();
        $show = route('corex.rental-work-orders.show', $wo);

        $response = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), [
            'photo_type' => 'completed', 'photo' => $this->photo(),
        ]);

        $response->assertRedirect($show . '#wo-photos')
            ->assertSessionHas('wo_photo_message', 'Completed photo uploaded.')
            ->assertSessionHasNoErrors();
        $saved = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->get();
        $this->assertCount(1, $saved);
        $this->assertSame('completed', $saved[0]->photo_type);
        $this->assertSame($this->admin->id, $saved[0]->uploaded_by_user_id);
        $this->assertSame($this->agency->id, $saved[0]->agency_id);

        // …and the work order itself shows the message and the thumbnail.
        $followed = $this->actingAs($this->admin)->followingRedirects()->post(route('corex.rental-work-orders.photos.store', $wo), [
            'photo_type' => 'in_progress', 'photo' => $this->photo('second.jpg'),
        ]);
        $followed->assertOk()->assertSee('In progress photo uploaded.');
        $html = $followed->getContent();
        $this->assertStringContainsString('id="wo-photos"', $html);
        foreach (RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->get() as $p) {
            $this->assertStringContainsString($p->storage_path, $html);
        }
        $this->assertSame(2, $this->photoCount($wo->id));
    }

    public function test_each_photo_type_gets_its_own_plain_confirmation(): void
    {
        $this->approvalWorld('WO Photo Types');
        $wo = $this->externalWorkOrder();

        foreach (['reported' => 'Before', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $type => $label) {
            $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => $type, 'photo' => $this->photo("{$type}.jpg")])
                ->assertSessionHas('wo_photo_message', $label . ' photo uploaded.');
        }
        $this->assertSame(3, $this->photoCount($wo->id));
    }

    public function test_a_normal_visit_shows_no_photo_message(): void
    {
        $this->approvalWorld('WO Photo Quiet');
        $wo = $this->externalWorkOrder();

        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();

        $this->assertStringContainsString('id="wo-photos"', $html);
        $this->assertStringNotContainsString('photo uploaded.', $html);
    }

    public function test_a_bad_upload_goes_back_to_the_work_order_with_the_message_and_saves_nothing(): void
    {
        $this->approvalWorld('WO Photo Errors');
        $wo = $this->externalWorkOrder();
        $back = route('corex.rental-work-orders.show', $wo) . '#wo-photos';

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => 'completed'])
            ->assertRedirect($back)->assertSessionHasErrors(['photo' => 'Choose a photo to upload.'], null, 'photo');

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), [
            'photo_type' => 'completed', 'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertRedirect($back)->assertSessionHasErrors(['photo' => 'The photo must be a JPG, PNG, WEBP or HEIC image.'], null, 'photo');

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), [
            'photo_type' => 'sideways', 'photo' => $this->photo(),
        ])->assertRedirect($back)->assertSessionHasErrors('photo_type', null, 'photo');

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), [
            'photo_type' => 'completed', 'photo' => UploadedFile::fake()->create('huge.jpg', 52000, 'image/jpeg'),
        ])->assertRedirect($back)->assertSessionHasErrors(['photo' => 'That photo is too large — the limit is 50 MB.'], null, 'photo');

        $this->assertSame(0, $this->photoCount($wo->id));

        // The message is shown to the person, inside the Photos block — not in the page-wide banner.
        $followed = $this->actingAs($this->admin)->followingRedirects()->post(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => 'completed']);
        $followed->assertOk()->assertSee('Choose a photo to upload.');
        $this->assertStringNotContainsString('photo uploaded.', $followed->getContent());
    }

    public function test_a_script_that_asks_for_json_still_gets_json(): void
    {
        $this->approvalWorld('WO Photo Json');
        $wo = $this->externalWorkOrder();
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($this->admin)->postJson(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => 'in_progress', 'photo' => $this->photo(), 'client_idempotency_key' => $key])
            ->assertCreated()->assertJsonPath('photo_type', 'in_progress')->assertJsonPath('rental_work_order_id', $wo->id);

        // A repeat of the same client key is answered with the saved photo (200) and saves nothing new.
        $this->actingAs($this->admin)->postJson(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => 'in_progress', 'photo' => $this->photo(), 'client_idempotency_key' => $key])
            ->assertOk()->assertJsonPath('rental_work_order_id', $wo->id);
        $this->assertSame(1, $this->photoCount($wo->id));

        $this->actingAs($this->admin)->postJson(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => 'in_progress'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_a_repeat_of_the_same_form_post_is_not_saved_twice_and_says_so(): void
    {
        $this->approvalWorld('WO Photo Repeat');
        $wo = $this->externalWorkOrder();
        $key = (string) \Illuminate\Support\Str::uuid();
        $post = ['photo_type' => 'completed', 'client_idempotency_key' => $key];

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), $post + ['photo' => $this->photo()])->assertSessionHas('wo_photo_message', 'Completed photo uploaded.');
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), $post + ['photo' => $this->photo()])->assertSessionHas('wo_photo_message', 'That photo was already uploaded.');

        $this->assertSame(1, $this->photoCount($wo->id));
    }

    public function test_an_office_photo_reaches_the_tenant_and_landlord_by_the_agency_photo_rule(): void
    {
        // §14.27.1 Q7 / §14.29 — the rule is by photo TYPE, not by who uploaded: "completed" and "in progress" show, "before" never.
        $this->approvalWorld('WO Photo Clients');
        $wo = $this->externalWorkOrder();
        foreach (['completed', 'in_progress', 'reported'] as $type) {
            $this->actingAs($this->admin)->post(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => $type, 'photo' => $this->photo("{$type}.jpg")])->assertSessionHasNoErrors('photo');
        }

        $visible = app(RentalJobCardClientViewService::class)->photosForWorkOrder($wo->fresh())->pluck('photo_type')->sort()->values()->all();

        $this->assertSame(['completed', 'in_progress'], $visible);
    }

    public function test_another_agencys_work_order_cannot_be_uploaded_to(): void
    {
        $this->approvalWorld('WO Photo Scope');
        $wo = $this->externalWorkOrder();
        $agency = \App\Models\Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Other Branch', 'agency_id' => $agency->id]);
        $outsider = \App\Models\User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $this->actingAs($outsider)->post(route('corex.rental-work-orders.photos.store', $wo), ['photo_type' => 'completed', 'photo' => $this->photo()])->assertNotFound();
        $this->assertSame(0, $this->photoCount($wo->id));
    }
}
