<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\WaArchiveIngestor;
use App\Services\Communications\WaBodyBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-135 — @lid-NATIVE body backfill. WhatsApp Web lists chats by @lid, which
 * carries no phone; the backfill target set now ships the @lid digit-key so the
 * sweep matches an @lid chat directly (no reverse @lid→phone resolution — the
 * asymmetry that left Elize's body unrecovered). Consent (AT-136) is NOT bypassed:
 * the @lid set is the opted-in pending set, and the server re-checks opt-in before
 * filling. These tests lock the matching fix AND the gate integrity together.
 */
final class WaLidBackfillTargetTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '222758646611979@lid';
    private const LID_DIGITS = '222758646611979';

    private int $agencyId;
    private int $agentUserId;
    private string $plainToken;
    private CommunicationWaDevice $device;
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
        $user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->agentUserId = (int) $user->id;

        $this->plainToken = Str::random(48);
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->agentUserId,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', $this->plainToken), 'active' => true,
        ]);

        // The @lid chat resolves (via counterpart_phone) to this real contact.
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel',
            'phone' => '0713510291', 'email' => null,
        ]);
    }

    /** An @lid message addressed to a real contact, captured via counterpart_phone. */
    private function lidMessage(array $over = []): array
    {
        return array_merge([
            'message_id'        => 'WA-' . Str::random(10),
            'chat_id'           => self::LID,
            'direction'         => 'out',
            'sender'            => null,
            'timestamp'         => now()->timestamp,
            'text'              => 'Body text that must be consent-gated',
            'has_media'         => false,
            'counterpart_phone' => '27713510291@c.us',
            'counterpart_lid'   => self::LID,
        ], $over);
    }

    private function setConsent(string $status): void
    {
        AgentCaptureConsent::create([
            'agency_id'     => $this->agencyId,
            'agent_user_id' => $this->agentUserId,
            'contact_id'    => $this->contact->id,
            'status'        => $status,
        ]);
    }

    private function ingest(array $msg): string
    {
        return app(WaArchiveIngestor::class)->ingest($this->device, $msg);
    }

    public function test_ingest_stores_counterpart_lid_digits_on_the_row(): void
    {
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);
        $this->ingest($this->lidMessage());

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertSame(self::LID_DIGITS, $comm->counterpart_lid, '@lid digits stored as the matchable key');
    }

    public function test_pending_at_ingest_then_optin_puts_the_lid_in_targets(): void
    {
        // Message arrives while the agent has NOT yet decided → body withheld,
        // envelope (incl. counterpart_lid) archived. This is Elize's exact case.
        $this->ingest($this->lidMessage());
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        // AT-168 Part B — withheld bodies are now EMBARGOED (stored, never shown),
        // not discarded; the AT-135 sweep still targets them (body_text empty).
        $this->assertSame('embargoed', $comm->body_status);
        $this->assertNull($comm->body_text, 'body withheld (embargoed) until opt-in');
        $this->assertSame(self::LID_DIGITS, $comm->counterpart_lid);

        $svc = app(WaBodyBackfillService::class);
        // Not a target yet — no opt-in.
        $this->assertNotContains(self::LID_DIGITS, $svc->pendingBodyLids($this->agencyId));

        // Agent opts in → the @lid is now a backfill target (the sweep can match it).
        AgentCaptureConsent::where('agent_user_id', $this->agentUserId)
            ->where('contact_id', $this->contact->id)
            ->update(['status' => AgentCaptureConsent::STATUS_OPTED_IN]);

        $this->assertContains(self::LID_DIGITS, $svc->pendingBodyLids($this->agencyId),
            '@lid of an opted-in withheld message is a backfill target');
    }

    public function test_backfill_targets_endpoint_returns_the_lid(): void
    {
        $this->ingest($this->lidMessage());
        AgentCaptureConsent::where('contact_id', $this->contact->id)
            ->update(['status' => AgentCaptureConsent::STATUS_OPTED_IN]);

        $this->withToken($this->plainToken)
            ->getJson(route('communications.wa.backfill-targets'))
            ->assertOk()
            ->assertJsonFragment(['lids' => [self::LID_DIGITS]]);
    }

    public function test_gate_integrity_opted_out_lid_is_not_a_target_and_body_is_not_filled(): void
    {
        // Withheld at ingest, then the agent OPTS OUT.
        $this->ingest($this->lidMessage());
        AgentCaptureConsent::where('contact_id', $this->contact->id)
            ->update(['status' => AgentCaptureConsent::STATUS_OPTED_OUT]);

        $svc = app(WaBodyBackfillService::class);
        $this->assertNotContains(self::LID_DIGITS, $svc->pendingBodyLids($this->agencyId),
            'opted-out @lid must NEVER be a backfill target');

        // Even if the extension re-POSTed the body (same external_id), the server
        // must refuse to fill it — consent gate is not bypassed by the @lid match.
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $result = $this->ingest($this->lidMessage(['message_id' => $comm->external_id]));
        $this->assertSame(WaArchiveIngestor::RESULT_DUPLICATE, $result);

        $comm->refresh();
        $this->assertNull($comm->body_text, 'opted-out body stays withheld');
        $this->assertNotSame('captured', $comm->body_status);
    }

    public function test_occurred_at_is_stored_in_app_timezone_not_utc(): void
    {
        // Regression: a Unix timestamp was stored as a UTC wall-clock, landing
        // occurred_at hours behind created_at (now() is app-tz) — so a just-sent
        // message displayed 2h in the past and read as "capture stopped".
        config(['app.timezone' => 'Africa/Johannesburg']);
        $this->setConsent(AgentCaptureConsent::STATUS_OPTED_IN);

        $epoch = 1751302502; // a fixed instant
        $this->ingest($this->lidMessage(['timestamp' => $epoch]));

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $expectedSast = \Illuminate\Support\Carbon::createFromTimestamp($epoch, 'Africa/Johannesburg')->toDateTimeString();
        $oldUtcBug    = \Illuminate\Support\Carbon::createFromTimestamp($epoch, 'UTC')->toDateTimeString();

        $this->assertSame($expectedSast, $comm->occurred_at->toDateTimeString(), 'occurred_at must be app-tz wall-clock');
        $this->assertNotSame($oldUtcBug, $comm->occurred_at->toDateTimeString(), 'must NOT be the old UTC wall-clock');
    }

    public function test_optin_then_rescrape_fills_the_withheld_lid_body(): void
    {
        // The full recovery path: withheld at ingest → opt-in → extension re-scrapes
        // (same external_id, now with text) → body filled.
        $this->ingest($this->lidMessage());
        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        AgentCaptureConsent::where('contact_id', $this->contact->id)
            ->update(['status' => AgentCaptureConsent::STATUS_OPTED_IN]);

        $result = $this->ingest($this->lidMessage(['message_id' => $comm->external_id]));
        $this->assertSame(WaArchiveIngestor::RESULT_BODY_FILLED, $result);

        $comm->refresh();
        $this->assertSame('captured', $comm->body_status);
        $this->assertNotNull($comm->body_text);
    }
}
