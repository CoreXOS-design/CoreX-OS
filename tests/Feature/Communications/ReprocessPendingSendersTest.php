<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationPending;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\CommunicationStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-40 — `communications:reprocess-pending-senders` backfills from_identifier
 * on rows captured before the fix (NULL sender) by re-parsing the stored raw,
 * then retroactively attaches a now-matchable sender to the archive.
 */
final class ReprocessPendingSendersTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

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
    }

    private function pendingFrom(string $fromHeader): CommunicationPending
    {
        $eml = implode("\r\n", [
            'From: ' . $fromHeader,
            'To: office@agency.test',
            'Subject: Old enquiry',
            'Message-ID: <' . Str::random(12) . '@example.com>',
            'Date: Tue, 03 Jun 2026 09:43:38 +0200',
            '',
            'body',
            '',
        ]);
        $stored = app(CommunicationStorageService::class)->store($this->agencyId, 'email', $eml);

        // Simulate a pre-fix capture: sender NULL, raw on disk.
        return CommunicationPending::create([
            'agency_id'               => $this->agencyId,
            'channel'                 => Communication::CHANNEL_EMAIL,
            'direction'               => Communication::DIRECTION_INBOUND,
            'external_id'             => '<' . Str::random(10) . '@example.com>',
            'thread_key'              => null,
            'from_identifier'         => null,
            'participant_identifiers' => [],
            'occurred_at'             => now()->subDays(2),
            'captured_at'             => now()->subDays(2),
            'subject'                 => 'Old enquiry',
            'body_text'               => 'body',
            'raw_path'                => $stored['path'],
            'content_hash'            => $stored['content_hash'],
            'has_attachments'         => false,
            'source_ref'              => 'mailbox:1',
            'expires_at'              => now()->addDays(4),
        ]);
    }

    public function test_backfills_sender_and_attaches_a_now_known_contact(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer',
            'phone' => '', 'email' => 'buyer@example.com',
        ]);
        $known = $this->pendingFrom('"Bea Buyer" <buyer@example.com>');

        $this->artisan('communications:reprocess-pending-senders')->assertSuccessful();

        // The known sender is now archived + linked, and the pending row purged.
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm, 'matchable sender should be archived');
        $this->assertSame('buyer@example.com', $comm->from_identifier);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_id'      => $contact->id,
            'link_method'      => 'deterministic',
        ]);
        $this->assertSoftDeleted('communication_pending', ['id' => $known->id]);
    }

    public function test_unknown_sender_keeps_the_row_but_now_carries_a_from_identifier(): void
    {
        $stranger = $this->pendingFrom('stranger@nowhere.test');

        $this->artisan('communications:reprocess-pending-senders')->assertSuccessful();

        $stranger->refresh();
        $this->assertSame('stranger@nowhere.test', $stranger->from_identifier);
        $this->assertNull($stranger->purged_at, 'no contact yet → stays pending for the nightly pruner');
        $this->assertSame(0, Communication::where('agency_id', $this->agencyId)->count());
    }
}
