<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\WahaSessionClient;
use App\Services\Communications\WahaUnavailableException;
use App\Services\Outreach\OutreachWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-323 — a WhatsApp "send" is click-to-chat: CoreX never transmits, it just records an outbound
 * row (born send_status='sent' optimistically). If the agent has no LIVE WhatsApp session (not
 * signed in), the message could not have gone out — the send must be recorded as not_delivered,
 * NOT a false 'sent'. When WAHA is unreachable we cannot tell, so we keep 'sent' (never mass-flag
 * on an outage). The agent-facing recovery (Revert / Resend) already exists for a genuine phone-send.
 */
final class WhatsAppNotConnectedSendStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'WA ' . Str::random(6), 'slug' => 'wa-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
        $this->actingAs($this->agent);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Wa', 'last_name' => 'Target',
            'phone' => '0821234567', 'email' => 'wa@example.com',
        ]);

        // The send-window gate is orthogonal to AT-323 — always allow, deterministically.
        $this->mock(OutreachWindowService::class, function ($m) {
            $m->shouldReceive('isSendAllowed')->andReturnTrue();
            $m->shouldReceive('blockedMessage')->andReturn('');
        });
    }

    private function linkWorkingDevice(string $session = 'sess-live'): void
    {
        CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->agent->id,
            'wa_number' => '27821234567', 'waha_session' => $session,
            'device_token' => Str::random(20), 'last_seen_at' => now(), 'active' => true,
        ]);
    }

    private function sendWa(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('corex.contacts.increment', $this->contact), [
            'channel' => 'whatsapp', 'body' => 'Hi there',
        ]);
    }

    private function latestOutbound(): Communication
    {
        return Communication::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->latest('id')->firstOrFail();
    }

    /** Not signed in (no linked WA session) → recorded not_delivered, NOT a false 'sent'. */
    public function test_not_signed_in_records_not_delivered_not_a_false_sent(): void
    {
        // No CommunicationWaDevice for the agent → no live session.
        $res = $this->sendWa();
        $res->assertOk();
        $res->assertJson(['not_connected' => true, 'send_status' => Communication::SEND_STATUS_NOT_DELIVERED]);

        $comm = $this->latestOutbound();
        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->send_status);
        $this->assertSame($this->agent->id, $comm->send_status_set_by_user_id);

        // A not-delivered send must NOT count as communicated.
        $this->contact->refresh();
        $this->assertSame(0, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
        $this->assertNull($this->contact->last_contacted_at);
    }

    /** WORKING WhatsApp session → recorded 'sent' (unchanged behaviour). */
    public function test_working_session_records_sent(): void
    {
        $this->linkWorkingDevice('sess-live');
        $this->mock(WahaSessionClient::class, function ($m) {
            $m->shouldReceive('status')->with('sess-live')
              ->andReturn(['exists' => true, 'status' => 'WORKING', 'me' => null]);
        });

        $res = $this->sendWa();
        $res->assertOk();
        $res->assertJson(['not_connected' => false, 'send_status' => Communication::SEND_STATUS_SENT]);

        $this->assertSame(Communication::SEND_STATUS_SENT, $this->latestOutbound()->send_status);
        $this->contact->refresh();
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
    }

    /** A non-WORKING session state (e.g. SCAN_QR_CODE — signed out) → not_delivered. */
    public function test_session_not_working_records_not_delivered(): void
    {
        $this->linkWorkingDevice('sess-idle');
        $this->mock(WahaSessionClient::class, function ($m) {
            $m->shouldReceive('status')->with('sess-idle')
              ->andReturn(['exists' => true, 'status' => 'SCAN_QR_CODE', 'me' => null]);
        });

        $this->sendWa()->assertOk()->assertJson(['not_connected' => true]);
        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $this->latestOutbound()->send_status);
    }

    /** WAHA unreachable → we cannot tell, so keep 'sent' (never mass-flag on an outage). */
    public function test_waha_unreachable_keeps_sent(): void
    {
        $this->linkWorkingDevice('sess-x');
        $this->mock(WahaSessionClient::class, function ($m) {
            $m->shouldReceive('status')->andThrow(new WahaUnavailableException('WAHA down'));
        });

        $this->sendWa()->assertOk()->assertJson(['not_connected' => false, 'send_status' => Communication::SEND_STATUS_SENT]);
        $this->assertSame(Communication::SEND_STATUS_SENT, $this->latestOutbound()->send_status);
    }
}
