<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Compliance\PpraEmploymentLetterFile;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\PpraEmploymentLetterPdfService;
use App\Services\Compliance\PpraEmploymentLetterService;
use App\Services\Compliance\PractitionerFfcRosterService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PPRA employment letter — wet-ink flow (.ai/specs/ppra-ffc-employment-letter.md §20): print → sign on paper →
 * upload the signed copy from EITHER screen. The scan is ONE record (ppra_employment_letter_files, reached through
 * PpraEmploymentLetter::files()/currentFile()), so an upload on one screen shows on the other.
 */
final class PpraEmploymentLetterWetInkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $principal;
    private User $agent;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();

        $this->agency = Agency::create(['name' => 'Southern Cape Realty', 'slug' => 'southern-cape-realty', 'ppra_number' => 'F999999']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Hermanus']);

        // Explicit grants (the table is not "unseeded"): agent own, branch_manager branch, admin all; manage for
        // admin + branch_manager; the Role Manager "can receive" tick for agent + admin.
        $scopes = ['agent' => 'own', 'branch_manager' => 'branch', 'admin' => 'all'];
        foreach ($scopes as $role => $scope) {
            foreach (['access_my_portal', 'ppra_employment_letters.view', 'ppra_employment_letters.create'] as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
            }
        }
        foreach (['admin', 'branch_manager'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'ppra_employment_letters.manage', 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        foreach (['agent', 'admin'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => PractitionerFfcRosterService::LETTER_PERMISSION, 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $this->principal = $this->user(['name' => 'Pat Principal', 'designation' => 'Principal', 'is_principal_practitioner' => true, 'ffc_number' => '7654321']);
        $this->agent     = $this->user(['name' => 'Alice Agent']);
        $this->admin     = $this->user(['name' => 'Ann Admin', 'role' => 'admin']);
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'agency_id'   => $this->agency->id,
            'branch_id'   => $this->branch->id,
            'role'        => 'agent',
            'designation' => 'Property Practitioner',
            'id_number'   => '9001015800088',
            'ffc_number'  => '1234567',
            'is_active'   => true,
        ], $attrs));
    }

    private function letter(?User $agent = null): PpraEmploymentLetter
    {
        return app(PpraEmploymentLetterService::class)->create($agent ?? $this->agent, $agent ?? $this->agent, $this->principal->id);
    }

    private function pdf(string $name = 'signed.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    private function adminUpload(PpraEmploymentLetter $letter, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)
            ->post(route('admin.ppra-employment-letters.upload', $letter->id), ['signed_copy' => $file]);
    }

    private function portalUpload(PpraEmploymentLetter $letter, UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)
            ->post(route('ppra-employment-letters.upload', $letter->id), ['signed_copy' => $file]);
    }

    // ── ONE record: an upload on either screen shows on the other ────────────

    public function test_upload_on_the_admin_register_shows_in_the_agents_my_portal_same_file_same_status(): void
    {
        $letter = $this->letter();
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, $letter->status);

        $this->adminUpload($letter, $this->pdf('from-admin.pdf'))->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_SIGNED_COPY_FILED, $letter->status);
        $this->assertSame(1, PpraEmploymentLetterFile::count());
        $file = $letter->currentFile;
        $this->assertSame('from-admin.pdf', $file->original_name);
        $this->assertSame(PpraEmploymentLetterFile::VIA_ADMIN, $file->uploaded_via);
        $this->assertSame($this->admin->id, $file->uploaded_by_user_id);
        Storage::disk('local')->assertExists($file->path);

        $portal = $this->actingAs($this->agent)->get(route('agent.portal'))->assertOk();
        $portal->assertSee('Signed copy filed');
        $portal->assertSee('from-admin.pdf');
        $portal->assertSee(route('ppra-employment-letters.signed-copy', [$letter->id, $file->id]), false);

        // The agent opens the very same bytes the admin uploaded.
        $stored = Storage::disk('local')->get($file->path);
        $this->assertSame($stored, $this->actingAs($this->agent)
            ->get(route('ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertOk()->streamedContent());
        $this->assertSame($stored, $this->actingAs($this->admin)
            ->get(route('admin.ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertOk()->streamedContent());
    }

    public function test_upload_from_my_portal_shows_on_the_admin_register_list_and_detail(): void
    {
        $letter = $this->letter();

        $this->portalUpload($letter, $this->pdf('from-agent.pdf'))->assertRedirect();

        $letter->refresh();
        $this->assertSame(PpraEmploymentLetter::STATUS_SIGNED_COPY_FILED, $letter->status);
        $file = $letter->currentFile;
        $this->assertSame(PpraEmploymentLetterFile::VIA_PORTAL, $file->uploaded_via);
        $this->assertSame($this->agent->id, $file->uploaded_by_user_id);

        $list = $this->actingAs($this->admin)->get(route('admin.ppra-employment-letters.index'))->assertOk();
        $list->assertSee('Signed copy filed');
        $list->assertSee('from-agent.pdf');

        $detail = $this->actingAs($this->admin)->get(route('admin.ppra-employment-letters.show', $letter->id))->assertOk();
        $detail->assertSee('from-agent.pdf');
        $detail->assertSee('Alice Agent'); // who uploaded it
        $detail->assertSee(route('admin.ppra-employment-letters.signed-copy', [$letter->id, $file->id]), false);
    }

    public function test_reupload_from_the_other_screen_keeps_one_current_and_marks_earlier_ones_superseded(): void
    {
        $letter = $this->letter();

        $this->adminUpload($letter, $this->pdf('first.pdf'));
        $first = $letter->fresh()->currentFile;
        $this->portalUpload($letter, $this->pdf('second.pdf'));

        $letter->refresh()->load('files.uploader');
        $this->assertSame(PpraEmploymentLetter::STATUS_SIGNED_COPY_FILED, $letter->status, 'stays filed on re-upload');
        $this->assertCount(2, $letter->files);
        $this->assertSame('second.pdf', $letter->currentFile->original_name);
        $this->assertSame('first.pdf', $letter->files->last()->original_name);
        // Nothing overwritten or deleted — the earlier file is still on disk and still a live row.
        Storage::disk('local')->assertExists($first->path);
        $this->assertSame(2, PpraEmploymentLetterFile::withTrashed()->count());
        $this->assertSame(0, PpraEmploymentLetterFile::onlyTrashed()->count());

        $html = $this->actingAs($this->admin)->get(route('admin.ppra-employment-letters.show', $letter->id))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'ppra-copy-current'), 'exactly one Current');
        $this->assertSame(1, substr_count($html, 'ppra-copy-superseded'));
        $this->assertStringContainsString('Superseded', $html);
        $this->assertStringContainsString('first.pdf', $html);
        $this->assertStringContainsString('Alice Agent', $html); // who replaced it (date + who)

        // The earlier upload stays downloadable through the same gate, on both screens.
        $bytes = Storage::disk('local')->get($first->path);
        $this->assertSame($bytes, $this->actingAs($this->admin)
            ->get(route('admin.ppra-employment-letters.signed-copy', [$letter->id, $first->id]))->assertOk()->streamedContent());
        $this->assertSame($bytes, $this->actingAs($this->agent)
            ->get(route('ppra-employment-letters.signed-copy', [$letter->id, $first->id]))->assertOk()->streamedContent());

        // A third upload (admin again): still exactly one Current.
        $this->adminUpload($letter, $this->pdf('third.pdf'));
        $letter->refresh()->load('files');
        $this->assertCount(3, $letter->files);
        $this->assertSame('third.pdf', $letter->currentFile->original_name);
        $html = $this->actingAs($this->agent)->get(route('ppra-employment-letters.show', $letter))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'ppra-copy-current'));
        $this->assertSame(2, substr_count($html, 'ppra-copy-superseded'));
    }

    public function test_both_list_queries_eager_load_the_files(): void
    {
        $letter = $this->letter();
        $this->adminUpload($letter, $this->pdf());

        $admin = $this->actingAs($this->admin)->get(route('admin.ppra-employment-letters.index'))->assertOk();
        $row = $admin->viewData('letters')->first();
        $this->assertTrue($row->relationLoaded('files'));
        $this->assertTrue($row->relationLoaded('currentFile'));

        $portal = $this->actingAs($this->agent)->get(route('agent.portal'))->assertOk();
        $mine = $portal->viewData('ppraLetters')->first();
        $this->assertTrue($mine->relationLoaded('files'));
        $this->assertTrue($mine->relationLoaded('currentFile'));
    }

    // ── Statuses ─────────────────────────────────────────────────────────────

    public function test_status_labels_are_neutral_and_legacy_statuses_display_sensibly(): void
    {
        foreach ([PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, PpraEmploymentLetter::STATUS_DRAFT,
                  PpraEmploymentLetter::STATUS_AWAITING_AGENT_SIGNATURE, PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE] as $s) {
            $this->assertSame('Awaiting signed copy', PpraEmploymentLetter::statusLabel($s));
        }
        $this->assertSame('Signed copy filed', PpraEmploymentLetter::statusLabel(PpraEmploymentLetter::STATUS_SIGNED_COPY_FILED));
        $this->assertSame('Signed (electronic)', PpraEmploymentLetter::statusLabel(PpraEmploymentLetter::STATUS_SIGNED));
        $this->assertStringNotContainsString('your', strtolower(PpraEmploymentLetter::statusLabel(PpraEmploymentLetter::STATUS_AWAITING_AGENT_SIGNATURE)));
    }

    public function test_a_letter_left_awaiting_principal_signature_by_the_old_flow_accepts_an_upload_and_the_status_filter_groups_it(): void
    {
        $legacy = $this->letter();
        $legacy->forceFill(['status' => PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE])->save();
        $filed = $this->letter($this->user(['name' => 'Bob Agent']));
        $this->adminUpload($filed, $this->pdf());

        $awaiting = $this->actingAs($this->admin)
            ->get(route('admin.ppra-employment-letters.index', ['status' => 'awaiting_signed_copy']))->assertOk();
        $ids = $awaiting->viewData('letters')->pluck('id')->all();
        $this->assertContains($legacy->id, $ids);
        $this->assertNotContains($filed->id, $ids);

        $this->portalUpload($legacy, $this->pdf('legacy.pdf'))->assertRedirect();
        $this->assertSame(PpraEmploymentLetter::STATUS_SIGNED_COPY_FILED, $legacy->fresh()->status);
    }

    // ── Scoping / permissions ────────────────────────────────────────────────

    public function test_an_agent_cannot_upload_to_or_open_another_agents_letter(): void
    {
        $letter = $this->letter();
        $stranger = $this->user(['name' => 'Sam Stranger']);

        $this->portalUpload($letter, $this->pdf(), $stranger)->assertStatus(404);
        $this->assertSame(0, PpraEmploymentLetterFile::count());
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, $letter->fresh()->status);

        // Even once a copy exists, the stranger cannot read it.
        $this->adminUpload($letter, $this->pdf());
        $file = $letter->fresh()->currentFile;
        $this->actingAs($stranger)->get(route('ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertStatus(404);
        $this->actingAs($stranger)->get(route('admin.ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertStatus(403); // no manage
    }

    public function test_the_principal_may_see_a_letter_but_only_its_agent_uploads_on_my_portal(): void
    {
        $letter = $this->letter();

        $this->portalUpload($letter, $this->pdf(), $this->principal)->assertStatus(403);
        $this->assertSame(0, PpraEmploymentLetterFile::count());
    }

    public function test_admin_upload_needs_manage_and_stays_inside_own_branch_agency_scope(): void
    {
        $letter = $this->letter();

        // No `manage` (a plain agent) → 403 on the admin routes.
        $this->adminUpload($letter, $this->pdf(), $this->agent)->assertStatus(403);

        // branch_manager has manage + branch scope: same branch OK, other branch 404.
        $sameBranchBm = $this->user(['name' => 'Bea Branch', 'role' => 'branch_manager']);
        $this->adminUpload($letter, $this->pdf('bm.pdf'), $sameBranchBm)->assertRedirect();
        $this->assertSame(1, PpraEmploymentLetterFile::count());

        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Stanford']);
        $otherBm     = $this->user(['name' => 'Otto Other', 'role' => 'branch_manager', 'branch_id' => $otherBranch->id]);
        $this->adminUpload($letter, $this->pdf('nope.pdf'), $otherBm)->assertStatus(404);
        $this->assertSame(1, PpraEmploymentLetterFile::count());
        $file = $letter->fresh()->currentFile;
        $this->actingAs($otherBm)->get(route('admin.ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertStatus(404);

        // An agency-wide admin (scope all) sees every branch.
        $otherBranchAgent = $this->user(['name' => 'Olga Other', 'branch_id' => $otherBranch->id]);
        $otherLetter = $this->letter($otherBranchAgent);
        $this->adminUpload($otherLetter, $this->pdf('all.pdf'))->assertRedirect();
        $this->assertSame(2, PpraEmploymentLetterFile::count());
    }

    public function test_another_agencys_admin_gets_404_on_upload_and_download_on_both_screens(): void
    {
        // The other tenant's user is built BEFORE anyone is acted as — BelongsToAgency force-stamps a new User into
        // the acting user's agency (same fixture rule as PpraEmploymentLetterTest::test_cross_agency_access_is_denied).
        $otherAgency = Agency::create(['name' => 'Cape Peninsula Properties', 'slug' => 'cape-peninsula']);
        $outsider = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'admin', 'is_active' => true]);
        $this->assertSame($otherAgency->id, $outsider->agency_id);
        foreach (['admin', 'agent'] as $role) {
            foreach (['access_my_portal', 'ppra_employment_letters.view', 'ppra_employment_letters.manage'] as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => 'all', 'agency_id' => $otherAgency->id]);
            }
        }
        PermissionService::clearCache();

        $letter = $this->letter();
        $this->adminUpload($letter, $this->pdf());
        $file = $letter->fresh()->currentFile;

        $this->adminUpload($letter, $this->pdf('x.pdf'), $outsider)->assertStatus(404);
        $this->portalUpload($letter, $this->pdf('y.pdf'), $outsider)->assertStatus(404);
        $this->actingAs($outsider)->get(route('admin.ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertStatus(404);
        $this->actingAs($outsider)->get(route('ppra-employment-letters.signed-copy', [$letter->id, $file->id]))->assertStatus(404);
        $this->assertSame(1, PpraEmploymentLetterFile::withoutGlobalScopes()->count());
    }

    public function test_an_archived_letter_refuses_uploads_on_both_screens_and_in_the_service(): void
    {
        $letter = $this->letter();
        $letter->delete();

        $this->portalUpload($letter, $this->pdf())->assertStatus(404);

        $resp = $this->adminUpload($letter, $this->pdf());
        $resp->assertRedirect();
        $resp->assertSessionHas('error');

        try {
            app(PpraEmploymentLetterService::class)->attachSignedCopy($letter->fresh() ?? PpraEmploymentLetter::withTrashed()->find($letter->id), $this->pdf(), $this->admin, 'admin');
            $this->fail('Expected a 409 for an archived letter.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(0, PpraEmploymentLetterFile::withTrashed()->count());
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, PpraEmploymentLetter::withTrashed()->find($letter->id)->status);
    }

    public function test_file_rules_pdf_jpg_png_up_to_10mb_and_nothing_else(): void
    {
        $letter = $this->letter();

        $this->actingAs($this->agent)->post(route('ppra-employment-letters.upload', $letter->id), [
            'signed_copy' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
        ])->assertSessionHasErrors('signed_copy');
        $this->actingAs($this->agent)->post(route('ppra-employment-letters.upload', $letter->id), [
            'signed_copy' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ])->assertSessionHasErrors('signed_copy');
        $this->actingAs($this->admin)->post(route('admin.ppra-employment-letters.upload', $letter->id), [])->assertSessionHasErrors('signed_copy');
        $this->assertSame(0, PpraEmploymentLetterFile::count());
        $this->assertSame(PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY, $letter->fresh()->status);

        $this->adminUpload($letter, UploadedFile::fake()->image('scan.jpg'))->assertRedirect();
        $this->portalUpload($letter, UploadedFile::fake()->image('scan.png'))->assertRedirect();
        $this->adminUpload($letter, UploadedFile::fake()->create('exactly10mb.pdf', 10240, 'application/pdf'))->assertRedirect();
        $this->assertSame(3, PpraEmploymentLetterFile::count());
        // Stored on the private disk under a random name, never a public URL.
        foreach (PpraEmploymentLetterFile::all() as $f) {
            $this->assertStringStartsWith('ppra-employment-letters/' . $this->agency->id . '/' . $letter->id . '/signed-copies/', $f->path);
            Storage::disk('local')->assertExists($f->path);
        }
    }

    public function test_the_signed_copy_routes_keep_deny_assistant_download(): void
    {
        foreach (['ppra-employment-letters.signed-copy', 'admin.ppra-employment-letters.signed-copy'] as $name) {
            $this->assertContains('deny_assistant_download', \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    // ── The printed letter ───────────────────────────────────────────────────

    public function test_printed_letter_date_is_the_day_it_was_created_never_the_day_of_printing(): void
    {
        $letter = $this->letter();
        $letter->forceFill(['created_at' => Carbon::parse('2026-03-04 10:00:00')])->save();
        Carbon::setTestNow('2026-09-30 09:00:00');

        try {
            $path = app(PpraEmploymentLetterPdfService::class)->generate(
                $letter->fresh(), $this->agent->fresh(), $this->principal->fresh(), $this->agency->fresh(), null, null
            );
            $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>&1');
            @unlink($path);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('Date 4 March 2026', preg_replace('/[ \t]+/', ' ', $text));
        $this->assertStringNotContainsString('30 September 2026', $text);
    }
}
