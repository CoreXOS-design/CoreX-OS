<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\CommunicationSendStatusService;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact-details Phase 4 — outreach could-not-send flow: flag a send as
 * not_delivered, reselect a different number/email and resend, and confirm none
 * of this ever leaves a failed single send counted as "communicated".
 *
 * AT-323 (b2e75cfc7) reshaped the way back: there is NO "Revert" route — the modal's
 * "Yes, I sent it" (`…/mark-sent`) is the ONLY path to sent — and "Resend" is no longer
 * a server route (it re-runs the whole send flow in the browser); the linked-row
 * bookkeeping lives in CommunicationSendStatusService::resend(), tested directly here.
 */
final class ContactCommunicationSendStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
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
            'agency_id' => $this->agencyId, 'first_name' => 'Send', 'last_name' => 'Status',
            'phone' => '0821111111', 'email' => 'sendstatus@example.com',
        ]);
    }

    private function logSend(string $channel = Communication::CHANNEL_WHATSAPP): Communication
    {
        return app(OutboundProvisionalLogger::class)->log(
            $this->contact, $channel, null, 'Hi there', $this->agent->id,
        );
    }

    /** Flagging a contact's ONLY send as not_delivered retracts last_contacted_at to null. */
    public function test_marking_the_only_send_not_delivered_clears_last_contacted(): void
    {
        $comm = $this->logSend();
        $this->contact->refresh();
        $this->assertNotNull($this->contact->last_contacted_at);
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));

        $this->post(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]))
            ->assertSessionHasNoErrors();

        $this->contact->refresh();
        $this->assertNull($this->contact->last_contacted_at, 'a failed single send must not count as communicated');
        $this->assertSame(0, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->fresh()->send_status);
        $this->assertSame($this->agent->id, $comm->fresh()->send_status_set_by_user_id);
    }

    /** "Yes, I sent it" (the modal's answer) is the one path back to sent: it restores the count and last_contacted_at. */
    public function test_confirming_sent_restores_count_and_last_contacted(): void
    {
        $comm = $this->logSend();
        $this->post(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]));
        $this->contact->refresh();
        $this->assertNull($this->contact->last_contacted_at);

        $this->postJson(route('corex.contacts.communications.mark-sent', [$this->contact, $comm]))
            ->assertOk()->assertJson(['ok' => true, 'count' => 1]);

        $this->contact->refresh();
        $this->assertNotNull($this->contact->last_contacted_at);
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
        $this->assertSame(Communication::SEND_STATUS_SENT, $comm->fresh()->send_status);
    }

    /** Resend creates a linked NEW row to the reselected number; the original is untouched. */
    public function test_resend_creates_a_linked_new_row_to_the_reselected_number(): void
    {
        $comm = $this->logSend();
        $this->post(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]));

        app(CommunicationSendStatusService::class)->resend($comm->fresh(), $this->contact, '0829999999', null, 'Hi there', $this->agent->id);

        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->fresh()->send_status, 'original is never modified');

        $new = Communication::where('resent_from_communication_id', $comm->id)->firstOrFail();
        $this->assertSame(Communication::SEND_STATUS_SENT, $new->send_status);
        $this->assertSame('0829999999', $new->participant_identifiers[0] ?? null);

        $this->contact->refresh();
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'only the resend counts, not the failed original');
    }

    /**
     * AT-323 INVARIANT 3 — no false-sent path. The old "Revert to sent" and the silent server-side "Resend" (which recorded a
     * sent row with no modal) were removed; this keeps them removed. The only way to sent is `mark-sent` (the modal's "Yes").
     */
    public function test_there_is_no_revert_or_silent_resend_route_only_the_modal_confirmation(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('corex.contacts.communications.revert'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('corex.contacts.communications.resend'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('corex.contacts.communications.mark-sent'));
    }

    /** Each status transition is recorded in domain_event_log (the "created → flagged → resent" audit chain). */
    public function test_status_transitions_are_domain_audited(): void
    {
        $comm = $this->logSend();
        $this->post(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]), ['reason' => 'wrong number']);

        $this->assertDatabaseHas('domain_event_log', [
            'event_name' => \App\Events\Communication\CommunicationMarkedNotDelivered::class,
            'subject_type' => Contact::class,
            'subject_id' => $this->contact->id,
            'actor_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.contacts.communications.mark-sent', [$this->contact, $comm]))->assertOk();
        $this->assertDatabaseHas('domain_event_log', [
            'event_name' => \App\Events\Communication\CommunicationSendStatusReverted::class,
            'subject_id' => $this->contact->id,
        ]);
    }

    /** A communication belonging to another contact 404s — no cross-contact status writes. */
    public function test_a_communication_from_another_contact_cannot_be_flagged(): void
    {
        $otherContact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Other', 'last_name' => 'Contact',
        ]);
        $foreignComm = app(OutboundProvisionalLogger::class)->log(
            $otherContact, Communication::CHANNEL_WHATSAPP, null, 'Hi', $this->agent->id,
        );

        $this->postJson(route('corex.contacts.communications.not-delivered', [$this->contact, $foreignComm]))
            ->assertNotFound();
    }
}
