<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\ComplianceDocumentAudit;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\PolicyVersion;
use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVersion;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\CompliancePdf;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Compliance\Policy\PolicyTestHelpers;
use Tests\TestCase;

/**
 * Signed copy of an RMCP / policy version + the compliance officer's sign-off (compliance.md "Signed copy on file").
 */
final class SignedCopyTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    private int $agencyId;
    private User $admin;
    private User $viewer;
    private RmcpVersion $active;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        Storage::fake('local');

        $this->agencyId = $this->seedAgency();
        $this->admin    = $this->makeUser($this->agencyId);
        $this->viewer   = $this->makeUser($this->agencyId, 'agent');

        // Once grants exist, a role without the key is refused (an empty grants table permits everything).
        foreach (['access_rmcp', 'access_policy'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'scope' => 'all', 'agency_id' => $this->agencyId]);
        }
        foreach (['access_rmcp', 'access_policy', 'edit_rmcp', 'approve_rmcp', 'edit_policy', 'approve_policy', 'manage_signed_copy'] as $key) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'scope' => 'all', 'agency_id' => $this->agencyId]);
        }
        PermissionService::clearCache();

        $this->active = $this->makeRmcp($this->agencyId, 2, 'active');
    }

    private function makeRmcp(int $agencyId, int $number, string $status): RmcpVersion
    {
        $v = RmcpVersion::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'version_number' => $number, 'title' => 'RMCP', 'status' => $status,
            'approved_by' => $status === 'draft' ? null : $this->admin->id, 'approved_at' => $status === 'draft' ? null : now()->subWeek(),
            'approver_title' => 'Principal', 'effective_from' => now()->subWeek(), 'next_review_due' => now()->addYear(),
        ]);
        RmcpSection::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section',
            'display_order' => 1, 'section_number' => '1', 'title' => 'Purpose', 'body_html' => '<p>Why.</p>']);

        return $v;
    }

    private function pdf(string $name = 'signed.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% signed\n%%EOF\n");
    }

    private function upload(User $by, RmcpVersion $v, array $extra = [])
    {
        return $this->actingAs($by)->post(route('compliance.rmcp.signed-copy.store', $v), array_merge([
            'signed_copy' => $this->pdf(), 'signed_by_name' => 'Elize Reichel', 'signed_on' => '2026-10-20', 'submitted_to_fic_on' => '2026-10-27',
        ], $extra));
    }

    public function test_a_viewer_sees_nothing_extra_when_there_is_no_signed_copy_and_a_manager_sees_the_add_control(): void
    {
        $this->actingAs($this->viewer)->get(route('compliance.rmcp.show', $this->active))
            ->assertOk()->assertDontSee('Signed copy on file')->assertDontSee('Add the signed copy')->assertDontSee('No signed copy on the system yet');

        Auth::logout();
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->active))
            ->assertOk()->assertSee('Add the signed copy');
    }

    public function test_upload_on_an_active_version_keeps_the_file_records_the_sign_off_and_does_not_re_ask_staff(): void
    {
        $ack = RmcpAcknowledgement::create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $this->active->id, 'user_id' => $this->viewer->id,
            'status' => 'completed', 'started_at' => now()->subDay(), 'completed_at' => now()->subDay(), 'valid_until' => now()->addMonths(6),
            'sections_acknowledged_count' => 1, 'sections_total_count' => 1]);

        $this->upload($this->admin, $this->active)->assertRedirect(route('compliance.rmcp.show', $this->active) . '#signed-copy');

        $v = $this->active->fresh();
        self::assertSame('active', $v->status, 'nothing was approved again');
        self::assertNotNull($v->signed_copy_path);
        Storage::disk('local')->assertExists($v->signed_copy_path);
        self::assertSame('Elize Reichel', $v->signed_by_name);
        self::assertSame('2026-10-20', $v->signed_on->toDateString());
        self::assertSame('2026-10-27', $v->submitted_to_fic_on->toDateString());
        self::assertSame($this->admin->id, (int) $v->signed_copy_uploaded_by);
        self::assertSame(64, strlen($v->signed_copy_sha256));

        self::assertSame('completed', $ack->fresh()->status, 'a signed acknowledgement is untouched');
        self::assertSame(0, DB::table('notifications')->where('notifiable_id', $this->viewer->id)->count(), 'no staff re-sign was fired');

        $audit = ComplianceDocumentAudit::withoutGlobalScopes()->where('version_id', $v->id)->first();
        self::assertSame('signed_copy_uploaded', $audit->event_type);
        self::assertSame('rmcp', $audit->document_kind);
        self::assertSame($this->admin->id, (int) $audit->actor_user_id);
    }

    public function test_everyone_who_can_view_sees_the_line_and_can_download_but_only_managers_can_upload(): void
    {
        $this->upload($this->admin, $this->active);
        Auth::logout();

        $page = $this->actingAs($this->viewer)->get(route('compliance.rmcp.show', $this->active))->assertOk();
        $page->assertSee('Signed copy on file — signed by Elize Reichel on 20 October 2026, submitted to the FIC on 27 October 2026', false);
        $page->assertSee('Download signed copy');
        $page->assertDontSee('Replace the signed copy');

        $this->get(route('compliance.rmcp.signed-copy', $this->active))->assertOk()->assertDownload();

        $this->upload($this->viewer, $this->active)->assertForbidden();
    }

    public function test_the_appointed_compliance_officer_may_upload_without_the_role_manager_key(): void
    {
        $co = $this->makeUser($this->agencyId, 'agent');
        $this->upload($co, $this->active)->assertForbidden();
        Auth::logout();

        FicaOfficerAppointment::create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'user_id' => $co->id,
            'role' => FicaOfficerAppointment::ROLE_PRIMARY, 'full_name' => 'Elize Reichel', 'appointed_on' => now()->subYear()]);

        $this->upload($co, $this->active)->assertRedirect();
        self::assertNotNull($this->active->fresh()->signed_copy_path);
    }

    public function test_a_draft_cannot_take_a_signed_copy(): void
    {
        $draft = $this->makeRmcp($this->agencyId, 3, 'draft');

        $this->upload($this->admin, $draft)->assertRedirect()->assertSessionHas('error');
        self::assertNull($draft->fresh()->signed_copy_path);
    }

    public function test_replacing_keeps_the_earlier_file_and_audits_the_replacement(): void
    {
        $this->upload($this->admin, $this->active);
        $first = $this->active->fresh()->signed_copy_path;

        $this->upload($this->admin, $this->active, ['signed_copy' => $this->pdf('second.pdf'), 'signed_by_name' => 'Johan Reichel']);
        $v = $this->active->fresh();

        self::assertNotSame($first, $v->signed_copy_path);
        Storage::disk('local')->assertExists($first);
        Storage::disk('local')->assertExists($v->signed_copy_path);
        self::assertSame('Johan Reichel', $v->signed_by_name);
        $events = ComplianceDocumentAudit::withoutGlobalScopes()->where('version_id', $v->id)->orderBy('id')->pluck('event_type')->all();
        self::assertSame(['signed_copy_uploaded', 'signed_copy_replaced'], $events);
        self::assertSame($first, ComplianceDocumentAudit::withoutGlobalScopes()->where('event_type', 'signed_copy_replaced')->first()->meta['replaced']);
    }

    public function test_a_file_already_uploaded_at_approval_shows_as_the_signed_copy(): void
    {
        Storage::disk('local')->put('rmcp/1/board.pdf', "%PDF-1.4\n%%EOF\n");
        $this->active->forceFill(['board_approval_document_path' => 'rmcp/1/board.pdf'])->save();

        $this->actingAs($this->viewer)->get(route('compliance.rmcp.show', $this->active))
            ->assertOk()->assertSee('Signed copy on file', false)->assertSee('Download signed copy');
        $this->get(route('compliance.rmcp.signed-copy', $this->active))->assertOk()->assertDownload();

        // A path whose file is gone shows nothing rather than a broken link.
        Storage::disk('local')->delete('rmcp/1/board.pdf');
        $this->get(route('compliance.rmcp.show', $this->active))->assertOk()->assertDontSee('Signed copy on file');
    }

    public function test_the_line_is_printed_on_the_generated_pdf_and_absent_without_a_signed_copy(): void
    {
        $agency = \App\Models\Agency::findOrFail($this->agencyId);
        $html = fn (RmcpVersion $v) => app(CompliancePdf::class)->versionHtml('Kicker', null, $v->load('sections', 'approver'), $agency, [], 'RMCP');

        self::assertStringNotContainsString('Signed copy on file', $html($this->active));

        $this->upload($this->admin, $this->active);
        self::assertStringContainsString('Signed copy on file — signed by Elize Reichel on 20 October 2026, submitted to the FIC on 27 October 2026', $html($this->active->fresh()));
    }

    public function test_validation_and_agency_isolation(): void
    {
        $this->upload($this->admin, $this->active, ['signed_copy' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')])->assertSessionHasErrors('signed_copy');
        $this->upload($this->admin, $this->active, ['signed_on' => now()->addWeek()->toDateString()])->assertSessionHasErrors('signed_on');
        self::assertNull($this->active->fresh()->signed_copy_path);

        $this->upload($this->admin, $this->active);
        Auth::logout();
        $other = $this->makeUser($this->seedAgency());
        $this->actingAs($other)->get(route('compliance.rmcp.signed-copy', $this->active))->assertNotFound();
        $this->post(route('compliance.rmcp.signed-copy.store', $this->active), ['signed_copy' => $this->pdf()])->assertNotFound();
    }

    // ── Policies ─────────────────────────────────────────────────────────

    public function test_a_policy_version_takes_the_same_signed_copy_and_shows_the_same_line(): void
    {
        $policy = $this->makePolicy($this->agencyId, 'direct_marketing', 'Direct Marketing Policy');
        $version = $this->makeVersion($policy, 1, 'active');
        $version->forceFill(['approved_by' => $this->admin->id, 'approved_at' => now()->subDay()])->save();

        $this->actingAs($this->viewer)->get(route('compliance.policy.show', $version))->assertOk()->assertDontSee('Signed copy on file');
        Auth::logout();

        $this->actingAs($this->admin)->post(route('compliance.policy.signed-copy.store', $version), [
            'signed_copy' => $this->pdf(), 'signed_by_name' => 'Elize Reichel', 'signed_on' => '2026-10-20',
        ])->assertRedirect();
        Auth::logout();

        $fresh = PolicyVersion::withoutGlobalScopes()->find($version->id);
        self::assertSame('active', $fresh->status);
        self::assertSame('policy', ComplianceDocumentAudit::withoutGlobalScopes()->where('version_id', $version->id)->first()->document_kind);

        $this->actingAs($this->viewer)->get(route('compliance.policy.show', $version))
            ->assertOk()->assertSee('Signed copy on file — signed by Elize Reichel on 20 October 2026', false)->assertSee('Download signed copy');
        $this->get(route('compliance.policy.signed-copy', $version))->assertOk()->assertDownload();
    }

    public function test_the_permission_key_is_in_the_role_manager_catalogue(): void
    {
        self::assertContains('manage_signed_copy', array_column(config('corex-permissions.permissions'), 'key'));
    }
}
