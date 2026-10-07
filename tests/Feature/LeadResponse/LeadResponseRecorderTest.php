<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationMailbox;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\ContactMatch;
use App\Models\ContactNote;
use App\Services\Communications\EmailArchiveIngestor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What counts as the FIRST response to an enquiry (Johan, 2026-10-07):
 *   YES  the explicit contacted action ("Contacted and note" / Mark as Now), a message actually sent, a link
 *        share confirmed, feedback captured on an appointment the contact is part of.
 *   NO   a note on its own — "Note only" and the Viewing booked / Viewing done / Offer discussed quick-picks.
 * Recorded once, never overwritten; ignored if it pre-dates the lead; untracked (old) leads are never touched;
 * a manager responding counts on the agent's lead and is shown as the responder.
 */
final class LeadResponseRecorderTest extends LeadResponseTestCase
{
    private function fresh(\App\Models\PortalLead $lead): \App\Models\PortalLead
    {
        return \App\Models\PortalLead::withoutGlobalScopes()->find($lead->id);
    }

    public function test_the_contacted_action_is_the_first_response_set_once_and_never_overwritten(): void
    {
        $lead = $this->lead(['received' => '2026-10-07 09:00:00']);
        $contact = $lead->contact;

        $this->actingAs($this->manager);
        $contact->markContacted(Carbon::parse('2026-10-07 09:20:00', 'Africa/Johannesburg'));

        $l = $this->fresh($lead);
        $this->assertSame('2026-10-07 09:20:00', $l->first_response_at->format('Y-m-d H:i:s'));
        $this->assertSame('contacted_action', $l->first_response_channel);
        $this->assertSame($this->manager->id, (int) $l->first_response_by_user_id, 'a manager answering is recorded as the responder');

        $contact->markContacted(Carbon::parse('2026-10-07 11:00:00', 'Africa/Johannesburg'));
        $this->assertSame('2026-10-07 09:20:00', $this->fresh($lead)->first_response_at->format('Y-m-d H:i:s'), 'never overwritten');
    }

    public function test_contacted_and_note_counts_but_a_note_only_does_not(): void
    {
        $lead = $this->lead(['received' => '2026-10-07 09:00:00']);
        $contact = $lead->contact;
        $this->actingAs($this->agentA);

        // "Note only", free text, and the three quick-picks the plan once considered — none is a contact.
        $this->post(route('corex.contacts.notes.store', $contact), ['body' => 'Called, no answer'])->assertRedirect();
        foreach (['Viewing booked', 'Viewing done', 'Offer discussed'] as $type) {
            $this->post(route('corex.contacts.notes.store', $contact), ['type' => $type])->assertRedirect();
        }
        $this->assertSame(4, ContactNote::withoutGlobalScopes()->where('contact_id', $contact->id)->count());
        $this->assertNull($this->fresh($lead)->first_response_at);

        // The explicit "Contacted and note" action IS.
        $this->post(route('corex.contacts.notes.store', $contact), ['body' => 'Spoke to them', 'mark_contacted' => 1])->assertRedirect();
        $l = $this->fresh($lead);
        $this->assertNotNull($l->first_response_at);
        $this->assertSame('contacted_action', $l->first_response_channel);
        $this->assertSame($this->agentA->id, (int) $l->first_response_by_user_id);
    }

    public function test_mark_as_now_on_the_last_contacted_tile_is_the_contacted_action(): void
    {
        $lead = $this->lead(['received' => '2026-10-07 09:00:00']);
        $this->actingAs($this->agentA)->post(route('corex.contacts.touch', $lead->contact), ['last_contacted_at' => '2026-10-07 10:00:00'])->assertRedirect();

        $this->assertSame('contacted_action', $this->fresh($lead)->first_response_channel);
    }

    public function test_an_outbound_message_that_went_out_counts_and_an_inbound_one_does_not(): void
    {
        Storage::fake('local');
        $contact = $this->contact('Bea', $this->agentA);
        $contact->forceFill(['email' => 'bea@example.com'])->save();
        $lead = $this->lead(['contact' => $contact, 'received' => '2026-10-07 09:00:00']);
        $mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agentB->id, 'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ]);
        $msg = fn (array $o = []) => array_merge([
            'external_id' => '<m-' . Str::random(8) . '@agency.test>', 'thread_key' => '<t@agency.test>', 'from' => 'bea@example.com',
            'counterpart' => 'bea@example.com', 'participants' => ['bea@example.com', 'office@agency.test'], 'subject' => 'Hi',
            'body_text' => 'Hello', 'occurred_at' => Carbon::parse('2026-10-07 09:15:00', 'Africa/Johannesburg'),
            'raw' => "Message-ID: x\r\n\r\n" . Str::random(20), 'attachments' => [],
        ], $o);

        app(EmailArchiveIngestor::class)->ingest($mailbox, $msg(), Communication::DIRECTION_INBOUND);
        $this->assertNull($this->fresh($lead)->first_response_at, 'the enquirer writing to us is not us responding');

        app(EmailArchiveIngestor::class)->ingest($mailbox, $msg(), Communication::DIRECTION_OUTBOUND);
        $l = $this->fresh($lead);
        $this->assertSame('message', $l->first_response_channel);
        $this->assertSame($this->agentB->id, (int) $l->first_response_by_user_id, 'the mailbox owner sent it');
        $this->assertSame('2026-10-07 09:15:00', $l->first_response_at->format('Y-m-d H:i:s'));
    }

