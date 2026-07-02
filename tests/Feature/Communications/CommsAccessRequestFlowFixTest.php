<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Services\Communications\CommsAccessGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-153 — comms access-request flow fixes:
 *  A) canAuthorize/canRevoke let a platform owner (null-agency super-admin) act as
 *     an audited break-glass, WITHOUT weakening the agency gate for ordinary users.
 *  D) communications:reassign-capture-owner re-owns platform-owned capture threads
 *     to a real agency agent (audited), and WaDeviceController refuses a platform/
 *     no-agency registrant.
 */
final class CommsAccessRequestFlowFixTest extends TestCase
{
    use RefreshDatabase;

    private CommsAccessGrantService $svc;
    private int $agencyA;      // the request's agency
    private int $agencyB;      // an unrelated agency
    private Contact $contact;
    private User $platformOwner;
    private User $agencyAdmin;     // agency A, grant_access (allow-all in tests)
    private User $strangerAgent;   // agency B, no grant
    private CommsAccessRequest $req;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CommsAccessGrantService::class);

        // A global owner role (schema-only snapshot has no seeded roles).
        // forceCreate: is_owner/can_be_deleted are guarded (not in Role::$fillable).
        Role::query()->forceCreate([
            'name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true,
            'can_be_deleted' => false, 'sort_order' => 0, 'agency_id' => null,
        ]);
        Role::clearCache();

        $this->agencyA = $this->makeAgency();
        $this->agencyB = $this->makeAgency();

        $this->contact = Contact::create([
            'agency_id' => $this->agencyA, 'first_name' => 'Elize', 'last_name' => 'R', 'phone' => '0713510291',
        ]);

        $this->platformOwner = User::factory()->create([
            'agency_id' => null, 'branch_id' => null, 'role' => 'super_admin', 'is_active' => true, 'name' => 'Platform Owner',
        ]);
        $this->agencyAdmin = User::factory()->create([
            'agency_id' => $this->agencyA, 'branch_id' => $this->agencyA, 'role' => 'admin', 'is_active' => true, 'name' => 'Agency Admin',
        ]);
        $this->strangerAgent = User::factory()->create([
            'agency_id' => $this->agencyB, 'branch_id' => $this->agencyB, 'role' => 'agent', 'is_active' => true, 'name' => 'Stranger',
        ]);

        // A pending request in agency A, owned-capture stamped to the platform owner.
        $requester = User::factory()->create(['agency_id' => $this->agencyA, 'branch_id' => $this->agencyA, 'role' => 'agent', 'is_active' => true]);
        $this->req = CommsAccessRequest::create([
            'agency_id' => $this->agencyA, 'contact_id' => $this->contact->id,
            'thread_key' => '222758646611979@lid', 'requester_user_id' => $requester->id,
            'status' => CommsAccessRequest::STATUS_PENDING, 'expires_at' => now()->endOfDay(),
        ]);
    }

    private function makeAgency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => 'Ag ' . Str::random(6), 'slug' => 'ag-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $id, 'agency_id' => $id, 'name' => 'D', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    // ── FIX A ────────────────────────────────────────────────────────────────

    public function test_platform_owner_can_authorize_a_null_agency_request(): void
    {
        $this->assertTrue($this->platformOwner->isOwnerRole(), 'seeded owner role resolves');
        $this->assertTrue($this->svc->canAuthorize($this->platformOwner, $this->req),
            'platform owner (agency NULL) may authorise as break-glass');
    }

    public function test_same_agency_admin_can_authorize(): void
    {
        $this->assertTrue($this->svc->canAuthorize($this->agencyAdmin, $this->req));
    }

    public function test_ordinary_cross_agency_user_cannot_authorize(): void
    {
        $this->assertFalse($this->svc->canAuthorize($this->strangerAgent, $this->req),
            'a different-agency user is still blocked — tenancy not weakened');
    }

    public function test_cross_agency_grant_holder_cannot_authorize(): void
    {
        // Even a would-be grant_access holder in ANOTHER agency stays blocked: the
        // effective-agency gate runs before the grant check for non-platform users.
        $crossAdmin = User::factory()->create([
            'agency_id' => $this->agencyB, 'branch_id' => $this->agencyB, 'role' => 'admin', 'is_active' => true,
        ]);
        $this->assertFalse($this->svc->canAuthorize($crossAdmin, $this->req));
    }

    public function test_revoke_break_glass_and_self_and_cross_agency(): void
    {
        $grant = CommsAccessRequest::create([
            'agency_id' => $this->agencyA, 'contact_id' => $this->contact->id,
            'thread_key' => 'tk-x', 'requester_user_id' => $this->agencyAdmin->id,
            'status' => CommsAccessRequest::STATUS_APPROVED, 'grant_mode' => CommsAccessRequest::MODE_ALWAYS,
            'authorized_by_user_id' => $this->agencyAdmin->id, 'authorized_at' => now(), 'expires_at' => now()->endOfDay(),
        ]);
        $this->assertTrue($this->svc->canRevoke($this->platformOwner, $grant), 'platform owner break-glass revoke');
        $this->assertTrue($this->svc->canRevoke($this->agencyAdmin, $grant), 'requester self-revoke');
        $this->assertFalse($this->svc->canRevoke($this->strangerAgent, $grant), 'cross-agency cannot revoke');
    }

    // ── FIX D — re-own command ────────────────────────────────────────────────

    public function test_reassign_command_reowns_platform_capture_to_agency_agent_audited(): void
    {
        $comm = Communication::create([
            'agency_id' => $this->agencyA, 'channel' => Communication::CHANNEL_WHATSAPP,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(12),
            'thread_key' => '222758646611979@lid', 'from_identifier' => '27713510291',
            'occurred_at' => now(), 'captured_at' => now(), 'owner_user_id' => $this->platformOwner->id,
            'body_text' => 'secret body',
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyA, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $this->contact->id,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100,
        ]);

        $this->artisan('communications:reassign-capture-owner', [
            '--from' => $this->platformOwner->id, '--to' => $this->agencyAdmin->id,
        ])->assertSuccessful();

        $comm->refresh();
        $this->assertSame((int) $this->agencyAdmin->id, (int) $comm->owner_user_id, 're-owned to agency agent');
        $this->assertSame('secret body', $comm->body_text, 'body untouched');
        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => CommsAccessAuditLog::EVENT_OWNERSHIP_TRANSFER,
            'subject_user_id' => $this->agencyAdmin->id, 'contact_id' => $this->contact->id,
        ]);
    }

    public function test_reassign_command_refuses_owner_role_target(): void
    {
        // --to must be a real agency agent, never a platform/owner account.
        $this->artisan('communications:reassign-capture-owner', [
            '--from' => $this->platformOwner->id, '--to' => $this->platformOwner->id,
        ])->assertFailed();
    }

    // ── FIX D — device registration guard (prevent recurrence) ────────────────

    public function test_device_registration_refuses_platform_owner(): void
    {
        $this->actingAs($this->platformOwner)
            ->post(route('communications.wa-devices.store'), ['wa_number' => '0820000000']);

        $this->assertSame(0, \App\Models\Communications\CommunicationWaDevice::query()
            ->where('user_id', $this->platformOwner->id)->count(),
            'a platform/owner account cannot register a capture device');
    }

    public function test_device_registration_allows_agency_agent(): void
    {
        $this->actingAs($this->agencyAdmin)
            ->post(route('communications.wa-devices.store'), ['wa_number' => '0820000000']);

        $this->assertSame(1, \App\Models\Communications\CommunicationWaDevice::query()
            ->where('user_id', $this->agencyAdmin->id)->count(),
            'a real agency agent registers a capture device normally');
    }
}
