<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Services\Outreach\OutreachWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-323 — a CoreX WhatsApp "send" is client-side click-to-chat: CoreX opens WhatsApp
 * (whatsapp://send) but never transmits and has NO delivery signal of its own. So (since
 * b2e75cfc7) the row is born UNCONFIRMED — send_status='not_delivered', not counted — and the
 * ALWAYS-SHOWN post-send "Did you send it?" confirmation is the only truthful signal that
 * decides the final status:
 *   • "No, not sent"   -> POST .../communications/{id}/not-delivered -> stays not_delivered
 *   • "Yes, I sent it" -> POST .../communications/{id}/mark-sent     -> sent (counted)
 *
 * The earlier connection-guard (infer not_delivered from the agent's WhatsApp-Web/WAHA
 * session at click time) is DEMOTED and removed from this path: it conflated "integration
 * connected" with "this message sent" and mislabelled a genuine phone-send. Automatic
 * delivery truth is option 1 (server-side WAHA send + ack), a separate project.
 */
final class WhatsAppSendConfirmationTest extends TestCase
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

    /**
     * AT-323 (b2e75cfc7): a WhatsApp "send" is client-side click-to-chat — CoreX opens WhatsApp
     * but never transmits — so the row is born UNCONFIRMED (send_status=not_delivered, not
     * counted) and returns its id so the ALWAYS-SHOWN confirm modal can ask. Even with NO
     * WhatsApp session linked (the QA1 / sessionless case) nothing preempts that, and there is
     * no not_connected flag on the response.
     */
    public function test_whatsapp_send_is_born_unconfirmed_and_returns_comm_id(): void
    {
        // No CommunicationWaDevice for the agent — sessionless, exactly the repro environment.
        $res = $this->sendWa();
        $res->assertOk();
        $res->assertJson(['send_status' => Communication::SEND_STATUS_NOT_DELIVERED]);
        $this->assertNotNull($res->json('communication_id'), 'must return the row id so the confirm modal shows');
        $this->assertArrayNotHasKey('not_connected', $res->json(), 'connection-guard is demoted — no not_connected preempt');

        $comm = $this->latestOutbound();
        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->send_status);

        $this->contact->refresh();
        $this->assertSame(0, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'an unconfirmed send is not counted');
        $this->assertNull($this->contact->last_contacted_at, 'an unconfirmed send never advances last_contacted');
    }

    /**
     * "No, not sent" — the confirm modal POSTs the row to not-delivered: it stays not_delivered
     * (idempotent) and never counts as a reached contact. A message the agent closed without
     * sending is never a false 'sent'.
     */
    public function test_confirm_not_sent_records_not_delivered(): void
    {
        $this->sendWa()->assertOk();
        $comm = $this->latestOutbound();
        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->send_status, 'born unconfirmed');

        $this->postJson(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]), [
            'reason' => 'Agent reported WhatsApp did not send (closed without sending).',
        ])->assertOk();

        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->fresh()->send_status);
        $this->contact->refresh();
        $this->assertSame(0, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'a not-sent message is not counted as reached');
        $this->assertNull($this->contact->last_contacted_at, 'last_contacted stays empty when the only send is not-sent');
    }

    /**
     * "Yes, I sent it" — the modal POSTs mark-sent: that is the ONLY path to 'sent', and only
     * then does the row count as a reached contact and advance last_contacted.
     */
    public function test_confirm_sent_moves_the_row_to_sent_and_counts_it(): void
    {
        $this->sendWa()->assertOk();
        $comm = $this->latestOutbound();
        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->send_status, 'not counted until the agent confirms');

        $this->postJson(route('corex.contacts.communications.mark-sent', [$this->contact, $comm]))
            ->assertOk();

        $this->assertSame(Communication::SEND_STATUS_SENT, $comm->fresh()->send_status);
        $this->contact->refresh();
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
        $this->assertNotNull($this->contact->last_contacted_at);
    }
}
