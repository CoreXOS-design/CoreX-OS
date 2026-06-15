<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationFlag;
use App\Models\Communications\CommunicationFlagAlert;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\CommunicationTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-36 Phase A — pending triage: per-agent flag isolation, agent_vs_agent
 * contradiction alerts, retroactive attach on "Add contact", and the BM
 * register exposing no message content.
 */
final class CommunicationTriageTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent1;
    private User $agent2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent1 = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
        $this->agent2 = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
    }

    private function seedPending(string $identifier, array $overrides = []): CommunicationPending
    {
        return CommunicationPending::create(array_merge([
            'agency_id'       => $this->agencyId,
            'channel'         => 'whatsapp',
            'direction'       => 'inbound',
            'external_id'     => 'EX-' . Str::random(10),
            'thread_key'      => $identifier,
            'from_identifier' => $identifier,
            'occurred_at'     => now()->subHour(),
            'captured_at'     => now()->subHour(),
            'body_text'       => 'SECRET-BODY-' . Str::random(6),
            'body_preview'    => 'preview',
            'expires_at'      => now()->addDays(3),
        ], $overrides));
    }

    private function service(): CommunicationTriageService
    {
        return app(CommunicationTriageService::class);
    }

    public function test_not_real_estate_flag_is_per_agent_only(): void
    {
        $id = 'buyer@unknown.test';
        $this->seedPending($id);

        $this->service()->flagNotRealEstate($this->agencyId, $this->agent1, $id, 'Buyer', 'EX-1');

        // Agent 1 no longer sees it; Agent 2 still does.
        $forAgent1 = $this->service()->pendingForAgent($this->agencyId, $this->agent1->id);
        $forAgent2 = $this->service()->pendingForAgent($this->agencyId, $this->agent2->id);

        $this->assertFalse($forAgent1->contains(fn ($p) => $p->from_identifier === $id), 'flag must suppress for the flagging agent');
        $this->assertTrue($forAgent2->contains(fn ($p) => $p->from_identifier === $id), 'flag must NOT bind another agent');
    }

    public function test_agent_vs_agent_alert_fires_on_contradiction(): void
    {
        $id = 'buyer@unknown.test';
        $this->seedPending($id);

        $flag1 = $this->service()->flagNotRealEstate($this->agencyId, $this->agent1, $id, 'Buyer', 'EX-1');

        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer', 'phone' => '', 'email' => $id]);
        $result = $this->service()->flagRealEstateAndAttach($this->agencyId, $this->agent2, $contact, $id, 'Bea Buyer', 'EX-1');

        $this->assertSame(1, $result['alerts']);
        $this->assertNotNull($flag1->fresh()->contradicted_at);
        $this->assertSame($this->agent2->id, $flag1->fresh()->contradicted_by_user_id);
        $this->assertDatabaseHas('communication_flag_alerts', [
            'agency_id'   => $this->agencyId,
            'original_flag_id' => $flag1->id,
            'alert_type'  => 'agent_vs_agent',
            'status'      => 'open',
        ]);
    }

    public function test_no_alert_when_same_agent_changes_their_mind(): void
    {
        $id = 'buyer@unknown.test';
        $this->seedPending($id);
        $this->service()->flagNotRealEstate($this->agencyId, $this->agent1, $id, 'Buyer', 'EX-1');

        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'B', 'last_name' => 'B', 'phone' => '', 'email' => $id]);
        $result = $this->service()->flagRealEstateAndAttach($this->agencyId, $this->agent1, $contact, $id, 'B B', 'EX-1');

        $this->assertSame(0, $result['alerts'], 'an agent contradicting their own flag is not an alert');
    }

    public function test_add_contact_retroactively_attaches_all_identifier_pending(): void
    {
        $id = 'seller@unknown.test';
        $this->seedPending($id, ['external_id' => 'EX-A', 'channel' => 'email', 'direction' => 'inbound']);
        $this->seedPending($id, ['external_id' => 'EX-B', 'channel' => 'whatsapp', 'direction' => 'inbound']);

        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '', 'email' => $id]);
        $result = $this->service()->flagRealEstateAndAttach($this->agencyId, $this->agent1, $contact, $id, 'Sam Seller', 'EX-A');

        $this->assertSame(2, $result['attached']);
        $this->assertSame(2, Communication::where('agency_id', $this->agencyId)->whereIn('external_id', ['EX-A', 'EX-B'])->count());
        // pending soft-purged + linked to the contact
        $this->assertSame(0, CommunicationPending::where('agency_id', $this->agencyId)->whereNull('purged_at')->count());
        $this->assertDatabaseHas('communication_links', ['linkable_type' => Contact::class, 'linkable_id' => $contact->id]);
    }

    public function test_bm_register_exposes_no_message_body(): void
    {
        $pending = $this->seedPending('buyer@unknown.test');
        $secret = $pending->body_text;
        $this->service()->flagNotRealEstate($this->agencyId, $this->agent1, 'buyer@unknown.test', 'Buyer', $pending->external_id);

        $bm = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'branch_manager', 'is_active' => true]);

        $resp = $this->actingAs($bm)->get(route('compliance.comm-flags.index'));
        $resp->assertOk();
        $resp->assertSee('buyer@unknown.test', false);     // identifier IS shown
        $resp->assertDontSee($secret, false);               // message body is NOT
    }
}
