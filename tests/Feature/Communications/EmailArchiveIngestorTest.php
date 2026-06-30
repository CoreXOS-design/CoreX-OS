<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-33 — email adapter ingestor: known-contact gate + Message-ID dedup +
 * attachment content-hash dedup. Tests the testable core directly (no live
 * IMAP), with agency_id seeded explicitly (not the AT-31 tenancy gap).
 */
final class EmailArchiveIngestorTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $mailboxOwnerId;
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

        $this->mailboxOwnerId = (int) User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ])->id;

        $this->mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->mailboxOwnerId,
            'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => true,
            'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    private function message(array $overrides = []): array
    {
        return array_merge([
            'external_id'  => '<msg-' . Str::random(10) . '@agency.test>',
            'thread_key'   => '<thread-1@agency.test>',
            'from'         => 'buyer@example.com',
            'counterpart'  => 'buyer@example.com',
            'participants' => ['buyer@example.com', 'office@agency.test'],
            'subject'      => 'Enquiry about 12 Main Road',
            'body_text'    => 'Hi, is this property still available?',
            'occurred_at'  => now()->subHour(),
            'raw'          => "Message-ID: x\r\nFrom: buyer@example.com\r\n\r\nbody " . Str::random(20),
            'attachments'  => [],
        ], $overrides);
    }

    private function ingestor(): EmailArchiveIngestor
    {
        return app(EmailArchiveIngestor::class);
    }

    public function test_known_sender_is_archived_with_a_deterministic_contact_link(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer',
            'phone' => '', 'email' => 'Buyer@Example.com',
        ]);

        $result = $this->ingestor()->ingest($this->mailbox, $this->message(), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_ARCHIVED, $result);
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertSame('email', $comm->channel);
        $this->assertSame('inbound', $comm->direction);
        $this->assertNotNull($comm->raw_path);
        $this->assertSame(0, CommunicationPending::count());
        // AT-122 — owning-agent provenance = the mailbox's user.
        $this->assertSame($this->mailboxOwnerId, (int) $comm->owner_user_id);

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_type'    => Contact::class,
            'linkable_id'      => $contact->id,
            'link_method'      => 'deterministic',
        ]);
    }

    /** AT-122 — match-only: an unknown sender is DISCARDED, never stored anywhere. */
    public function test_unknown_sender_is_discarded_and_nothing_is_written(): void
    {
        Storage::fake('local'); // fresh disk so we can assert nothing landed on it

        $result = $this->ingestor()->ingest($this->mailbox, $this->message(['from' => 'stranger@nowhere.test', 'counterpart' => 'stranger@nowhere.test']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_DROPPED, $result);
        // Nothing in either table…
        $this->assertSame(0, Communication::count(), 'no archive row');
        $this->assertSame(0, CommunicationPending::count(), 'no pending row — grace buffer is gone under match-only');
        // …and nothing written to disk (the .eml is only stored after a match).
        $this->assertEmpty(Storage::disk('local')->allFiles(), 'no raw payload on disk for an unmatched email');
    }

    /** AT-122 — a never-business sender is likewise dropped (filter still classifies it). */
    public function test_never_business_sender_is_dropped(): void
    {
        $result = $this->ingestor()->ingest($this->mailbox, $this->message(['from' => 'no-reply@bank.test', 'counterpart' => 'no-reply@bank.test']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_DROPPED, $result);
        $this->assertSame(0, Communication::count());
        $this->assertSame(0, CommunicationPending::count());
    }

    public function test_message_id_dedup_prevents_a_second_row(): void
    {
        Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'B', 'last_name' => 'B', 'phone' => '', 'email' => 'buyer@example.com']);
        $msg = $this->message();

        $first = $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND);
        $second = $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_ARCHIVED, $first);
        $this->assertSame(EmailArchiveIngestor::RESULT_DUPLICATE, $second);
        $this->assertSame(1, Communication::where('external_id', $msg['external_id'])->count());
    }

    /**
     * AT-122 — the dedup guard still also checks the LEGACY communication_pending
     * table, so a Message-ID already parked under the old store-then-match
     * behaviour is treated as a duplicate (not re-ingested) rather than matched
     * and archived a second time. Ingest itself never writes pending anymore.
     */
    public function test_legacy_pending_row_dedupes_a_re_poll(): void
    {
        Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'B', 'last_name' => 'B', 'phone' => '', 'email' => 'buyer@example.com']);
        $msg = $this->message();

        // Simulate a row left in pending by the pre-AT-122 behaviour.
        CommunicationPending::create([
            'agency_id' => $this->agencyId,
            'channel' => Communication::CHANNEL_EMAIL,
            'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => $msg['external_id'],
            'occurred_at' => now()->subDay(),
            'captured_at' => now()->subDay(),
            'expires_at' => now()->addDays(4),
        ]);

        $this->assertSame(EmailArchiveIngestor::RESULT_DUPLICATE, $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND));
        $this->assertSame(0, Communication::count(), 're-poll of a legacy-pending id is not archived again');
    }

    public function test_identical_attachments_are_stored_once(): void
    {
        Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'B', 'last_name' => 'B', 'phone' => '', 'email' => 'buyer@example.com']);
        $bytes = 'PDFDATA-' . str_repeat('z', 200);

        $this->ingestor()->ingest($this->mailbox, $this->message([
            'attachments' => [
                ['filename' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => $bytes],
                ['filename' => 'copy.pdf', 'mime' => 'application/pdf', 'bytes' => $bytes],
            ],
        ]), Communication::DIRECTION_INBOUND);

        $paths = CommunicationAttachment::where('agency_id', $this->agencyId)->pluck('storage_path')->unique();
        $this->assertSame(2, CommunicationAttachment::count(), 'two attachment rows');
        $this->assertCount(1, $paths, 'identical bytes share one stored object (dedup)');
    }
}
