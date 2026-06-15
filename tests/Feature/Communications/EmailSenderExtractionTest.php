<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\EmailAddressExtractor;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * AT-40 BUG 2 — the From address must populate from_identifier so the
 * known-contact gate can match. Regression guard for the webklex Attribute
 * (non-Traversable) extraction bug that left from_identifier NULL on every row.
 * Drives a real .eml through the poller's normalize() (the fixed path) and then
 * the ingestor.
 */
final class EmailSenderExtractionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private CommunicationMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
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

    private function eml(string $from, string $to = 'office@agency.test'): string
    {
        return implode("\r\n", [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: Enquiry about 12 Main Road',
            'Message-ID: <' . Str::random(12) . '@example.com>',
            'Date: Tue, 03 Jun 2026 09:43:38 +0200',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Hi, is this still available?',
            '',
        ]);
    }

    public function test_extractor_pulls_the_sender_email_from_a_webklex_attribute(): void
    {
        $msg = Message::fromString($this->eml('"TPN eSign" <noreply@tpn.co.za>'));

        // The exact failure mode: a plain foreach over getFrom() yields nothing.
        $this->assertSame('noreply@tpn.co.za', EmailAddressExtractor::first($msg->getFrom()));
        $this->assertContains('office@agency.test', EmailAddressExtractor::normalize($msg->getTo()));
    }

    public function test_normalize_populates_from_identifier_and_a_known_sender_is_archived(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer',
            'phone' => '', 'email' => 'Buyer@Example.com',
        ]);

        $msg = Message::fromString($this->eml('"Bea Buyer" <buyer@example.com>'));

        // Call the poller's real (private) normalize() — the fixed extraction path.
        $poller = app(ImapMailboxPoller::class);
        $normalize = new ReflectionMethod($poller, 'normalize');
        $normalize->setAccessible(true);
        $normalized = $normalize->invoke($poller, $msg, Communication::DIRECTION_INBOUND);

        $this->assertSame('buyer@example.com', $normalized['from']);
        $this->assertSame('buyer@example.com', $normalized['counterpart']);
        $this->assertContains('buyer@example.com', $normalized['participants']);

        $result = app(EmailArchiveIngestor::class)
            ->ingest($this->mailbox, $normalized, Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_ARCHIVED, $result);
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertSame('buyer@example.com', $comm->from_identifier);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_type'    => Contact::class,
            'linkable_id'      => $contact->id,
            'link_method'      => 'deterministic',
        ]);
    }
}
