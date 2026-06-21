<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\SharedDriveFile;
use App\Models\SharedDriveFolder;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared Drive — server-side proofs.
 *
 * Covers permission gating (upload/delete blocked for view-only roles),
 * file-type + size policy, recursive soft-delete, and multi-tenant
 * isolation. Spec: .ai/specs/shared-drive.md §8.
 */
final class SharedDriveTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private const FULL = [
        'access_shared_drive', 'shared_drive.view', 'shared_drive.upload',
        'shared_drive.download', 'shared_drive.folders.create',
        'shared_drive.folders.delete', 'shared_drive.files.delete',
    ];

    private const VIEW_ONLY = ['access_shared_drive', 'shared_drive.view'];

    public function test_permitted_user_can_browse_create_folder_and_upload(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);

        // Browse root
        $this->actingAs($user)->get(route('documents.shared-drive.index'))->assertOk();

        // Create folder
        $this->actingAs($user)
            ->post(route('documents.shared-drive.folders.store'), ['name' => 'Branch SOPs'])
            ->assertRedirect();

        $folder = SharedDriveFolder::where('name', 'Branch SOPs')->firstOrFail();
        $this->assertSame($user->agency_id, $folder->agency_id);

        // Upload a PDF into the folder (AJAX → JSON response)
        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $file, 'folder_id' => $folder->id])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $stored = SharedDriveFile::where('folder_id', $folder->id)->firstOrFail();
        $this->assertSame('policy.pdf', $stored->original_name);
        Storage::disk('local')->assertExists($stored->stored_path);
    }

    public function test_oversized_and_disallowed_files_are_rejected(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);

        // 51 MB PDF → rejected by max validation (JSON 422)
        $tooBig = UploadedFile::fake()->create('huge.pdf', 51201, 'application/pdf');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $tooBig])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        // Disallowed extension/mime → rejected by allow-list (JSON 422)
        $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $exe])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, SharedDriveFile::count());
    }

    public function test_view_only_user_cannot_upload_or_delete(): void
    {
        Storage::fake('local');
        $viewer = $this->userWithPermissions(self::VIEW_ONLY);

        $viewer && $this->actingAs($viewer)->get(route('documents.shared-drive.index'))->assertOk();

        // Upload blocked
        $file = UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
        $this->actingAs($viewer)
            ->post(route('documents.shared-drive.upload'), ['file' => $file])
            ->assertForbidden();

        // Folder create blocked
        $this->actingAs($viewer)
            ->post(route('documents.shared-drive.folders.store'), ['name' => 'Nope'])
            ->assertForbidden();

        // Bulk download (needs download perm) and bulk delete blocked
        $this->actingAs($viewer)
            ->post(route('documents.shared-drive.files.bulk-download'), ['ids' => [1]])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('documents.shared-drive.files.bulk-destroy'), ['ids' => [1]])
            ->assertForbidden();
    }

    public function test_bulk_delete_soft_deletes_only_selected_files(): void
    {
        $user = $this->userWithPermissions(self::FULL);

        $make = fn (string $name) => SharedDriveFile::create([
            'agency_id' => $user->agency_id, 'folder_id' => null,
            'original_name' => $name, 'stored_path' => 'shared_drive/x/' . $name,
            'extension' => 'pdf', 'bytes' => 1, 'uploaded_by_user_id' => $user->id,
        ]);
        $a = $make('a.pdf');
        $b = $make('b.pdf');
        $c = $make('c.pdf');

        $this->actingAs($user)
            ->delete(route('documents.shared-drive.files.bulk-destroy'), ['ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('shared_drive_files', ['id' => $a->id]);
        $this->assertSoftDeleted('shared_drive_files', ['id' => $b->id]);
        $this->assertNotSoftDeleted('shared_drive_files', ['id' => $c->id]);
    }

    public function test_bulk_download_of_multiple_files_returns_a_zip(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);

        foreach (['one.pdf', 'two.pdf'] as $name) {
            $this->actingAs($user)->post(route('documents.shared-drive.upload'), [
                'file' => UploadedFile::fake()->create($name, 10, 'application/pdf'),
            ])->assertOk();
        }

        $ids = SharedDriveFile::pluck('id')->all();
        $resp = $this->actingAs($user)->post(route('documents.shared-drive.files.bulk-download'), ['ids' => $ids]);

        $resp->assertOk();
        $disposition = (string) $resp->headers->get('content-disposition');
        $this->assertStringContainsString('.zip', strtolower($disposition));
    }

    public function test_deleting_folder_soft_deletes_descendants(): void
    {
        $user = $this->userWithPermissions(self::FULL);

        $parent = SharedDriveFolder::create(['agency_id' => $user->agency_id, 'name' => 'Parent', 'created_by_user_id' => $user->id]);
        $child  = SharedDriveFolder::create(['agency_id' => $user->agency_id, 'parent_id' => $parent->id, 'name' => 'Child', 'created_by_user_id' => $user->id]);
        $file   = SharedDriveFile::create([
            'agency_id' => $user->agency_id, 'folder_id' => $child->id,
            'original_name' => 'a.pdf', 'stored_path' => 'shared_drive/x/a.pdf',
            'extension' => 'pdf', 'bytes' => 1, 'uploaded_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->delete(route('documents.shared-drive.folders.destroy', $parent->id))
            ->assertRedirect();

        $this->assertSoftDeleted('shared_drive_folders', ['id' => $parent->id]);
        $this->assertSoftDeleted('shared_drive_folders', ['id' => $child->id]);
        $this->assertSoftDeleted('shared_drive_files', ['id' => $file->id]);
    }

    public function test_user_cannot_access_another_agencys_folder(): void
    {
        $userA = $this->userWithPermissions(self::FULL);
        $agencyB = $this->makeAgency();

        $foreignFolder = SharedDriveFolder::create([
            'agency_id' => $agencyB, 'name' => 'Secret', 'created_by_user_id' => $userA->id,
        ]);

        $this->actingAs($userA)
            ->get(route('documents.shared-drive.folder', $foreignFolder->id))
            ->assertNotFound();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeAgency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => 'SD-Agency-' . Str::random(6),
            'slug' => 'sd-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $id, 'agency_id' => $id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    /**
     * Create a user whose role holds exactly the given permission keys.
     * Inserting role_permissions rows flips PermissionService::$seeded so the
     * "unseeded = grant all" fallback no longer masks denials.
     */
    private function userWithPermissions(array $keys): User
    {
        $agencyId = $this->makeAgency();
        $role = 'sd_role_' . Str::random(6);

        DB::table('roles')->insert([
            'name' => $role, 'label' => 'SD Test', 'is_owner' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($keys as $key) {
            DB::table('nexus_permissions')->insertOrIgnore([
                'key' => $key, 'label' => $key, 'section' => 'shared-drive',
                'type' => 'action', 'module' => 'shared_drive', 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_permissions')->insert([
                'role' => $role, 'permission_key' => $key, 'scope' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        PermissionService::clearCache();

        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }
}