    public function test_a_confirmed_link_share_counts_but_only_when_confirmed(): void
    {
        $lead = $this->lead(['received' => '2026-10-07 09:00:00']);
        $match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $lead->contact_id, 'created_by_user_id' => $this->agentA->id,
            'agent_id' => $this->agentA->id, 'name' => 'W', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
        ]);

        $share = $match->mintShareLink($this->agentA->id);
        $this->assertNull($this->fresh($lead)->first_response_at, 'minting a link is not sharing it');

        $share->confirmSent('whatsapp');
        $l = $this->fresh($lead);
        $this->assertSame('shared_link', $l->first_response_channel);
        $this->assertSame($this->agentA->id, (int) $l->first_response_by_user_id);
    }

    public function test_feedback_on_an_appointment_the_contact_is_part_of_counts(): void
    {
        $lead = $this->lead(['received' => '2026-10-07 09:00:00']);
        $event = CalendarEvent::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id, 'user_id' => $this->agentA->id, 'created_by_id' => $this->agentA->id,
            'event_type' => 'appointment', 'category' => 'viewing', 'title' => 'Viewing', 'event_date' => '2026-10-07 10:00:00',
        ]);

        // Merely being on the appointment is not enough…
        $this->assertNull($this->fresh($lead)->first_response_at);

        // …feedback provided on it is.
        CalendarEventFeedback::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id, 'calendar_event_id' => $event->id, 'contact_id' => $lead->contact_id,
            'feedback_kind' => 'viewing', 'visibility' => 'internal_only', 'captured_by_user_id' => $this->agentA->id,
            'captured_at' => Carbon::parse('2026-10-07 11:00:00', 'Africa/Johannesburg'),
        ]);

        $l = $this->fresh($lead);
        $this->assertSame('appointment_feedback', $l->first_response_channel);
        $this->assertSame('2026-10-07 11:00:00', $l->first_response_at->format('Y-m-d H:i:s'));
    }

    public function test_a_contact_before_the_lead_arrived_is_ignored_and_a_repeat_enquiry_starts_a_fresh_clock(): void
    {
        $contact = $this->contact('Rae', $this->agentA);
        $first = $this->lead(['contact' => $contact, 'received' => '2026-10-05 09:00:00']);
        $this->actingAs($this->agentA);

        // Contact dated BEFORE the second enquiry exists: it answers the first, not a later one.
        $contact->markContacted(Carbon::parse('2026-10-05 09:30:00', 'Africa/Johannesburg'));
        $second = $this->lead(['contact' => $contact, 'received' => '2026-10-07 09:00:00']);
        $this->assertSame('2026-10-05 09:30:00', $this->fresh($first)->first_response_at->format('Y-m-d H:i:s'));
        $this->assertNull($this->fresh($second)->first_response_at, 'a repeat enquiry starts a fresh clock');

        // An action dated before the lead arrived is ignored.
        $contact->markContacted(Carbon::parse('2026-10-07 08:00:00', 'Africa/Johannesburg'));
        $this->assertNull($this->fresh($second)->first_response_at);

        $contact->markContacted(Carbon::parse('2026-10-07 09:40:00', 'Africa/Johannesburg'));
        $this->assertSame('2026-10-07 09:40:00', $this->fresh($second)->first_response_at->format('Y-m-d H:i:s'));
    }

    public function test_one_contact_answers_every_open_enquiry_already_received(): void
    {
        $contact = $this->contact('Dee', $this->agentA);
        $a = $this->lead(['contact' => $contact, 'received' => '2026-10-07 08:30:00']);
        $b = $this->lead(['contact' => $contact, 'received' => '2026-10-07 09:00:00', 'portal' => 'pp']);
        $this->actingAs($this->agentA);
        $contact->markContacted(Carbon::parse('2026-10-07 09:30:00', 'Africa/Johannesburg'));

        $this->assertNotNull($this->fresh($a)->first_response_at);
        $this->assertNotNull($this->fresh($b)->first_response_at);
    }

    public function test_enquiries_from_before_tracking_are_never_touched(): void
    {
        $old = $this->lead(['received' => '2026-09-01 09:00:00', 'tracked' => false]);
        $this->actingAs($this->agentA);
        $old->contact->markContacted(Carbon::parse('2026-10-07 09:30:00', 'Africa/Johannesburg'));

        $this->assertNull($this->fresh($old)->first_response_at, 'no inflated "response" months after the fact');
        $this->assertFalse((bool) $this->fresh($old)->response_tracked);
    }
}
