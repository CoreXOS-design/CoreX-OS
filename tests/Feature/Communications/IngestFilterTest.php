<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\CommunicationIngestFilter;
use App\Services\Communications\EmailArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-43 Fix 2 — deterministic ingestion filter (POPIA minimisation).
 * Drops no-reply/bank/service senders BEFORE storing, but a sender matching a
 * CoreX contact is NEVER dropped (contact always wins).
 */
final class IngestFilterTest extends TestCase
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

    private function message(array $overrides = []): array
    {
        $from = $overrides['from'] ?? 'someone@example.com';

        return array_merge([
            'external_id'  => '<msg-' . Str::random(10) . '@x.test>',
            'thread_key'   => null,
            'from'         => $from,
            'counterpart'  => $from,
            'participants' => [$from, 'office@agency.test'],
            'subject'      => 'Subject ' . Str::random(5),
            'body_text'    => 'body text here',
            'occurred_at'  => now()->subHour(),
            'raw'          => "Message-ID: x\r\nFrom: {$from}\r\n\r\nbody",
            'attachments'  => [],
        ], $overrides);
    }

    /** @return CommunicationIngestFilter */
    private function filter(): CommunicationIngestFilter
    {
        return app(CommunicationIngestFilter::class);
    }

    public function test_filter_classifies_each_input_path(): void
    {
        $agency = \App\Models\Agency::find($this->agencyId);
        $f = $this->filter();

        // no-reply markers (local part)
        $this->assertSame(CommunicationIngestFilter::REASON_NOREPLY, $f->dropReasonForUnknown('no-reply@randomco.com', $agency));
        $this->assertSame(CommunicationIngestFilter::REASON_NOREPLY, $f->dropReasonForUnknown('system@randomco.com', $agency));
        // service/bank domain (config default blocklist) incl. subdomain
        $this->assertSame(CommunicationIngestFilter::REASON_BLOCKED, $f->dropReasonForUnknown('incontact@fnb.co.za', $agency));
        $this->assertSame(CommunicationIngestFilter::REASON_BLOCKED, $f->dropReasonForUnknown('x@accounting.sageone.co.za', $agency));
        // a real-looking person at a non-blocked domain → keep
        $this->assertNull($f->dropReasonForUnknown('thabo.mokoena@gmail-personal.test', $agency));
        // WhatsApp number (no @) → rules don't apply → keep
        $this->assertNull($f->dropReasonForUnknown('+27821234567', $agency));
        // empty / null → keep (absorb, never crash)
        $this->assertNull($f->dropReasonForUnknown(null, $agency));
        $this->assertNull($f->dropReasonForUnknown('', $agency));
    }

    public function test_contact_on_a_blocklisted_domain_is_never_dropped(): void
    {
        // A genuine business contact happens to sit on a blocklist domain.
        Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Banker', 'last_name' => 'Person',
            'phone' => '', 'email' => 'relationship.manager@fnb.co.za',
        ]);

        $result = app(EmailArchiveIngestor::class)
            ->ingest($this->mailbox, $this->message(['from' => 'relationship.manager@fnb.co.za']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_ARCHIVED, $result, 'contact match wins over blocklist');
        $this->assertSame(1, Communication::count());

        // And evaluateExisting agrees: keep, reason contact_match.
        [$keep, $reason] = $this->filter()->evaluateExisting('relationship.manager@fnb.co.za', $this->agencyId, \App\Models\Agency::find($this->agencyId));
        $this->assertTrue($keep);
        $this->assertSame('contact_match', $reason);
    }

    public function test_noreply_sender_is_dropped_and_nothing_is_stored(): void
    {
        $result = app(EmailArchiveIngestor::class)
            ->ingest($this->mailbox, $this->message(['from' => 'no-reply@notifications.test']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_DROPPED, $result);
        $this->assertSame(0, Communication::count(), 'dropped → not archived');
        $this->assertSame(0, CommunicationPending::count(), 'dropped → not pending');
        // POPIA: nothing written to storage for a dropped message.
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_bank_service_sender_with_no_contact_is_dropped(): void
    {
        $result = app(EmailArchiveIngestor::class)
            ->ingest($this->mailbox, $this->message(['from' => 'paymentsemail@fnb.co.za']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_DROPPED, $result);
        $this->assertSame(0, CommunicationPending::count());
    }

    public function test_ordinary_unknown_sender_still_parks_in_pending(): void
    {
        $result = app(EmailArchiveIngestor::class)
            ->ingest($this->mailbox, $this->message(['from' => 'newlead@somerealtor.test']), Communication::DIRECTION_INBOUND);

        $this->assertSame(EmailArchiveIngestor::RESULT_PENDING, $result);
        $this->assertSame(1, CommunicationPending::count());
    }

    public function test_agency_blocklist_override_replaces_the_default_list(): void
    {
        $agency = \App\Models\Agency::find($this->agencyId);
        // Override: only block example-bank.test; fnb.co.za is NOT on the agency list anymore.
        $agency->update(['communication_ingest_blocklist_domains' => ['example-bank.test']]);
        $agency->refresh();

        $f = $this->filter();
        $this->assertSame(CommunicationIngestFilter::REASON_BLOCKED, $f->dropReasonForUnknown('x@example-bank.test', $agency));
        $this->assertNull($f->dropReasonForUnknown('x@fnb.co.za', $agency), 'agency override replaces the default list');
    }

    public function test_agency_can_disable_the_noreply_rule(): void
    {
        $agency = \App\Models\Agency::find($this->agencyId);
        $agency->update(['communication_ingest_drop_noreply' => false]);
        $agency->refresh();

        $this->assertNull($this->filter()->dropReasonForUnknown('no-reply@randomco.com', $agency));
    }
}
