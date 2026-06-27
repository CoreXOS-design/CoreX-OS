<?php

namespace Tests\Feature\Agents;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The My Portal ID Copy upload accepts either a PDF or a front + back photo
 * pair. When photos are sent they MUST be combined into a single PDF stored as
 * ONE id_copy document — otherwise the portal's latest-per-type grouping would
 * only ever show one side and verifiers would lose the other.
 */
class IdCopyPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(): User
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
    }

    public function test_front_and_back_photos_become_one_id_copy_pdf(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();

        $response = $this->actingAs($user)->post('/corex/my-portal/upload', [
            'document_type' => 'id_copy',
            'id_upload_mode' => 'photo',
            'id_front' => UploadedFile::fake()->image('front.jpg', 800, 500),
            'id_back'  => UploadedFile::fake()->image('back.jpg', 800, 500),
        ]);

        $response->assertRedirect();

        $doc = UserDocument::where('user_id', $user->id)
            ->where('document_type', UserDocument::DOCUMENT_TYPE_ID_COPY)
            ->first();

        $this->assertNotNull($doc, 'An id_copy document should be created.');
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertSame('pending', $doc->status);
        Storage::disk('public')->assertExists($doc->file_path);
    }

    public function test_photo_mode_requires_both_sides(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();

        $response = $this->actingAs($user)->post('/corex/my-portal/upload', [
            'document_type' => 'id_copy',
            'id_upload_mode' => 'photo',
            'id_front' => UploadedFile::fake()->image('front.jpg', 800, 500),
            // id_back missing
        ]);

        $response->assertSessionHasErrors('id_back');
        $this->assertSame(0, UserDocument::where('user_id', $user->id)->count());
    }
}
