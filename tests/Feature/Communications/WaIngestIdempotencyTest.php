<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\AgentCaptureConsentService;
use App\Services\Communications\WaArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-149 — WA ingest idempotency. WAHA re-delivers the same message; the archive
 * must treat a duplicate delivery as a benign skip (never a dropped message, and
 * never a 500/ERROR). Proves the observable safety: a new message stores, a
 * re-delivery skips without touching the original, a same-id-different-body
 * re-delivery keeps the first + WARNs, and the consent pairing is idempotent.
 *
 * The concurrent-race path (the DB unique-constraint catch that the pre-insert
 * dedup cannot close) is verified on live post-deploy (Johan #4) — it is not
 * deterministically reproducible single-threaded because the same dedup guard
 * short-circuits before the insert.
 */
final class WaIngestIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '222758646611979@lid';

    private int $agencyId;
    private CommunicationWaDevice $device;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->agent->id,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', Str::random(40)), 'active' => true,
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel', 'phone' => '0713510291',
        ]);
        // Opt the pairing IN so bodies are captured (mirrors the live errors,
        // which all had captured bodies).
        DB::table('agent_capture_consent')->insert([
            'agency_id' => $this->agencyId, 'agent_user_id' => $this->agent->id, 'contact_id' => $this->contact->id,
            'status' => 'opted_in', 'decided_at' => now(), 'decided_by_user_id' => $this->agent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ingest(string $msgId, string $text): string
    {
        return app(WaArchiveIngestor::class)->ingest($this->device, [
            'message_id'        => $msgId,
            'direction'         => 'in',
            'timestamp'         => now()->timestamp,
            'text'              => $text,
            'chat_id'           => self::LID,
            'counterpart_phone' => '27713510291@c.us',
        ]);
    }

    public function test_a_new_message_stores(): void
    {
        $result = $this->ingest('MSG-NEW', 'The offer is R1,950,000.');

        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $result);
        $row = Communication::withoutGlobalScopes()->where('external_id', 'MSG-NEW')->first();
        $this->assertNotNull($row);
        $this->assertSame('The offer is R1,950,000.', $row->body_text);
        $this->assertSame(1, Communication::withoutGlobalScopes()->count());
    }

    public function test_a_re_delivered_duplicate_skips_without_touching_the_original(): void
    {
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $this->ingest('MSG-DUP', 'Hello there'));
        $original = Communication::withoutGlobalScopes()->where('external_id', 'MSG-DUP')->first();
        $originalUpdatedAt = $original->updated_at->toDateTimeString();

        // Same message id + same body arrives again (WAHA re-delivery).
        $result = $this->ingest('MSG-DUP', 'Hello there');

        $this->assertSame(WaArchiveIngestor::RESULT_DUPLICATE, $result, 're-delivery is a duplicate, not a new row');
        $this->assertSame(1, Communication::withoutGlobalScopes()->count(), 'exactly one row — nothing added, nothing dropped');
        $fresh = Communication::withoutGlobalScopes()->where('external_id', 'MSG-DUP')->first();
        $this->assertSame($original->id, $fresh->id);
        $this->assertSame('Hello there', $fresh->body_text, 'original body untouched');
        $this->assertSame($originalUpdatedAt, $fresh->updated_at->toDateTimeString(), 'original row not rewritten');
    }

    public function test_same_id_with_a_different_body_keeps_first_and_warns(): void
    {
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $this->ingest('MSG-EDIT', 'Original wording'));

        Log::spy();

        $result = $this->ingest('MSG-EDIT', 'TAMPERED wording');

        $this->assertSame(WaArchiveIngestor::RESULT_DUPLICATE, $result);
        $fresh = Communication::withoutGlobalScopes()->where('external_id', 'MSG-EDIT')->first();
        $this->assertSame('Original wording', $fresh->body_text, 'first-stored body is kept — never overwritten');
        $this->assertSame(1, Communication::withoutGlobalScopes()->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'DIFFERENT body'))
            ->once();
    }

    public function test_a_real_1062_collision_is_caught_and_skipped_at_debug(): void
    {
        // Reproduce the live 1062: a row already holds the (agency_id, external_id)
        // unique key, but the pre-insert dedup guards can't see it — here because it
        // is SOFT-DELETED (the guards apply the SoftDeletes scope; the unique index
        // is on (agency_id, external_id) with NO deleted_at, so the key is still
        // occupied). This drives the SAME create()→1062→catch path the concurrent
        // race hits, deterministically. external_id shaped from today's live evidence.
        $ext = 'false_244632797597780@lid_ACD1FB65F656C1C54BA415977C1DBFCE';
        $seed = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => 'whatsapp', 'direction' => 'inbound',
            'external_id' => $ext, 'thread_key' => 'wa:799522551', 'occurred_at' => now(),
            'captured_at' => now(), 'body_status' => 'captured', 'content_hash' => str_repeat('a', 64),
            'has_attachments' => 0, 'owner_user_id' => $this->agent->id,
        ]);
        $seed->delete(); // soft-delete → guards miss it, but the unique key stays held
        $activeBefore = Communication::withoutGlobalScopes()->whereNull('deleted_at')->count();

        Log::spy();
        $result = $this->ingest($ext, 'Is doodreg so');

        $this->assertSame(WaArchiveIngestor::RESULT_DUPLICATE, $result, '1062 is caught → duplicate, not an exception');
        $this->assertSame($activeBefore, Communication::withoutGlobalScopes()->whereNull('deleted_at')->count(),
            'no new active row — the re-delivery of a removed message is not resurrected');
        Log::shouldHaveReceived('debug')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'duplicate re-delivery'))
            ->once();
        Log::shouldNotHaveReceived('error'); // the noise is gone — no ERROR
    }

    public function test_a_failure_or_duplicate_mid_sequence_does_not_skip_later_messages(): void
    {
        // Whole-input-space: a duplicate on one message must not stop the next
        // message being stored. (The batch controller loop also wraps each ingest
        // in try/catch — this proves ingest() calls are independent.)
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $this->ingest('BATCH-1', 'First'));
        $dup = $this->ingest('BATCH-1', 'First');            // duplicate mid-sequence
        $new = $this->ingest('BATCH-2', 'Second, must store'); // later message

        $this->assertSame(WaArchiveIngestor::RESULT_DUPLICATE, $dup);
        $this->assertSame(WaArchiveIngestor::RESULT_ARCHIVED, $new, 'a later message still stores after a duplicate');
        $this->assertNotNull(Communication::withoutGlobalScopes()->where('external_id', 'BATCH-2')->first());
        $this->assertSame(2, Communication::withoutGlobalScopes()->count());
    }

    public function test_consent_pairing_is_idempotent(): void
    {
        $svc = app(AgentCaptureConsentService::class);

        $first = $svc->ensureSelfLinkedConsent($this->agencyId, $this->agent->id, (int) $this->contact->id);
        $second = $svc->ensureSelfLinkedConsent($this->agencyId, $this->agent->id, (int) $this->contact->id);

        $this->assertSame($first->id, $second->id, 'same pairing, no duplicate row, no exception');
        $this->assertSame(1, DB::table('agent_capture_consent')
            ->where('agency_id', $this->agencyId)->where('agent_user_id', $this->agent->id)
            ->where('contact_id', $this->contact->id)->count());
    }
}
