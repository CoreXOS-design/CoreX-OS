<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\Communications\OutboundProvisionalLogger;
use App\Services\Communications\ProvisionalReconciler;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-80 — bulk Seller Outreach sends must write to the D2 Communications archive
 * so the contact comms tiles (AT-59: LAST CONTACTED / WHATSAPP / EMAIL) reflect
 * them.
 *
 * Before AT-80 the send path wrote only seller_outreach_sends + contact_outreach_log
 * and touched nothing the tiles read. The fix calls OutboundProvisionalLogger::log()
 * — the SAME provisional path the per-contact quick-send buttons use — inside the
 * per-recipient send transaction, so:
 *   - a communications (outbound) row is created, linked to the Contact;
 *   - the WHATSAPP/EMAIL tile counts (outboundCommCount) increment;
 *   - last_contacted_at advances;
 *   - the later real Sent message reconciles the provisional row IN PLACE
 *     (text_hash match) → no double count.
 *
 * Input paths proven: whatsapp single, email single, bulk (3 recipients, no
 * cross-linking), dedup reconcile (provisional → confirmed, count unchanged),
 * transactional atomicity (archive write failure rolls the send row back too).
 */
final class OutreachWritesToArchiveTest extends TestCase
{
    use RefreshDatabase;

    /** 1 — WhatsApp send writes a linked outbound archive row + advances the tiles. */
    public function test_whatsapp_send_writes_archive_row_and_advances_tiles(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $this->assertNull($contact->last_contacted_at, 'precondition: never contacted');
        $this->assertSame(0, $contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));

