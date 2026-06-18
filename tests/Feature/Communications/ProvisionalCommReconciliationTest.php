<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-59 — contact comms tiles driven by the Communications archive.
 *
 * Proves the provisional → confirmed reconciliation machinery on real messy
 * data: a click creates a provisional outbound row; ingestion PROMOTES it in
 * place (text-hash match, time-window fallback) instead of duplicating; an
 * edited-before-send orphan that never matches is pruned; out-of-order ingestion
 * never rewinds last_contacted_at.
 */
final class ProvisionalCommReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private Contact $contact;
    private CommunicationMailbox $mailbox;

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
        $this->actingAs(User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]));

        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'phone' => '0721234567', 'email' => 'thandi@example.co.za',
        ]);

        $this->mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => true,
            'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    private function logger(): OutboundProvisionalLogger
    {
        return app(OutboundProvisionalLogger::class);
    }

    private function ingestor(): EmailArchiveIngestor
    {
        return app(EmailArchiveIngestor::class);
    }

    /** Outbound Sent-folder message addressed to the contact. */
    private function sentMessage(array $overrides = []): array
    {
        return array_merge([
            'external_id'  => '<sent-' . Str::random(10) . '@agency.test>',
            'thread_key'   => '<thread-1@agency.test>',
            'from'         => 'office@agency.test',
            'counterpart'  => 'thandi@example.co.za',
            'participants' => ['office@agency.test', 'thandi@example.co.za'],
            'subject'      => 'Your viewing on Saturday',
            'body_text'    => 'Hi Thandi, confirming 10am at 12 Marine Drive.',
            'occurred_at'  => now(),
            'raw'          => "Message-ID: x\r\nFrom: office@agency.test\r\n\r\nbody " . Str::random(20),
            'attachments'  => [],
        ], $overrides);
    }

    // ── A/B: provisional creation + derived tile count ──

    public function test_click_creates_one_provisional_outbound_row_and_counts_on_the_tile(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Your viewing on Saturday', 'Hi Thandi, confirming 10am at 12 Marine Drive.', null);

        $rows = Communication::withTrashed()->where('agency_id', $this->agencyId)->get();
        $this->assertCount(1, $rows);
        $comm = $rows->first();
        $this->assertNotNull($comm->provisional_at, 'row is provisional');
        $this->assertStringStartsWith('provisional:', $comm->external_id);
        $this->assertSame('outbound', $comm->direction);

        // Tile count derives from the archive (provisional counts).
        $this->assertSame(1, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
        $this->assertSame(0, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_WHATSAPP));

        // last_contacted advanced; link recorded but not yet confirmed.
        $this->assertNotNull($this->contact->fresh()->last_contacted_at);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => Contact::class,
            'linkable_id' => $this->contact->id, 'link_method' => 'manual', 'confirmed_at' => null,
        ]);
    }

    // ── D: reconciliation by exact text hash promotes in place ──

    public function test_matching_ingest_promotes_the_provisional_row_not_duplicates(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Your viewing on Saturday', 'Hi Thandi, confirming 10am at 12 Marine Drive.', null);
        $provisional = Communication::firstWhere('agency_id', $this->agencyId);

        $msg = $this->sentMessage(['occurred_at' => now()->addMinutes(3)]);
        $result = $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_OUTBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_RECONCILED, $result);

        // Exactly one row — the SAME row, promoted.
        $this->assertSame(1, Communication::where('agency_id', $this->agencyId)->count());
        $promoted = Communication::find($provisional->id);
        $this->assertNotNull($promoted, 'same row id survives');
        $this->assertNull($promoted->provisional_at, 'provisional cleared');
        $this->assertSame($msg['external_id'], $promoted->external_id, 'real Message-ID set');
        $this->assertNotNull($promoted->raw_path, 'raw payload now attached');
        $this->assertEquals($msg['occurred_at']->format('Y-m-d H:i'), $promoted->occurred_at->format('Y-m-d H:i'));

        // The single link is now confirmed; no second link created.
        $this->assertSame(1, CommunicationLink::where('communication_id', $promoted->id)->count());
        $this->assertNotNull(CommunicationLink::firstWhere('communication_id', $promoted->id)->confirmed_at);

        // Tile still reads 1 — no double count.
        $this->assertSame(1, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
    }

    // ── D fallback: edited-before-send breaks the hash, time window matches ──

    public function test_edited_message_within_window_reconciles_via_time_fallback(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Your viewing on Saturday', 'Original draft text.', null);
        $provisional = Communication::firstWhere('agency_id', $this->agencyId);

        // Body edited before sending → text hash differs, but within the window.
        $msg = $this->sentMessage(['body_text' => 'Edited: see you 10:30am instead.', 'occurred_at' => now()->addMinutes(20)]);
        $result = $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_OUTBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_RECONCILED, $result);
        $this->assertSame(1, Communication::where('agency_id', $this->agencyId)->count());
        $this->assertNull(Communication::find($provisional->id)->provisional_at);
    }

    // ── D miss: outside window → fresh confirmed row, provisional left orphaned ──

    public function test_edited_message_outside_window_inserts_fresh_and_leaves_orphan(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Your viewing on Saturday', 'Original draft text.', null);

        // Different body AND far outside the 48h window → no match.
        $msg = $this->sentMessage(['body_text' => 'Completely different.', 'occurred_at' => now()->addDays(10)]);
        $result = $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_OUTBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_ARCHIVED, $result);
        // Two rows now: the orphan provisional + the fresh confirmed one.
        $this->assertSame(2, Communication::where('agency_id', $this->agencyId)->count());
        $this->assertSame(2, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
    }

    // ── E: prune removes an aged unreconciled provisional (back to truth) ──

    public function test_prune_soft_purges_aged_unreconciled_provisional(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Subject', 'Body that will be edited away.', null);
        $provisional = Communication::firstWhere('agency_id', $this->agencyId);

        // Age it past the default 7-day prune window.
        $provisional->forceFill(['provisional_at' => now()->subDays(8)])->save();

        $this->artisan('communications:prune-provisional')->assertExitCode(0);

        $purged = Communication::withTrashed()->find($provisional->id);
        $this->assertNotNull($purged->deleted_at, 'soft-deleted');
        $this->assertSame('provisional_unreconciled', $purged->purged_reason);
        // Drops out of the derived tile count.
        $this->assertSame(0, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
    }

    public function test_prune_leaves_fresh_provisional_alone(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Subject', 'Just sent.', null);

        $this->artisan('communications:prune-provisional')->assertExitCode(0);

        $this->assertSame(1, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
    }

    // ── duplicate ingestion is idempotent ──

    public function test_duplicate_ingest_after_reconcile_does_not_add_a_row(): void
    {
        $this->logger()->log($this->contact, Communication::CHANNEL_EMAIL, 'Your viewing on Saturday', 'Hi Thandi, confirming 10am at 12 Marine Drive.', null);
        $msg = $this->sentMessage();

        $this->assertSame(EmailArchiveIngestor::RESULT_RECONCILED, $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_OUTBOUND));
        $this->assertSame(EmailArchiveIngestor::RESULT_DUPLICATE, $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_OUTBOUND));

        $this->assertSame(1, Communication::where('agency_id', $this->agencyId)->count());
        $this->assertSame(1, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
    }

    // ── F: out-of-order ingestion never rewinds last_contacted_at ──

    public function test_out_of_order_ingest_does_not_rewind_last_contacted(): void
    {
        $this->contact->forceFill(['last_contacted_at' => now()])->save();
        $before = $this->contact->fresh()->last_contacted_at;

        // A fresh confirmed outbound that occurred 2 days ago (no provisional to match).
        $msg = $this->sentMessage(['body_text' => 'Older message.', 'occurred_at' => now()->subDays(2)]);
        $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_OUTBOUND);

        $this->assertEquals(
            $before->format('Y-m-d H:i'),
            $this->contact->fresh()->last_contacted_at->format('Y-m-d H:i'),
            'last_contacted_at must not move backwards'
        );
    }

    // ── zero-comms contact ──

    public function test_contact_with_no_comms_counts_zero(): void
    {
        $this->assertSame(0, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_EMAIL));
        $this->assertSame(0, $this->contact->fresh()->outboundCommCount(Communication::CHANNEL_WHATSAPP));
    }
}
