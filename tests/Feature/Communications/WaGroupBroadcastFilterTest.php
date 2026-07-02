<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\WaArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-151 — group/broadcast noise: (A) the ingestion gate drops @g.us and
 * status@broadcast messages regardless of capture path; (B) the one-off
 * remediation command soft-purges already-archived group/broadcast rows while
 * leaving the real 1:1 (@lid) threads intact.
 */
final class WaGroupBroadcastFilterTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '222758646611979@lid';

    private int $agencyId;
    private CommunicationWaDevice $device;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $agent->id,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', Str::random(40)), 'active' => true,
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel', 'phone' => '0713510291',
        ]);
        AgentCaptureConsent::create([
            'agency_id' => $this->agencyId, 'agent_user_id' => $agent->id,
            'contact_id' => $this->contact->id, 'status' => AgentCaptureConsent::STATUS_OPTED_IN,
        ]);
    }

    private function ingest(array $over): string
    {
        return app(WaArchiveIngestor::class)->ingest($this->device, array_merge([
            'message_id' => 'WA-' . Str::random(10),
            'direction'  => 'in',
            'timestamp'  => now()->timestamp,
            'text'       => 'hello',
            'counterpart_phone' => '27713510291@c.us',
        ], $over));
    }

    // ── A — ingestion gate ───────────────────────────────────────────────────

    public function test_group_and_broadcast_messages_are_dropped_at_ingestion(): void
    {
        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest(['chat_id' => '120363406141318837@g.us']), 'modern group id dropped');
        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest(['chat_id' => '27783098955-1467809184@g.us']), 'legacy group id dropped');
        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest(['chat_id' => 'status@broadcast']), 'status broadcast dropped');
        $this->assertSame(WaArchiveIngestor::RESULT_DROPPED,
            $this->ingest(['chat_id' => self::LID, 'is_group' => true]), 'is_group flag dropped');

        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count(),
            'no group/broadcast message reaches the archive');
    }

    public function test_real_one_to_one_lid_chat_still_archives(): void
    {
        // Control: the real 1:1 @lid conversation is NOT noise and still archives.
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $this->ingest(['chat_id' => self::LID]));
        $this->assertSame(1, Communication::where('agency_id', $this->agencyId)
            ->where('thread_key', self::LID)->count());
    }

    // ── B — remediation command ──────────────────────────────────────────────

    public function test_purge_command_soft_purges_noise_and_leaves_one_to_one_intact(): void
    {
        $group     = $this->seedComm('120363406141318837@g.us');
        $broadcast = $this->seedComm('status@broadcast');
        $oneToOne  = $this->seedComm(self::LID);

        // Dry-run changes nothing.
        $this->artisan('communications:purge-wa-noise', ['--agency' => $this->agencyId, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertNull($group->fresh()->purged_at, 'dry-run must not purge');

        // Real run soft-purges the two noise rows only.
        $this->artisan('communications:purge-wa-noise', ['--agency' => $this->agencyId])
            ->assertSuccessful();

        $this->assertNotNull($group->fresh()->purged_at, 'group row soft-purged');
        $this->assertSame('group_broadcast_noise', $group->fresh()->purged_reason);
        $this->assertNotNull($broadcast->fresh()->purged_at, 'broadcast row soft-purged');
        $this->assertNull($oneToOne->fresh()->purged_at, 'the real @lid 1:1 is UNTOUCHED');

        // Soft only — rows still exist (recoverable), nothing hard-deleted.
        $this->assertSame(3, Communication::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
    }

    private function seedComm(string $threadKey): Communication
    {
        return Communication::create([
            'agency_id'   => $this->agencyId,
            'channel'     => Communication::CHANNEL_WHATSAPP,
            'direction'   => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key'  => $threadKey,
            'from_identifier' => '27713510291',
            'occurred_at' => now(), 'captured_at' => now(),
        ]);
    }
}
