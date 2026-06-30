<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Communications\CommsAccessGrantService;
use App\Services\PermissionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-118 step 3 — Flow A: request → authorise (either/or) → session-scoped grant
 * (fills the Step-2 gate seam) → midnight reset. Every step POPIA-logged.
 *
 * Permissions are SEEDED so the gate is active (not the empty-table allow-all
 * fallback). Service-layer flow uses null session binding (no HTTP session in a
 * direct call), so hasActiveGrant resolves deterministically.
 */
final class CommsAccessGateFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private CommsAccessGrantService $grants;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->grants = app(CommsAccessGrantService::class);

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Seed perms so the gate is ACTIVE: agent = communications.view 'own';
        // admin = communications.grant_access + view 'all' (the authoriser).
        RolePermission::insert([
            ['role' => 'agent', 'permission_key' => 'communications.view', 'scope' => 'own', 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'communications.view', 'scope' => 'all', 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'communications.grant_access', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();
    }

    private function agent(string $role = 'agent'): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => $role,
        ]);
    }

    private function contactWithComm(?int $ownerUserId): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Thabo', 'last_name' => 'M', 'phone' => '0821234567',
        ]);
        $comm = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => Communication::CHANNEL_WHATSAPP,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(12),
            'thread_key' => 'tk', 'from_identifier' => '2782', 'occurred_at' => now(),
            'captured_at' => now(), 'owner_user_id' => $ownerUserId,
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $contact->id,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100,
        ]);
        return $contact;
    }

    public function test_request_then_owner_approves_opens_the_gate_for_that_contact_only(): void
    {
        $owner     = $this->agent();
        $requester = $this->agent();
        $contactA  = $this->contactWithComm($owner->id);
        $contactB  = $this->contactWithComm($owner->id);
        $this->actingAs($requester);

        // Requester owns no threads → gate shut for both contacts.
        $this->assertFalse($this->grants->hasActiveGrant($requester, $contactA));

        // Request access to A.
        $req = $this->grants->requestAccess($requester, $contactA, 'Need to follow up');
        $this->assertTrue($req->isPending());
        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => 'request', 'actor_user_id' => $requester->id, 'contact_id' => $contactA->id,
        ]);

        // Owner (owns a thread for A) may authorise — either/or, no dual control.
        $this->assertTrue($this->grants->canAuthorize($owner, $req));
        $this->grants->approve($req->fresh(), $owner);

        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => 'grant', 'actor_user_id' => $owner->id,
            'subject_user_id' => $requester->id, 'contact_id' => $contactA->id,
        ]);

        // Gate now OPENS for contact A only — not contact B.
        $this->assertTrue($this->grants->hasActiveGrant($requester, $contactA->fresh()));
        $this->assertFalse($this->grants->hasActiveGrant($requester, $contactB->fresh()));
    }

    public function test_decline_logs_and_grants_no_access(): void
    {
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner->id);
        $this->actingAs($requester);

        $req = $this->grants->requestAccess($requester, $contact, null);
        $this->grants->decline($req->fresh(), $owner, 'Not appropriate');

        $this->assertSame(CommsAccessRequest::STATUS_DECLINED, $req->fresh()->status);
        $this->assertFalse($this->grants->hasActiveGrant($requester, $contact->fresh()));
        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => 'decline', 'actor_user_id' => $owner->id, 'contact_id' => $contact->id,
        ]);
    }

    public function test_grant_access_holder_can_authorise_even_when_not_an_owner(): void
    {
        $manager   = $this->agent('admin');   // grant_access holder, not an owner
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner->id);
        $this->actingAs($requester);

        $req = $this->grants->requestAccess($requester, $contact, null);

        $this->assertTrue($this->grants->canAuthorize($manager, $req), 'grant_access holder may authorise (either/or)');
        $this->grants->approve($req->fresh(), $manager);
        $this->assertTrue($this->grants->hasActiveGrant($requester, $contact->fresh()));
    }

    public function test_midnight_reset_revokes_all_active_grants_and_logs(): void
    {
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner->id);
        $this->actingAs($requester);

        $req = $this->grants->requestAccess($requester, $contact, null);
        $this->grants->approve($req->fresh(), $owner);
        $this->assertTrue($this->grants->hasActiveGrant($requester, $contact->fresh()));

        // The midnight job.
        $this->artisan('comms-access:reset')->assertExitCode(0);

        $this->assertSame(CommsAccessRequest::STATUS_REVOKED, $req->fresh()->status);
        $this->assertFalse($this->grants->hasActiveGrant($requester, $contact->fresh()));
        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => 'midnight_reset', 'subject_user_id' => $requester->id, 'contact_id' => $contact->id,
        ]);
    }

    public function test_logout_revokes_the_users_grants_and_logs_session_expired(): void
    {
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner->id);
        $this->actingAs($requester);

        $req = $this->grants->requestAccess($requester, $contact, null);
        $this->grants->approve($req->fresh(), $owner);

        $this->grants->revokeForUser($requester, 'logout');

        $this->assertFalse($this->grants->hasActiveGrant($requester, $contact->fresh()));
        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => 'session_expired', 'actor_user_id' => $requester->id, 'contact_id' => $contact->id,
        ]);
    }

    public function test_audit_log_is_immutable(): void
    {
        $requester = $this->agent();
        $contact   = $this->contactWithComm($this->agent()->id);
        $this->actingAs($requester);

        $this->grants->requestAccess($requester, $contact, null);
        $row = CommsAccessAuditLog::where('event_type', 'request')->firstOrFail();

        $this->expectException(DomainException::class);
        $row->update(['event_type' => 'grant']);
    }

    public function test_http_request_and_authorize_endpoints(): void
    {
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner->id);

        // Requester (comms 'own' scope) posts a request.
        $this->actingAs($requester)
            ->postJson(route('api.v1.comms-access.store'), ['contact_id' => $contact->id, 'reason' => 'Follow up'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'pending']);

        $req = CommsAccessRequest::forContact($contact->id)->firstOrFail();
        $this->assertSame($requester->id, $req->requester_user_id);

        // Owner approves via the endpoint.
        $this->actingAs($owner)
            ->postJson(route('api.v1.comms-access.authorize', $req), ['decision' => 'approve'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'approved']);

        $this->assertTrue($req->fresh()->isApproved());
        $this->assertDatabaseHas('comms_access_audit_log', ['event_type' => 'grant', 'contact_id' => $contact->id]);
    }
}
