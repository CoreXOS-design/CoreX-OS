<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\User;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Johan, 2026-10-07 (supersedes the earlier "any note resets Last Contacted"):
 * "Only 'Contacted and note' moves it. 'Note only' could be anything and does not
 * mean the contact was contacted; 'Contacted and note' means it."
 *
 * One rule, one writer: a ContactNote never touches the marker; the explicit
 * mark_contacted action (ContactNoteController::store → Contact::markContacted())
 * is the only note-driven mover. A real outbound message still moves it.
 *
 * Paths proven: contact screen (notes tab / tile modal / buyer pipeline redirects), Core
 * Matches popup (redirect_to=back), mobile API, quick-pick-only note, body-only edit,
 * system-generated/model-level note, an ALREADY-contacted contact (data-state axis —
 * a plain note leaves the existing value exactly), contacted-and-note, outbound message.
 */
final class NoteOnlyDoesNotMarkContactedTest extends TestCase
{
    use RefreshDatabase;

    // ── Note only: nothing moves ──────────────────────────────────────

    public function test_contact_screen_note_only_does_not_move_last_contacted(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Rang, no answer'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('contact_notes', ['contact_id' => $contact->id, 'body' => 'Rang, no answer']);
        $this->assertNull($contact->fresh()->last_contacted_at);
        $this->assertNull($contact->fresh()->contacted_marked_at);
    }

    public function test_core_matches_popup_note_only_does_not_move_last_contacted(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)->from('/corex/core-matches')
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Thinking about it', 'redirect_to' => 'back'])
            ->assertRedirect('/corex/core-matches');

