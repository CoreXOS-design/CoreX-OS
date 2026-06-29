<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\ContactIdentifierResolver;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\ContactDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-125 step 2 — the canonical resolvers match ANY of a contact's identifiers
 * (the child tables), widening AT-122 ingestion to secondary emails/phones.
 */
final class ContactResolverMultiIdentifierTest extends TestCase
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

    /** A contact with a primary + a secondary email and a primary + secondary phone. */
    private function multiContact(): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Multi', 'last_name' => 'Id',
            'phone' => '', 'email' => null,
        ]);
        $contact->emails()->create(['email' => 'primary@example.com']);   // primary (mirror)
        $contact->emails()->create(['email' => 'secondary@example.com']); // secondary
        $contact->phones()->create(['phone' => '0821111111']);            // primary (mirror)
        $contact->phones()->create(['phone' => '0822222222']);            // secondary

        return $contact->refresh();
    }

    private function resolver(): ContactIdentifierResolver
    {
        return app(ContactIdentifierResolver::class);
    }

    public function test_resolver_matches_secondary_email_and_primary(): void
    {
        $contact = $this->multiContact();

        $this->assertSame($contact->id, $this->resolver()->resolve('secondary@example.com', $this->agencyId)?->id, 'secondary email now resolves');
        $this->assertSame($contact->id, $this->resolver()->resolve('PRIMARY@example.com', $this->agencyId)?->id, 'primary still resolves (case-insensitive)');
    }

    public function test_resolver_matches_secondary_phone_and_primary(): void
    {
        $contact = $this->multiContact();

        // 27-prefixed inbound forms (WA jid style) of each number.
        $this->assertSame($contact->id, $this->resolver()->resolve('27822222222', $this->agencyId)?->id, 'secondary phone now resolves (0→27)');
        $this->assertSame($contact->id, $this->resolver()->resolve('0821111111', $this->agencyId)?->id, 'primary phone still resolves');
    }

    public function test_resolver_returns_null_for_a_truly_unmatched_identifier(): void
    {
        $this->multiContact();

        $this->assertNull($this->resolver()->resolve('nobody@nowhere.test', $this->agencyId));
        $this->assertNull($this->resolver()->resolve('27600000000', $this->agencyId));
    }

    public function test_mirror_only_contact_still_resolves(): void
    {
        // A contact carrying only the legacy mirror column (no child rows yet —
        // the state of contacts created via the unchanged form until step 3).
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Mirror', 'last_name' => 'Only',
            'phone' => '0837654321', 'email' => 'mirror@example.com',
        ]);
        $this->assertSame(0, $contact->emails()->count(), 'no child rows — mirror only');

        $this->assertSame($contact->id, $this->resolver()->resolve('mirror@example.com', $this->agencyId)?->id);
        $this->assertSame($contact->id, $this->resolver()->resolve('0837654321', $this->agencyId)?->id);
    }

    public function test_dedup_finds_existing_contact_via_secondary_identifier(): void
    {
        $contact = $this->multiContact();
        $dups = app(ContactDuplicateService::class)->findDuplicates(['email' => 'secondary@example.com'], $this->agencyId);

        $this->assertTrue($dups->contains('id', $contact->id), 'match-or-create finds the existing contact by its secondary email — no duplicate');
    }

    public function test_ingestion_widens_to_secondary_email(): void
    {
        $contact = $this->multiContact();

        // Inbound email FROM the contact's SECONDARY address → now imported + linked.
        $msg = [
            'external_id' => '<sec-' . Str::random(8) . '@x>', 'thread_key' => '<t@x>',
            'from' => 'secondary@example.com', 'counterpart' => 'secondary@example.com',
            'participants' => ['secondary@example.com', 'office@agency.test'],
            'subject' => 'Re: my second address', 'body_text' => 'hi from my other email',
            'occurred_at' => now()->subHour(), 'raw' => 'Message-ID: x', 'attachments' => [],
        ];
        $result = app(EmailArchiveIngestor::class)->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_ARCHIVED, $result, 'secondary-email message is IMPORTED (was discarded pre-AT-125 step 2)');
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => Contact::class, 'linkable_id' => $contact->id,
        ]);
    }

    public function test_ingestion_still_discards_a_truly_unmatched_email(): void
    {
        $this->multiContact();

        $msg = [
            'external_id' => '<no-' . Str::random(8) . '@x>', 'thread_key' => '<t@x>',
            'from' => 'stranger@nowhere.test', 'counterpart' => 'stranger@nowhere.test',
            'participants' => ['stranger@nowhere.test'], 'subject' => 'spam', 'body_text' => 'x',
            'occurred_at' => now(), 'raw' => 'Message-ID: y', 'attachments' => [],
        ];
        $result = app(EmailArchiveIngestor::class)->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_DROPPED, $result);
        $this->assertSame(0, Communication::count());
        $this->assertSame(0, CommunicationPending::count());
    }

    public function test_secondary_identifier_does_not_match_across_agencies(): void
    {
        $this->multiContact(); // agency A

        $otherAgency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(5), 'slug' => 'other-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull($this->resolver()->resolve('secondary@example.com', $otherAgency), 'agency B cannot resolve agency A\'s identifier');
    }
}
