<?php

namespace Tests\Feature\Agents;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Images\AgentProfilePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Locks the invariant the photo desync bug violated: the file on disk, the
 * user_documents 'profile_photo' row, and the legacy agent_photo_path column
 * must always point at the SAME normalised .webp file. Before the service,
 * admin uploads updated only the column, leaving the document (which
 * profilePhotoUrl() and the P24 sync prefer) pointing at a deleted .jpg.
 */
class AgentProfilePhotoServiceTest extends TestCase
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

    public function test_set_writes_file_document_and_column_in_lockstep(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();

        $path = app(AgentProfilePhotoService::class)
            ->set($user, UploadedFile::fake()->image('me.jpg', 1000, 1000));

        $this->assertSame("agents/{$user->id}/photo.webp", $path);
        Storage::disk('public')->assertExists($path);

        $user->refresh();
        $this->assertSame($path, $user->agent_photo_path, 'column must match the file');

        $doc = $user->documents()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->latest()->first();

        $this->assertNotNull($doc, 'a profile_photo document must be created');
        $this->assertSame($path, $doc->file_path, 'document must match the file');
        $this->assertSame('image/webp', $doc->mime_type);
        $this->assertSame('verified', $doc->status);
    }

    public function test_set_updates_the_existing_document_rather_than_duplicating(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        $svc = app(AgentProfilePhotoService::class);

        $svc->set($user->fresh(), UploadedFile::fake()->image('one.jpg', 1000, 1000));
        $svc->set($user->fresh(), UploadedFile::fake()->image('two.jpg', 1200, 1200));

        $this->assertSame(
            1,
            $user->documents()->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)->count(),
            'a second upload must reuse the row, not create a duplicate'
        );
    }

    public function test_clear_removes_file_document_and_column_together(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        $svc = app(AgentProfilePhotoService::class);

        $path = $svc->set($user->fresh(), UploadedFile::fake()->image('me.jpg', 1000, 1000));

        $svc->clear($user->fresh());

        $user->refresh();
        $this->assertNull($user->agent_photo_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(
            0,
            $user->documents()->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)->count(),
            'the document row must be soft-deleted'
        );
    }
}