        $this->assertDatabaseHas('contact_notes', ['contact_id' => $contact->id, 'body' => 'Thinking about it']);
        $this->assertNull($contact->fresh()->last_contacted_at);
    }

    public function test_buyer_pipeline_quick_pick_only_note_does_not_move_last_contacted(): void
    {
        // The pipeline form's quick pick "Contacted" with an empty body is still just a NOTE.
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->post(route('corex.contacts.notes.store', $contact), ['type' => 'Contacted', 'body' => '', 'redirect_to' => 'buyer-notes'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_notes', ['contact_id' => $contact->id, 'type' => 'Contacted']);
        $this->assertNull($contact->fresh()->last_contacted_at);
        $this->assertNull($contact->fresh()->contacted_marked_at);
    }

    public function test_mobile_api_note_does_not_move_last_contacted(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        Sanctum::actingAs($agent);

        $this->postJson(route('v1.mobile.contacts.notes.store', $contact), ['type' => 'Contacted', 'body' => 'Called the seller'])
            ->assertCreated();

        $this->assertNull($contact->fresh()->last_contacted_at);
        $this->assertNull($contact->fresh()->contacted_marked_at);
    }

    public function test_editing_a_note_does_not_move_last_contacted(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $note = $this->makeNote($agencyId, $contact->id, $agent->id, 'Original');

        $this->actingAs($agent)
            ->put(route('corex.contacts.notes.update', [$contact, $note]), ['body' => 'Edited'])
            ->assertSessionHasNoErrors();

        $this->assertNull($contact->fresh()->last_contacted_at);
    }

    public function test_a_system_generated_note_does_not_move_last_contacted(): void
    {
        // The model-level create the system writers use (dead-end flag, "Not selling", opt-out, import).
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->makeNote($agencyId, $contact->id, $agent->id, 'Marked as a dead-end — no contact details available.');
        ContactNote::create([
            'agency_id' => $agencyId, 'contact_id' => $contact->id, 'user_id' => $agent->id,
            'type' => 'Not Selling', 'body' => '12 Marine Drive, Margate was marked Not selling.',
        ]);

        $this->assertNull($contact->fresh()->last_contacted_at);
        $this->assertNull($contact->fresh()->contacted_marked_at);
    }

    public function test_a_plain_note_leaves_an_existing_contacted_date_exactly_as_it_was(): void
    {
        // Data-state axis: an already-contacted contact (explicit mark 3 days ago), then a plain note today.
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $threeDaysAgo = Carbon::now()->subDays(3)->startOfSecond();
        $contact->markContacted($threeDaysAgo);

        $this->actingAs($agent)
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Just a thought'])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertTrue($fresh->last_contacted_at->eq($threeDaysAgo), 'plain note must not advance an existing contacted date');
        $this->assertTrue($fresh->contacted_marked_at->eq($threeDaysAgo));
    }

    // ── Contacted and note: moves ─────────────────────────────────────

    public function test_contacted_and_note_moves_last_contacted_from_the_contact_screen(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Spoke to him', 'mark_contacted' => 1, 'redirect_to' => 'info'])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertNotNull($fresh->last_contacted_at);
        $this->assertNotNull($fresh->contacted_marked_at);
        $this->assertDatabaseHas('contact_notes', ['contact_id' => $contact->id, 'body' => 'Spoke to him']);
    }

    public function test_contacted_and_note_moves_last_contacted_from_the_core_matches_popup(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)->from('/corex/core-matches')
            ->post(route('corex.contacts.notes.store', $contact), ['body' => 'Wants a viewing', 'mark_contacted' => 1, 'redirect_to' => 'back'])
            ->assertRedirect('/corex/core-matches');

        $this->assertNotNull($contact->fresh()->last_contacted_at);
    }

    public function test_contacted_and_note_after_an_older_plain_note_moves_it_once(): void
    {
        // Sequence: plain note (nothing), then Contacted and note (moves) — and a LATER plain note does not move it again.
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);

        $this->actingAs($agent)->post(route('corex.contacts.notes.store', $contact), ['body' => 'first']);
        $this->assertNull($contact->fresh()->last_contacted_at);

        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $this->actingAs($agent)->post(route('corex.contacts.notes.store', $contact), ['body' => 'second', 'mark_contacted' => 1]);
        $moved = $contact->fresh()->last_contacted_at;
        $this->assertNotNull($moved);

        Carbon::setTestNow(Carbon::now()->addHours(5));
        $this->actingAs($agent)->post(route('corex.contacts.notes.store', $contact), ['body' => 'third']);
        $this->assertTrue($contact->fresh()->last_contacted_at->eq($moved), 'a later plain note must not move it again');
        Carbon::setTestNow();
    }

    // ── Outbound communication: still moves ───────────────────────────

    public function test_a_real_outbound_email_still_moves_last_contacted_and_a_later_note_does_not_change_it(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $contact->forceFill(['email' => 'sam.buyer@example.co.za'])->save();

        Carbon::setTestNow(Carbon::now()->startOfSecond());
        app(OutboundProvisionalLogger::class)->log($contact, Communication::CHANNEL_EMAIL, 'Properties for you', 'Hi Sam, three new listings.', $agent->id);
        $sentAt = $contact->fresh()->last_contacted_at;
        $this->assertNotNull($sentAt, 'a sent outbound email must still mark the contact contacted');

        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->actingAs($agent)->post(route('corex.contacts.notes.store', $contact), ['body' => 'Follow-up idea']);
        $this->assertTrue($contact->fresh()->last_contacted_at->eq($sentAt));
        Carbon::setTestNow();
    }

    // ── Fixtures (same shape as ContactNoteUpdateTest) ─────────────────

    private function seedFixture(): array
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
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        return [$agencyId, $agent];
    }

    private function makeContact(int $agencyId, int $createdBy): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $createdBy,
            'agent_id'  => $createdBy,
            'first_name' => 'Sam',
            'last_name' => 'Buyer',
            'phone' => '08255' . random_int(10000, 99999),
        ]);
    }

    private function makeNote(int $agencyId, int $contactId, int $userId, string $body): ContactNote
    {
        return ContactNote::create([
            'agency_id' => $agencyId, 'contact_id' => $contactId, 'user_id' => $userId, 'body' => $body,
        ]);
    }
}
