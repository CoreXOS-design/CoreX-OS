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

        $this->mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'email_address' => 'office@agency.test',
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

        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_type'    => Contact::class,
            'linkable_id'      => $contact->id,
            'link_method'      => 'deterministic',
        ]);
    }

    public function test_unknown_sender_parks_in_pending_grace_buffer(): void
    {
        $result = $this->ingestor()->ingest($this->mailbox, $this->message(['from' => 'stranger@nowhere.test', 'counterpart' => 'stranger@nowhere.test']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_PENDING, $result);
        $this->assertSame(0, Communication::count());
        $pending = CommunicationPending::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($pending);
        $this->assertNotNull($pending->expires_at);
        // default grace = 4 calendar days
        $this->assertTrue($pending->expires_at->isAfter(now()->addDays(3)));
        $this->assertTrue($pending->expires_at->isBefore(now()->addDays(6)));
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

    public function test_pending_then_same_id_is_deduped_across_tables(): void
    {
        $msg = $this->message(['from' => 'unknown@nowhere.test', 'counterpart' => 'unknown@nowhere.test']);

        $this->assertSame(EmailArchiveIngestor::RESULT_PENDING, $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND));
        // Re-poll the same Message-ID while still pending → duplicate, not a 2nd pending row.
        $this->assertSame(EmailArchiveIngestor::RESULT_DUPLICATE, $this->ingestor()->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND));
        $this->assertSame(1, CommunicationPending::where('external_id', $msg['external_id'])->count());
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
