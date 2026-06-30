<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\Admin\AgentDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-118 step 4 — Flow B offboarding: mandatory successor blocks the soft-delete;
 * the departing agent's live working set transfers to the successor (contacts,
 * FICA, communications, ON-MARKET stock); sold/historic stock + deals + commissions
 * stay; the comms gate re-points; the transfer is immutably audit-logged.
 */
final class AgentOffboardingTransferTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agent(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
    }

    private function property(User $agent, string $status): Property
    {
        return Property::create([
            'agency_id' => $this->agencyId, 'agent_id' => $agent->id, 'branch_id' => $this->agencyId,
            'external_id' => (string) Str::uuid(), 'title' => 'P ' . Str::random(5),
            'suburb' => 'Margate', 'property_type' => 'house', 'status' => $status, 'price' => 1000000,
        ]);
    }

    private function commForContact(Contact $contact, User $owner): Communication
    {
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
        return $comm;
    }

    public function test_transfer_moves_live_set_to_successor_and_leaves_historic(): void
    {
        $admin     = $this->agent();
        $departing = $this->agent();
        $successor = $this->agent();

        $contact   = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'C', 'last_name' => 'One', 'phone' => '0821', 'agent_id' => $departing->id]);
        $onMarket  = $this->property($departing, 'active');
        $sold      = $this->property($departing, 'sold');
        $comm      = $this->commForContact($contact, $departing);
        $ficaId    = (int) DB::table('fica_submissions')->insertGetId([
            'agency_id' => $this->agencyId, 'requested_by' => $departing->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(AgentDeletionService::class)->transferForOffboarding($departing, $successor, 'promote', $admin->id);

        // Live set → successor
        $this->assertSame($successor->id, (int) $contact->fresh()->agent_id, 'contact agent → successor');
        $this->assertSame($successor->id, (int) $onMarket->fresh()->agent_id, 'on-market stock → successor');
        $this->assertSame($successor->id, (int) $comm->fresh()->owner_user_id, 'comms owner → successor (gate re-points)');
        $this->assertSame($successor->id, (int) DB::table('fica_submissions')->where('id', $ficaId)->value('requested_by'), 'FICA → successor');

        // Historic stays with the departing agent
        $this->assertSame($departing->id, (int) $sold->fresh()->agent_id, 'sold/historic stock STAYS with departed agent');

        // Immutable ownership_transfer audit (req 1)
        $this->assertDatabaseHas('comms_access_audit_log', [
            'event_type' => 'ownership_transfer', 'actor_user_id' => $admin->id, 'subject_user_id' => $departing->id,
        ]);
    }

    public function test_comms_gate_repoints_to_successor(): void
    {
        $departing = $this->agent();
        $successor = $this->agent();
        $contact   = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'C', 'last_name' => 'Two', 'phone' => '0822', 'agent_id' => $departing->id]);
        $this->commForContact($contact, $departing);

        $base = fn () => Communication::query()->whereHas('links', fn ($q) =>
            $q->where('linkable_type', Contact::class)->where('linkable_id', $contact->id));

        // Before: departing owner sees it under 'own'; successor does not.
        $this->assertSame(1, $base()->visibleTo($departing, 'own')->count());
        $this->assertSame(0, $base()->visibleTo($successor, 'own')->count());

        app(AgentDeletionService::class)->transferForOffboarding($departing, $successor, 'promote', $departing->id);

        // After: the gate re-points — successor sees it, departing agent does not.
        $this->assertSame(1, $base()->visibleTo($successor, 'own')->count(), 'gate re-points to successor');
        $this->assertSame(0, $base()->visibleTo($departing, 'own')->count());
    }

    public function test_delete_is_blocked_without_a_successor(): void
    {
        $admin     = $this->agent();
        $departing = $this->agent();
        $qrTarget  = $this->agent();

        // QR reroute supplied, but NO successor (target_user_id) → blocked.
        $resp = $this->actingAs($admin)
            ->from('/admin/users')
            ->post('/admin/users/' . $departing->id . '/delete', [
                'qr_reroute_user_id' => $qrTarget->id,
            ]);

        $resp->assertSessionHasErrors('target_user_id');
        $this->assertNull($departing->fresh()->deleted_at, 'agent NOT soft-deleted without a successor');
    }

    public function test_delete_with_successor_proceeds_and_soft_deletes(): void
    {
        $admin     = $this->agent();
        $departing = $this->agent();
        $successor = $this->agent();
        $contact   = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'C', 'last_name' => 'Three', 'phone' => '0823', 'agent_id' => $departing->id]);

        $resp = $this->actingAs($admin)
            ->from('/admin/users')
            ->post('/admin/users/' . $departing->id . '/delete', [
                'qr_reroute_user_id' => $successor->id,
                'target_user_id'     => $successor->id,
                'secondary_handling' => 'promote',
            ]);

        $resp->assertRedirect(route('admin.users'));
        $this->assertNotNull($departing->fresh()->deleted_at, 'agent soft-deleted');
        $this->assertFalse((bool) $departing->fresh()->is_active);
        $this->assertSame($successor->id, (int) $contact->fresh()->agent_id, 'contact transferred to successor');
    }
}
