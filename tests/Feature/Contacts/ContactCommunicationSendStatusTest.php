<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact-details Phase 4 — outreach could-not-send flow: flag a send as
 * not_delivered, reselect a different number/email and resend, revert, and
 * confirm none of this ever leaves a failed single send counted as
 * "communicated".
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

    /** Reverting a not_delivered flag restores the count and last_contacted_at. */
    public function test_reverting_restores_count_and_last_contacted(): void
    {
        $comm = $this->logSend();
        $this->post(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]));
        $this->contact->refresh();
        $this->assertNull($this->contact->last_contacted_at);

        $this->post(route('corex.contacts.communications.revert', [$this->contact, $comm]))
            ->assertSessionHasNoErrors();

        $this->contact->refresh();
        $this->assertNotNull($this->contact->last_contacted_at);
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP));
        $this->assertSame(Communication::SEND_STATUS_SENT, $comm->fresh()->send_status);
    }

    /** Resend creates a linked NEW row to the reselected number; the original is untouched. */
    public function test_resend_creates_a_linked_new_row_to_the_reselected_number(): void
    {
        $altPhone = $this->contact->phones()->create([
            'agency_id' => $this->agencyId, 'phone' => '0829999999', 'is_primary' => false,
        ]);

        $comm = $this->logSend();
        $this->post(route('corex.contacts.communications.not-delivered', [$this->contact, $comm]));

        $this->post(route('corex.contacts.communications.resend', [$this->contact, $comm]), [
            'contact_phone_id' => $altPhone->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Communication::SEND_STATUS_NOT_DELIVERED, $comm->fresh()->send_status, 'original is never modified');

        $new = Communication::where('resent_from_communication_id', $comm->id)->firstOrFail();
        $this->assertSame(Communication::SEND_STATUS_SENT, $new->send_status);
        $this->assertSame('0829999999', $new->participant_identifiers[0] ?? null);

        $this->contact->refresh();
        $this->assertSame(1, $this->contact->outboundCommCount(Communication::CHANNEL_WHATSAPP), 'only the resend counts, not the failed original');
    }

    /** A not-delivered send can only be resent (not a still-sent one). */
    public function test_resend_is_rejected_for_a_send_that_was_never_flagged(): void
    {
        $altPhone = $this->contact->phones()->create([
            'agency_id' => $this->agencyId, 'phone' => '0829999999', 'is_primary' => false,
        ]);
        $comm = $this->logSend();

        $this->postJson(route('corex.contacts.communications.resend', [$this->contact, $comm]), [
            'contact_phone_id' => $altPhone->id,
        ])->assertStatus(400);
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

        $this->post(route('corex.contacts.communications.revert', [$this->contact, $comm]));
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
