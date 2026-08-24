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

    /**
     * CX-113 Phase G (Johan, 2026-08-22) — "cant see all the email addresses it was
     * sent from or sent to." Confirmed by direct investigation that the ingestion
     * pipeline discarded the To/Cc split before this fix; these cover the fix and its
     * honest fallback for rows captured before it existed.
     */
    public function test_body_shows_to_and_cc_addresses_when_the_row_has_them(): void
    {
        $comm = $this->comm($this->agencyId, [
            'to_identifiers' => ['buyer@example.com', 'agent2@hfcoastal.co.za'],
            'cc_identifiers' => ['manager@hfcoastal.co.za'],
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $comm->id))
            ->assertOk();

        $resp->assertSee('To', false);
        $resp->assertSee('buyer@example.com', false);
        $resp->assertSee('agent2@hfcoastal.co.za', false);
        $resp->assertSee('Cc', false);
        $resp->assertSee('manager@hfcoastal.co.za', false);
    }

    public function test_body_falls_back_to_an_unlabelled_recipients_list_for_a_legacy_row(): void
    {
        // to_identifiers/cc_identifiers null — predates the Phase G ingestion fix.
        $comm = $this->comm($this->agencyId, [
            'to_identifiers' => null,
            'cc_identifiers' => null,
            'participant_identifiers' => ['conveyancer@bbb-attorneys.co.za', 'legacy-recipient@example.com'],
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $comm->id))
            ->assertOk();

        $resp->assertSee('Recipients', false);
        $resp->assertSee('legacy-recipient@example.com', false);
        $resp->assertDontSee('>To<', false);
        $resp->assertDontSee('>Cc<', false);
    }

    public function test_body_annotates_a_recipient_who_is_a_named_party_on_a_dr2_deal(): void
    {
        $dealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 1_500_000, 'commission_amount' => 75_000, 'commission_vat' => 11_250,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deal = \App\Models\Deal::withoutEvents(fn () => \App\Models\Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000, 'total_commission' => 86_250,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Test Seller', 'property_address' => 'MARKER_ANNOTATE_PROPERTY',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $dealV2Id,
        ]));
        $contactId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => 'seller-party@example.com',
            'first_name' => 'Test', 'last_name' => 'Seller', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_contacts')->insert([
            'deal_id' => $deal->id, 'contact_id' => $contactId, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $comm = $this->comm($this->agencyId, [
            'to_identifiers' => ['seller-party@example.com'],
            'cc_identifiers' => [],
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $comm->id))
            ->assertOk();

        $resp->assertSee('seller on deal', false);
        $resp->assertSee((string) $deal->deal_no, false);
    }

    public function test_body_annotates_a_recipient_who_is_a_known_contact_but_not_a_deal_party(): void
    {
        DB::table('contacts')->insert([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'email' => 'plain-contact@example.com',
            'first_name' => 'Plain', 'last_name' => 'Contact', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $comm = $this->comm($this->agencyId, [
            'to_identifiers' => ['plain-contact@example.com'],
            'cc_identifiers' => [],
        ]);

        $resp = $this->actingAs($this->agent)
            ->get(route('deals-dr2.comms-body.show', $comm->id))
            ->assertOk();

        $resp->assertSee('Plain Contact', false);
        $resp->assertSee('contact', false);
    }
}