        $resp = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi {seller_name}, demand is strong. {tracking_link} Reply STOP to opt out.",
            ]);

        $resp->assertOk();
        $sendId = $resp->json('send_id');
        $send   = SellerOutreachSend::withoutGlobalScopes()->findOrFail($sendId);

        // (a) one outbound communications row, correct channel, linked to the contact.
        $comm = $this->soleCommunicationFor($contact, Communication::CHANNEL_WHATSAPP);
        $this->assertSame(Communication::DIRECTION_OUTBOUND, $comm->direction);
        $this->assertSame($agencyId, (int) $comm->agency_id);
        $this->assertNotNull($comm->provisional_at, 'archive row starts provisional (pre-ingestion)');
        // The stored body is the ACTUAL sent body (merge fields + tracking link substituted).
        $this->assertSame($send->body_snapshot, $comm->body_text, 'archive body must equal the sent body');
        $this->assertStringContainsString('/m/' . $send->tracking_short_code, (string) $comm->body_text);
        $this->assertStringNotContainsString('{tracking_link}', (string) $comm->body_text);

        $link = CommunicationLink::withoutGlobalScopes()->where('communication_id', $comm->id)->sole();
        $this->assertSame(Contact::class, $link->linkable_type);
        $this->assertSame($contact->id, (int) $link->linkable_id);
        $this->assertNull($link->confirmed_at, 'provisional link: confirmed_at set only on reconcile');

        // (b) + (c) tile count increments and last_contacted_at advances.
        $contact->refresh();
        $this->assertSame(1, $contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
        $this->assertSame(0, $contact->outboundCommCount(Communication::CHANNEL_EMAIL), 'email tile untouched');
        $this->assertNotNull($contact->last_contacted_at, 'last_contacted_at advanced');
    }

    /** 2 — Email send writes an email-channel archive row with the subject. */
    public function test_email_send_writes_email_archive_row(): void
    {
        Mail::fake();
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedEmailTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'email',
                'subject' => 'Demand for your home',
                'body'    => "Hi {seller_name}, buyers are searching. {tracking_link} Tap {opt_out_link} to stop.",
            ]);

        $resp->assertOk();
        $send = SellerOutreachSend::withoutGlobalScopes()->findOrFail($resp->json('send_id'));

        $comm = $this->soleCommunicationFor($contact, Communication::CHANNEL_EMAIL);
        $this->assertSame(Communication::DIRECTION_OUTBOUND, $comm->direction);
        $this->assertSame($send->subject_snapshot, $comm->subject, 'archive subject must equal the sent subject');
        $this->assertSame($send->body_snapshot, $comm->body_text);

        $contact->refresh();
        $this->assertSame(1, $contact->outboundCommCount(Communication::CHANNEL_EMAIL));
        $this->assertSame(0, $contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'whatsapp tile untouched');
    }

    /** 3 — Bulk: 3 recipients in one compose each get their OWN linked row, no cross-linking. */
    public function test_bulk_send_links_each_recipient_independently(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);

        /** @var Contact[] $contacts */
        $contacts = [
            $this->seedContactWithAddress($agencyId, 'Thandi'),
            $this->seedContactWithAddress($agencyId, 'Sipho'),
            $this->seedContactWithAddress($agencyId, 'Naledi'),
        ];

        // One compose → many recipients: the composer is invoked per recipient
        // (send() is the per-recipient chokepoint). Simulate the blast.
        foreach ($contacts as $c) {
            $this->actingAs(User::find($userId))
                ->postJson(route('seller-outreach.composer.submit', $c), [
                    'channel' => 'whatsapp',
                    'body'    => "Hi {seller_name}. {tracking_link} Reply STOP.",
                ])->assertOk();
        }

        // Each contact has EXACTLY one outbound whatsapp row, correctly linked, no cross-talk.
        foreach ($contacts as $c) {
            $c->refresh();
            $this->assertSame(1, $c->outboundCommCount(Communication::CHANNEL_WHATSAPP), "contact {$c->id} count");
            $comm = $this->soleCommunicationFor($c, Communication::CHANNEL_WHATSAPP);
            $links = CommunicationLink::withoutGlobalScopes()->where('communication_id', $comm->id)->get();
            $this->assertCount(1, $links, 'each archive row links to exactly one contact');
            $this->assertSame($c->id, (int) $links->first()->linkable_id, 'no cross-linking between recipients');
            $this->assertNotNull($c->last_contacted_at);
        }

        // 3 distinct communications + 3 distinct sends — one per recipient.
        $this->assertSame(3, Communication::withoutGlobalScopes()->where('agency_id', $agencyId)->count());
        $this->assertSame(3, SellerOutreachSend::withoutGlobalScopes()->where('agency_id', $agencyId)->count());
    }

    /** 4 — Dedup: the real Sent message ingesting later reconciles in place — no double count. */
    public function test_real_ingest_reconciles_provisional_with_no_double_count(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi {seller_name}. {tracking_link} Reply STOP.",
            ])->assertOk();

        $contact->refresh();
        $this->assertSame(1, $contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'provisional counts as 1');

        $provisional = $this->soleCommunicationFor($contact, Communication::CHANNEL_WHATSAPP);
        $this->assertNotNull($provisional->provisional_at);

        // Simulate the WA/Sent-folder ingestion of the SAME message: same text_hash
        // is the deterministic reconcile key (agent did not edit before sending).
        $promoted = app(ProvisionalReconciler::class)->reconcileOutbound(
            $contact,
            Communication::CHANNEL_WHATSAPP,
            [
                'external_id'  => 'wa:' . Str::random(16),
                'occurred_at'  => now()->addSeconds(30),
                'captured_at'  => now()->addSeconds(31),
                'body_text'    => $provisional->body_text,
                'text_hash'    => $provisional->text_hash,
                'content_hash' => hash('sha256', 'raw-wa-payload'),
                'source_ref'   => 'wa-ingest',
            ],
        );

        $this->assertNotNull($promoted, 'ingest must reconcile the provisional row, not insert a new one');
        $this->assertSame($provisional->id, $promoted->id, 'same row promoted in place');

        $contact->refresh();
        // STILL exactly one row / one count — no double count.
        $this->assertSame(1, $contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'no double count after ingest');
        $this->assertSame(1, Communication::withoutGlobalScopes()->where('agency_id', $agencyId)->count());

        $promoted->refresh();
        $this->assertNull($promoted->provisional_at, 'row promoted to confirmed');
        $link = CommunicationLink::withoutGlobalScopes()->where('communication_id', $promoted->id)->sole();
        $this->assertNotNull($link->confirmed_at, 'link confirmed on reconcile');
    }

    /** 5 — Atomicity: if the archive write throws, the send row rolls back too (no orphan). */
    public function test_archive_write_failure_rolls_back_the_send_row(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $throwingLogger = new class extends OutboundProvisionalLogger {
            public function log(Contact $contact, string $channel, ?string $subject, ?string $body, ?int $userId = null): Communication
            {
                throw new \RuntimeException('simulated archive write failure');
            }
        };

        $context = app(SellerOutreachComposerService::class)->composeContext(
            agencyId: $agencyId,
            contact:  $contact,
            property: null,
            channel:  'whatsapp',
            agent:    User::find($userId),
            bodyOverride: "Hi {seller_name}. {tracking_link} Reply STOP.",
        );

        $sender = new SellerOutreachSenderService($throwingLogger);

        try {
            $sender->send($context);
            $this->fail('send() should have propagated the archive write failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('simulated archive write failure', $e->getMessage());
        }

        // The per-recipient transaction rolled back fully — no orphan send row,
        // no orphan archive row.
        $this->assertSame(0, SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $contact->id)->count(), 'send row rolled back');
        $this->assertSame(0, Communication::withoutGlobalScopes()->where('agency_id', $agencyId)->count(), 'no orphan archive row');
        $contact->refresh();
        $this->assertNull($contact->last_contacted_at, 'last_contacted_at not advanced on rollback');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function soleCommunicationFor(Contact $contact, string $channel): Communication
    {
        $ids = CommunicationLink::withoutGlobalScopes()
            ->where('linkable_type', Contact::class)
            ->where('linkable_id', $contact->id)
            ->pluck('communication_id');

        return Communication::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('channel', $channel)
            ->sole();
    }

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
            'phone'     => '+27821110000',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedContactWithAddress(int $agencyId, string $firstName = 'Thandi'): Contact
    {
        return Contact::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'first_name'    => $firstName,
            'last_name'     => 'Mkhize',
            'phone'         => '+2782' . random_int(1000000, 9999999),
            'email'         => strtolower($firstName) . '-' . Str::random(6) . '@example.test',
            'street_number' => '14',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Margate',
        ]);
    }

    private function seedDefaultTemplate(int $agencyId): void
    {
        DB::table('seller_outreach_templates')->insert([
            'agency_id'              => $agencyId,
            'name'                   => 'Initial outreach — sale',
            'channel'                => 'whatsapp',
            'subject'                => null,
            'body'                   => "Hi {seller_name}, demand is strong in {property_town}. {tracking_link} Reply STOP to {opt_out_link}.",
            'description'            => 'test default',
            'is_active'              => true,
            'is_default_for_channel' => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    private function seedEmailTemplate(int $agencyId): void
    {
        DB::table('seller_outreach_templates')->insert([
            'agency_id'              => $agencyId,
            'name'                   => 'Initial outreach — email',
            'channel'                => 'email',
            'subject'                => 'Demand for your home in {property_town}',
            'body'                   => "Hi {seller_name}, buyers are searching in {property_town}. {tracking_link} Tap {opt_out_link} to stop.",
            'description'            => 'test default email',
            'is_active'              => true,
            'is_default_for_channel' => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }
}
