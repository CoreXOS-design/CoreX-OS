<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommsThreadSetting;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\WaThreadKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-168 Part A — a single contact captured via the extension (@lid) and via
 * WAHA (@c.us) must resolve to ONE canonical thread, not fragment into two.
 */
final class WaThreadCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
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
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Andre', 'last_name' => 'Roets', 'phone' => '0799522551',
        ]);
    }

    public function test_canonical_key_helper(): void
    {
        $this->assertSame('wa:799522551', WaThreadKey::canonical('27799522551'));
        $this->assertSame('wa:799522551', WaThreadKey::canonical('0799522551@c.us'));
        $this->assertNull(WaThreadKey::canonical(''));
        $this->assertNull(WaThreadKey::canonical('222758646611979@lid')); // an @lid is not a phone
        $this->assertTrue(WaThreadKey::isGroupOrBroadcast('27766185578-1456235253@g.us'));
        $this->assertTrue(WaThreadKey::isGroupOrBroadcast('status@broadcast'));
        $this->assertFalse(WaThreadKey::isGroupOrBroadcast('244632797597780@lid'));
    }

    public function test_command_collapses_lid_and_cus_into_one_canonical_thread(): void
    {
        // Pre-fix fragmentation: same human, two raw chat ids → two thread_keys.
        $lid = $this->seedComm('244632797597780@lid', '27799522551');
        $cus = $this->seedComm('27799522551@c.us', '27799522551');
        // A group message where this contact was the sender — MUST NOT fold in.
        $grp = $this->seedComm('27766185578-1456235253@g.us', '27799522551');

        // A per-thread privacy setting on the OLD @lid key — must migrate to canonical.
        $agentId = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent'])->id;
        CommsThreadSetting::create([
            'agency_id' => $this->agencyId, 'contact_id' => $this->contact->id,
            'thread_key' => '244632797597780@lid', 'hide_subject' => true, 'set_by_user_id' => $agentId,
        ]);

        // Dry-run writes nothing.
        $this->artisan('communications:recanonicalize-wa-threads', ['--agency' => $this->agencyId, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertSame('244632797597780@lid', $lid->fresh()->thread_key, 'dry-run must not change');

        // Real run.
        $this->artisan('communications:recanonicalize-wa-threads', ['--agency' => $this->agencyId])
            ->assertSuccessful();

        // Both 1:1 rows now share the canonical thread key.
        $this->assertSame('wa:799522551', $lid->fresh()->thread_key);
        $this->assertSame('wa:799522551', $cus->fresh()->thread_key);
        $this->assertSame('244632797597780@lid', $lid->fresh()->wa_chat_id, 'raw chat id preserved');
        $this->assertSame(1, Communication::where('agency_id', $this->agencyId)
            ->where('thread_key', 'wa:799522551')->distinct()->count('thread_key'));

        // The group row is untouched (still addressable by the noise purge).
        $this->assertSame('27766185578-1456235253@g.us', $grp->fresh()->thread_key);

        // The privacy setting followed the thread onto the canonical key.
        $this->assertDatabaseHas('comms_thread_settings', [
            'contact_id' => $this->contact->id, 'thread_key' => 'wa:799522551', 'hide_subject' => true,
        ]);
        $this->assertDatabaseMissing('comms_thread_settings', [
            'contact_id' => $this->contact->id, 'thread_key' => '244632797597780@lid', 'deleted_at' => null,
        ]);
    }

    public function test_command_is_idempotent(): void
    {
        $this->seedComm('244632797597780@lid', '27799522551');
        $this->seedComm('27799522551@c.us', '27799522551');

        $this->artisan('communications:recanonicalize-wa-threads', ['--agency' => $this->agencyId])->assertSuccessful();
        // Second run finds nothing to do (already canonical).
        $this->artisan('communications:recanonicalize-wa-threads', ['--agency' => $this->agencyId])
            ->expectsOutputToContain('No fragmented WhatsApp threads')
            ->assertSuccessful();

        $this->assertSame(2, Communication::where('agency_id', $this->agencyId)
            ->where('thread_key', 'wa:799522551')->count());
    }

    private function seedComm(string $rawChat, string $fromId): Communication
    {
        return Communication::create([
            'agency_id'   => $this->agencyId,
            'channel'     => Communication::CHANNEL_WHATSAPP,
            'direction'   => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key'  => $rawChat,   // pre-fix: raw chat id
            'wa_chat_id'  => $rawChat,   // migration backfill copies thread_key → wa_chat_id
            'from_identifier' => $fromId,
            'occurred_at' => now(), 'captured_at' => now(),
        ]);
    }
}
