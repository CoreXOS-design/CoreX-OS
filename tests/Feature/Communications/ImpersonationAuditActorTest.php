<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Communications\CommsAccessRequest;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\ContactAccessLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-118 — audit-actor truth under switch-user. When session('impersonator_id')
 * is set (admin acting-as-X), the audit row still reads actor=X but now carries
 * the real acting admin: comms_access_audit_log.detail.acting_as_admin_id and
 * contact_access_log.impersonator_id. Non-impersonated actions carry no marker.
 */
final class ImpersonationAuditActorTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Seeding role_permissions flips the system into enforce-mode, so the
        // agent must be granted the perms the exercised routes require.
        RolePermission::insert([
            ['role' => 'agent', 'permission_key' => 'communications.view', 'scope' => 'own', 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'agent', 'permission_key' => 'access_contacts', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();
    }

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);
    }

    private function contactWithComm(User $owner): Contact
    {
        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'C', 'last_name' => 'X', 'phone' => '0821', 'agent_id' => $owner->id]);
        $comm = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => Communication::CHANNEL_WHATSAPP,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(12),
            'thread_key' => 'tk', 'from_identifier' => '2782', 'occurred_at' => now(),
            'captured_at' => now(), 'owner_user_id' => $owner->id,
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $contact->id,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100,
        ]);
        return $contact;
    }

    public function test_comms_audit_stamps_acting_admin_under_impersonation(): void
    {
        $admin     = $this->agent();
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner);

        // Admin is acting-as the requester (impersonator_id in session).
        $this->withSession(['impersonator_id' => $admin->id])
            ->actingAs($requester)
            ->postJson(route('api.v1.comms-access.store'), ['contact_id' => $contact->id])
            ->assertOk();

        $row = CommsAccessAuditLog::where('event_type', 'request')->firstOrFail();
        $this->assertSame($requester->id, (int) $row->actor_user_id, 'actor still reads as the impersonated user');
        $this->assertSame($admin->id, (int) ($row->detail['acting_as_admin_id'] ?? null), 'real acting admin recorded');
    }

    public function test_comms_audit_has_no_admin_marker_when_not_impersonated(): void
    {
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner);

        $this->actingAs($requester)
            ->postJson(route('api.v1.comms-access.store'), ['contact_id' => $contact->id])
            ->assertOk();

        $row = CommsAccessAuditLog::where('event_type', 'request')->firstOrFail();
        $this->assertArrayNotHasKey('acting_as_admin_id', (array) ($row->detail ?? []));
    }

    public function test_acting_admin_visible_on_grant_row_under_impersonation(): void
    {
        // Admin acting-as the owner approves a request → grant row actor=owner, but
        // the acting admin is visible (the self-approval / acting-as edge is auditable).
        $admin     = $this->agent();
        $owner     = $this->agent();
        $requester = $this->agent();
        $contact   = $this->contactWithComm($owner);

        $request = CommsAccessRequest::create([
            'agency_id' => $this->agencyId, 'contact_id' => $contact->id, 'requester_user_id' => $requester->id,
            'status' => CommsAccessRequest::STATUS_PENDING, 'expires_at' => now()->endOfDay(),
        ]);

        $this->withSession(['impersonator_id' => $admin->id])
            ->actingAs($owner)
            ->postJson(route('api.v1.comms-access.authorize', $request), ['decision' => 'approve'])
            ->assertOk();

        $grant = CommsAccessAuditLog::where('event_type', 'grant')->firstOrFail();
        $this->assertSame($owner->id, (int) $grant->actor_user_id);
        $this->assertSame($admin->id, (int) ($grant->detail['acting_as_admin_id'] ?? null), 'acting admin visible on the grant row');
    }

    public function test_contact_access_log_records_impersonator(): void
    {
        $admin = $this->agent();
        $x     = $this->agent();
        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'V', 'last_name' => 'Y', 'phone' => '0822', 'agent_id' => $x->id]);

        $this->withSession(['impersonator_id' => $admin->id])
            ->actingAs($x)
            ->get(route('corex.contacts.show', $contact))
            ->assertOk();

        $log = ContactAccessLog::where('contact_id', $contact->id)->latest('id')->firstOrFail();
        $this->assertSame($x->id, (int) $log->user_id, 'access still attributed to the impersonated user');
        $this->assertSame($admin->id, (int) $log->impersonator_id, 'real acting admin recorded');
    }

    public function test_contact_access_log_has_no_marker_when_not_impersonated(): void
    {
        // Separate test = fresh session (the test client persists session between
        // requests, so an impersonated request in another test must not leak here).
        $x       = $this->agent();
        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'V', 'last_name' => 'Z', 'phone' => '0824', 'agent_id' => $x->id]);

        $this->actingAs($x)->get(route('corex.contacts.show', $contact))->assertOk();

        $log = ContactAccessLog::where('contact_id', $contact->id)->latest('id')->firstOrFail();
        $this->assertNull($log->impersonator_id, 'normal access carries no acting-admin marker');
    }
}
