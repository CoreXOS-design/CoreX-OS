<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-182 comms-archive polish:
 *  ITEM 1 — thread de-duplication: the thread conversation view shows each email's NEW
 *  content (reply-quote stripped into a derived `body_display`; raw `body_text` untouched),
 *  with a "Show full email" affordance; WhatsApp unaffected; a deploy backfill derives it
 *  for existing rows.
 *  ITEM 2 — a "Contact" action that opens the matched contact's communications tab.
 */
final class CommunicationArchiveThreadDedupTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $owner;
    private CommunicationMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->owner = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true,
        ]);
        $this->mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->owner->id,
            'email_address' => 'office@agency.test', 'imap_host' => 'imap.agency.test', 'imap_port' => 993,
            'username' => 'office@agency.test', 'encrypted_password' => 'secret',
            'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer',
            'phone' => '', 'email' => 'buyer@example.com',
        ]);
    }

    /** @param array<string,mixed> $over */
    private function ingestEmail(array $over = []): Communication
    {
        $msg = array_merge([
            'external_id' => '<msg-' . Str::random(10) . '@agency.test>',
            'thread_key' => '<thread-dedup@agency.test>',
            'from' => 'buyer@example.com', 'counterpart' => 'buyer@example.com',
            'participants' => ['buyer@example.com', 'office@agency.test'],
            'subject' => 'RE: Viewing',
            'body_text' => 'Tuesday works.',
            'occurred_at' => now()->subHour(),
            'raw' => "Message-ID: x\r\n\r\nbody " . Str::random(20),
            'attachments' => [],
        ], $over);

        app(EmailArchiveIngestor::class)->ingest($this->mailbox, $msg, Communication::DIRECTION_INBOUND);

        return Communication::where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
    }

    // ── ITEM 1 — display body derivation ─────────────────────────────────────────

    public function test_ingest_derives_stripped_display_body_and_leaves_raw_untouched(): void
    {
        $this->contact();
        $raw = "Tuesday works for the viewing.\n\nOn Mon, 6 Jul 2026, Bea Buyer <buyer@example.com> wrote:\n> Are you free this week?\n> Thanks\n";

        $comm = $this->ingestEmail(['body_text' => $raw]);

        $this->assertSame($raw, $comm->body_text, 'raw body_text must be untouched (immutable record)');
        $this->assertSame('Tuesday works for the viewing.', $comm->body_display);
        $this->assertTrue($comm->wasQuoteStripped());
        $this->assertSame('Tuesday works for the viewing.', $comm->display_body);
    }

    public function test_ingest_leaves_display_null_for_unquoted_email_and_falls_back(): void
    {
        $this->contact();
        $comm = $this->ingestEmail(['body_text' => "Hi, here are the three listings we discussed."]);

        $this->assertNull($comm->body_display);
        $this->assertFalse($comm->wasQuoteStripped());
        $this->assertSame('Hi, here are the three listings we discussed.', $comm->display_body);
    }

    public function test_whatsapp_message_is_unaffected_display_equals_body(): void
    {
        $wa = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => Communication::CHANNEL_WHATSAPP,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(12),
            'thread_key' => 'wa-tk', 'from_identifier' => '27713510291', 'occurred_at' => now(),
            'captured_at' => now(), 'owner_user_id' => $this->owner->id, 'body_text' => 'On my way now',
        ]);

        $this->assertNull($wa->body_display);
        $this->assertFalse($wa->wasQuoteStripped());
        $this->assertSame('On my way now', $wa->display_body);
    }

    public function test_backfill_derives_display_for_existing_emails_idempotently(): void
    {
        $raw = "Confirmed.\n\n-----Original Message-----\nFrom: x\nSubject: y\n\nPlease confirm.\n";
        $comm = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => Communication::CHANNEL_EMAIL,
            'direction' => Communication::DIRECTION_INBOUND, 'external_id' => Str::random(12),
            'thread_key' => 'tk', 'from_identifier' => 'buyer@example.com', 'occurred_at' => now(),
            'captured_at' => now(), 'owner_user_id' => $this->owner->id, 'body_text' => $raw, 'body_display' => null,
        ]);

        $this->artisan('comms:backfill-display-bodies')->assertSuccessful();
        $comm->refresh();
        $this->assertSame('Confirmed.', $comm->body_display);
        $this->assertSame($raw, $comm->body_text, 'backfill must not touch raw body_text');

        // Idempotent: a second run leaves it identical.
        $this->artisan('comms:backfill-display-bodies')->assertSuccessful();
        $this->assertSame('Confirmed.', $comm->fresh()->body_display);
    }

    public function test_thread_view_shows_stripped_body_with_show_full_email_affordance(): void
    {
        $this->contact();
        $this->ingestEmail(['body_text' => "Tuesday works.\n\nOn Mon wrote:\n> Are you free this week?\n> Regards\n"]);

        $html = $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.thread', ['threadKey' => '<thread-dedup@agency.test>']))
            ->assertOk()
            ->assertSee('Tuesday works.')
            ->assertSee('Show full email')
            ->getContent();

        // The full body (quoted history) is present in the page — inside the collapsed expand,
        // so nothing is lost and search still finds it.
        $this->assertStringContainsString('Are you free this week?', $html);
    }

    // ── ITEM 2 — Contact action ──────────────────────────────────────────────────

    public function test_contact_action_links_to_matched_contact_comm_tab_in_thread_and_detail(): void
    {
        $contact = $this->contact();
        $comm = $this->ingestEmail();

        // The ingest created the deterministic contact link.
        $this->assertSame(
            $contact->id,
            (int) optional($comm->links->firstWhere('linkable_type', Contact::class))->linkable_id
        );

        $contactUrl = route('corex.contacts.show', $contact->id);

        // Thread view.
        $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.thread', ['threadKey' => '<thread-dedup@agency.test>']))
            ->assertOk()
            ->assertSee($contactUrl, false)
            ->assertSee('?tab=communications', false);

        // Detail view.
        $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.show', $comm))
            ->assertOk()
            ->assertSee($contactUrl, false)
            ->assertSee('view contact');

        // List view.
        $this->actingAs($this->owner)
            ->get(route('compliance.comm-archive.index'))
            ->assertOk()
            ->assertSee($contactUrl, false)
            ->assertSee('>Contact</a>', false);
    }
}
