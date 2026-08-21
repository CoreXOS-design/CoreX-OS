<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CX-112 (Johan, 2026-08-21) — "an agent must be able to read an email before filing/
 * confirming it." Reuses compliance.communication-archive._thread-bubble.blade.php (already
 * safe: escaped plain-text body, no HTML interpretation) via a new DR2-scoped controller,
 * gated on view_deals instead of the stricter access_communication_archive permission the
 * partial's original caller uses. Covers: body renders on demand, attachment access is
 * agency-scoped (not just borrowed from the comms-archive grant), and cross-agency leaks
 * are blocked on both the body view and the attachment stream.
 */
final class CommunicationBodyViewTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function comm(int $agencyId, array $over = []): Communication
    {
        return Communication::create(array_merge([
            'agency_id' => $agencyId, 'channel' => Communication::CHANNEL_EMAIL, 'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14), 'thread_key' => null,
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za',
            'subject' => 'MARKER_BODY_VIEW_SUBJECT',
            'body_text' => 'MARKER_BODY_VIEW_CONTENT — the full email text an agent needs to read before filing.',
            'occurred_at' => now(), 'captured_at' => now(),
            'owner_user_id' => null, 'has_attachments' => false,
        ], $over));
    }

    public function test_body_renders_the_escaped_plain_text_email_content(): void
    {
        $comm = $this->comm($this->agencyId);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $comm->id))
            ->assertOk();

        $resp->assertSee('MARKER_BODY_VIEW_SUBJECT');
        $resp->assertSee('MARKER_BODY_VIEW_CONTENT', false);
    }

    public function test_body_view_never_leaks_across_agencies(): void
    {
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherComm = $this->comm($otherAgencyId, ['subject' => 'MARKER_OTHER_AGENCY_SUBJECT']);

        $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $otherComm->id))
            ->assertNotFound();
    }

    public function test_attachment_download_is_agency_scoped_not_borrowed_from_archive_grant(): void
    {
        $comm = $this->comm($this->agencyId, ['has_attachments' => true]);
        $path = 'test-fixtures/' . Str::random(20) . '.txt';
        \Illuminate\Support\Facades\Storage::disk(config('communications.disk', 'local'))->put($path, 'attachment body');
        $attachment = CommunicationAttachment::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'filename' => 'contract.pdf', 'mime' => 'application/pdf', 'size_bytes' => 16,
            'content_hash' => hash('sha256', 'attachment body'), 'storage_path' => $path,
            'media_status' => 'stored',
        ]);

        $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.attachment', $attachment->id))
            ->assertOk();
    }

    public function test_attachment_never_leaks_across_agencies(): void
    {
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherComm = $this->comm($otherAgencyId, ['has_attachments' => true]);
        $path = 'test-fixtures/' . Str::random(20) . '.txt';
        \Illuminate\Support\Facades\Storage::disk(config('communications.disk', 'local'))->put($path, 'other agency file');
        $otherAttachment = CommunicationAttachment::create([
            'agency_id' => $otherAgencyId, 'communication_id' => $otherComm->id,
            'filename' => 'secret.pdf', 'mime' => 'application/pdf', 'size_bytes' => 18,
            'content_hash' => hash('sha256', 'other agency file'), 'storage_path' => $path,
            'media_status' => 'stored',
        ]);

        $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.attachment', $otherAttachment->id))
            ->assertNotFound();
    }

    public function test_unfiled_emails_screen_can_expand_a_row_via_the_shared_body_endpoint(): void
    {
        // Long enough that the list's 90-char preview truncation actually cuts it off — a
        // short fixture would pass either way and prove nothing about on-demand loading.
        $longTail = 'MARKER_TAIL_ONLY_VISIBLE_WHEN_EXPANDED_' . str_repeat('padding text to push well past the ninety character preview limit so truncation genuinely applies here. ', 3);
        $comm = $this->comm($this->agencyId, [
            'subject' => 'MARKER_UNFILED_EXPAND_SUBJECT',
            'body_text' => 'Short lead-in. ' . $longTail,
        ]);

        // The list page itself must not eager-render the full body (performance: on-demand only)
        // — only the truncated preview.
        $listResp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.unfiled-emails.index'))
            ->assertOk();
        $listResp->assertDontSee($longTail, false);

        // Expanding fetches it separately, on demand, via the shared endpoint.
        $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $comm->id))
            ->assertOk()
            ->assertSee($longTail, false);
    }
}
