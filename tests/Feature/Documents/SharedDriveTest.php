<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\SharedDrive;
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
 * Covers permission gating, file-type + size policy, recursive soft-delete,
 * multi-tenant isolation, and (v2) per-drive access control: restricted drives
 * are hidden from non-members, visible to members / creators / managers, and
 * creating a restricted drive needs the dedicated permission.
 * Spec: .ai/specs/shared-drive.md §8 + v2.
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

    private const DRIVES_FULL = [
        'access_shared_drive', 'shared_drive.view', 'shared_drive.upload',
        'shared_drive.download', 'shared_drive.folders.create',
        'shared_drive.folders.delete', 'shared_drive.files.delete',
        'shared_drive.drives.create', 'shared_drive.drives.create_restricted',
    ];

    private const VIEW_ONLY = ['access_shared_drive', 'shared_drive.view'];

    public function test_permitted_user_can_browse_create_folder_and_upload(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);

        // Browse the drive list (ensures the default drive too)
        $this->actingAs($user)->get(route('documents.shared-drive.index'))->assertOk();
        // Browse a drive
        $this->actingAs($user)->get(route('documents.shared-drive.drive', $drive))->assertOk();

        // Create folder in the drive
        $this->actingAs($user)
            ->post(route('documents.shared-drive.folders.store'), ['drive_id' => $drive, 'name' => 'Branch SOPs'])
            ->assertRedirect();

        $folder = SharedDriveFolder::where('name', 'Branch SOPs')->firstOrFail();
        $this->assertSame($user->agency_id, $folder->agency_id);
        $this->assertSame($drive, (int) $folder->drive_id);

        // Upload a PDF into the folder (AJAX → JSON response)
        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $file, 'drive_id' => $drive, 'folder_id' => $folder->id])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $stored = SharedDriveFile::where('folder_id', $folder->id)->firstOrFail();
        $this->assertSame('policy.pdf', $stored->original_name);
        $this->assertSame($drive, (int) $stored->drive_id);
        Storage::disk('local')->assertExists($stored->stored_path);
    }

    public function test_image_upload_is_allowed_and_flagged_as_image(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);

        $img = UploadedFile::fake()->create('photo.png', 200, 'image/png');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $img, 'drive_id' => $drive])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $stored = SharedDriveFile::where('original_name', 'photo.png')->firstOrFail();
        $this->assertTrue($stored->isImage());
        $this->assertTrue($stored->isViewableInline());
        Storage::disk('local')->assertExists($stored->stored_path);
    }

    public function test_oversized_and_disallowed_files_are_rejected(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);

        $tooBig = UploadedFile::fake()->create('huge.pdf', 51201, 'application/pdf');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $tooBig, 'drive_id' => $drive])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload');
        $this->actingAs($user)
            ->post(route('documents.shared-drive.upload'), ['file' => $exe, 'drive_id' => $drive])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, SharedDriveFile::count());
    }

    public function test_view_only_user_cannot_upload_or_delete(): void
    {
        Storage::fake('local');
        $viewer = $this->userWithPermissions(self::VIEW_ONLY);
        $drive = $this->makeDrive((int) $viewer->agency_id, (int) $viewer->id);

        $this->actingAs($viewer)->get(route('documents.shared-drive.index'))->assertOk();

        $file = UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');
        $this->actingAs($viewer)
            ->post(route('documents.shared-drive.upload'), ['file' => $file, 'drive_id' => $drive])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('documents.shared-drive.folders.store'), ['drive_id' => $drive, 'name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('documents.shared-drive.files.bulk-download'), ['drive_id' => $drive, 'ids' => [1]])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('documents.shared-drive.files.bulk-destroy'), ['drive_id' => $drive, 'ids' => [1]])
            ->assertForbidden();
    }

    public function test_bulk_delete_soft_deletes_only_selected_files(): void
    {
        $user = $this->userWithPermissions(self::FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);

        $make = fn (string $name) => SharedDriveFile::create([
            'agency_id' => $user->agency_id, 'drive_id' => $drive, 'folder_id' => null,
            'original_name' => $name, 'stored_path' => 'shared_drive/x/' . $name,
            'extension' => 'pdf', 'bytes' => 1, 'uploaded_by_user_id' => $user->id,
        ]);
        $a = $make('a.pdf');
        $b = $make('b.pdf');
        $c = $make('c.pdf');

        $this->actingAs($user)
            ->delete(route('documents.shared-drive.files.bulk-destroy'), ['drive_id' => $drive, 'ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('shared_drive_files', ['id' => $a->id]);
        $this->assertSoftDeleted('shared_drive_files', ['id' => $b->id]);
        $this->assertNotSoftDeleted('shared_drive_files', ['id' => $c->id]);
    }

    public function test_bulk_download_of_multiple_files_returns_a_zip(): void
    {
        Storage::fake('local');
        $user = $this->userWithPermissions(self::FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);

        foreach (['one.pdf', 'two.pdf'] as $name) {
            $this->actingAs($user)->post(route('documents.shared-drive.upload'), [
                'file' => UploadedFile::fake()->create($name, 10, 'application/pdf'),
                'drive_id' => $drive,
            ])->assertOk();
        }

        $ids = SharedDriveFile::pluck('id')->all();
        $resp = $this->actingAs($user)->post(route('documents.shared-drive.files.bulk-download'), ['drive_id' => $drive, 'ids' => $ids]);

        $resp->assertOk();
        $disposition = (string) $resp->headers->get('content-disposition');
        $this->assertStringContainsString('.zip', strtolower($disposition));
    }

    public function test_deleting_folder_soft_deletes_descendants(): void
    {
        $user = $this->userWithPermissions(self::FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);

        $parent = SharedDriveFolder::create(['agency_id' => $user->agency_id, 'drive_id' => $drive, 'name' => 'Parent', 'created_by_user_id' => $user->id]);
        $child  = SharedDriveFolder::create(['agency_id' => $user->agency_id, 'drive_id' => $drive, 'parent_id' => $parent->id, 'name' => 'Child', 'created_by_user_id' => $user->id]);
        $file   = SharedDriveFile::create([
            'agency_id' => $user->agency_id, 'drive_id' => $drive, 'folder_id' => $child->id,
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

    public function test_user_cannot_access_another_agencys_drive(): void
    {
        $userA = $this->userWithPermissions(self::FULL);
        $agencyB = $this->makeAgency();
        $foreignUser = $this->userInAgency($agencyB, self::FULL);
        $foreignDrive = $this->makeDrive($agencyB, (int) $foreignUser->id);

        $this->actingAs($userA)
            ->get(route('documents.shared-drive.drive', $foreignDrive))
            ->assertNotFound();
    }

    // ── v2: per-drive access control ───────────────────────────────────────

    public function test_restricted_drive_hidden_from_non_member_but_visible_to_member(): void
    {
        $agency = $this->makeAgency();
        $creator = $this->userInAgency($agency, self::DRIVES_FULL);
        $member  = $this->userInAgency($agency, self::VIEW_ONLY);
        $outsider = $this->userInAgency($agency, self::VIEW_ONLY);

        // Creator makes a restricted drive and invites the member.
        $this->actingAs($creator)
            ->post(route('documents.shared-drive.drives.store'), [
                'name' => 'Directors', 'is_restricted' => 1, 'user_ids' => [$member->id],
            ])->assertRedirect();

        $drive = SharedDrive::where('name', 'Directors')->firstOrFail();
        $this->assertTrue($drive->is_restricted);

        $this->actingAs($creator)->get(route('documents.shared-drive.drive', $drive->id))->assertOk();
        $this->actingAs($member)->get(route('documents.shared-drive.drive', $drive->id))->assertOk();
        $this->actingAs($outsider)->get(route('documents.shared-drive.drive', $drive->id))->assertForbidden();
    }

    public function test_manager_sees_restricted_drive_without_being_a_member(): void
    {
        $agency = $this->makeAgency();
        $creator = $this->userInAgency($agency, self::DRIVES_FULL);
        $manager = $this->userInAgency($agency, array_merge(self::VIEW_ONLY, ['shared_drive.drives.manage']));

        $this->actingAs($creator)
            ->post(route('documents.shared-drive.drives.store'), ['name' => 'Locked', 'is_restricted' => 1])
            ->assertRedirect();

        $drive = SharedDrive::where('name', 'Locked')->firstOrFail();
        $this->actingAs($manager)->get(route('documents.shared-drive.drive', $drive->id))->assertOk();
    }

    public function test_creating_restricted_drive_requires_permission(): void
    {
        // Has create but NOT create_restricted.
        $user = $this->userWithPermissions(array_merge(self::VIEW_ONLY, ['shared_drive.drives.create']));

        $this->actingAs($user)
            ->post(route('documents.shared-drive.drives.store'), ['name' => 'Nope', 'is_restricted' => 1])
            ->assertForbidden();

        $this->assertSame(0, SharedDrive::where('name', 'Nope')->count());

        // An open drive is fine.
        $this->actingAs($user)
            ->post(route('documents.shared-drive.drives.store'), ['name' => 'Open Drive'])
            ->assertRedirect();
        $this->assertSame(1, SharedDrive::where('name', 'Open Drive')->where('is_restricted', false)->count());
    }

    public function test_default_drive_cannot_be_deleted(): void
    {
        $user = $this->userWithPermissions(array_merge(self::DRIVES_FULL, ['shared_drive.drives.manage']));
        $defaultDrive = $this->makeDrive((int) $user->agency_id, (int) $user->id, false, true);

        $this->actingAs($user)
            ->delete(route('documents.shared-drive.drives.destroy', $defaultDrive))
            ->assertStatus(422);

        $this->assertNotSoftDeleted('shared_drives', ['id' => $defaultDrive]);
    }

    public function test_deleting_drive_soft_deletes_its_contents(): void
    {
        $user = $this->userWithPermissions(self::DRIVES_FULL);
        $drive = $this->makeDrive((int) $user->agency_id, (int) $user->id);
        $folder = SharedDriveFolder::create(['agency_id' => $user->agency_id, 'drive_id' => $drive, 'name' => 'F', 'created_by_user_id' => $user->id]);
        $file = SharedDriveFile::create([
            'agency_id' => $user->agency_id, 'drive_id' => $drive, 'folder_id' => $folder->id,
            'original_name' => 'a.pdf', 'stored_path' => 'shared_drive/x/a.pdf',
            'extension' => 'pdf', 'bytes' => 1, 'uploaded_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->delete(route('documents.shared-drive.drives.destroy', $drive))
            ->assertRedirect();

        $this->assertSoftDeleted('shared_drives', ['id' => $drive]);
        $this->assertSoftDeleted('shared_drive_folders', ['id' => $folder->id]);
        $this->assertSoftDeleted('shared_drive_files', ['id' => $file->id]);
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

    private function makeDrive(int $agencyId, int $creatorId, bool $restricted = false, bool $default = false): int
    {
        return (int) DB::table('shared_drives')->insertGetId([
            'agency_id'          => $agencyId,
            'name'               => $default ? 'General' : 'Drive-' . Str::random(5),
            'is_restricted'      => $restricted,
            'is_default'         => $default,
            'created_by_user_id' => $creatorId,
            'created_at'         => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Create a user whose role holds exactly the given permission keys, in a
     * fresh agency. Inserting role_permissions rows flips
     * PermissionService::$seeded so the "unseeded = grant all" fallback no
     * longer masks denials.
     */
    private function userWithPermissions(array $keys): User
    {
        return $this->userInAgency($this->makeAgency(), $keys);
    }

    /** Create a user with the given permission keys inside an existing agency. */
    private function userInAgency(int $agencyId, array $keys): User
    {
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
