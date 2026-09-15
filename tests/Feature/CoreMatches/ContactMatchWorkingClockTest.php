<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchShare;
use App\Models\ContactNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-Core-Matches, Johan's ruling 2 — a note added, the Last Contacted
 * button, a message sent, and a live link shared ALL reset the working
 * clock (Contact::last_contacted_at). Proves the two NEW triggers this
 * build adds; "message sent" already worked before this build (not
 * re-proven here — that's CommunicationSendStatusService's own test).
 */
final class ContactMatchWorkingClockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Clock Test Agency', 'slug' => 'clock-test-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'created_by_user_id' => $this->agent->id,
            'first_name' => 'Clock', 'last_name' => 'Test',
        ]);
    }

    public function test_sharing_a_live_link_resets_the_clock(): void
    {
        $this->assertNull($this->contact->last_contacted_at);

        $match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'name' => 'Test', 'listing_type' => 'sale',
        ]);

        $match->mintShareLink($this->agent->id)->confirmSent(ContactMatchShare::CHANNEL_WHATSAPP);

        $this->contact->refresh();
        $this->assertNotNull($this->contact->last_contacted_at);
        $this->assertSame(1, ContactMatchShare::count());
    }

    public function test_adding_a_note_resets_the_clock(): void
    {
        $this->assertNull($this->contact->last_contacted_at);

        ContactNote::create([
            'agency_id' => $this->agency->id,
            'contact_id' => $this->contact->id,
            'user_id' => $this->agent->id,
            'type' => 'Contacted',
            'body' => 'Called, left a voicemail.',
        ]);

        $this->contact->refresh();
        $this->assertNotNull($this->contact->last_contacted_at);
    }

    public function test_a_nightly_stock_refresh_style_match_save_does_not_reset_the_clock(): void
    {
        // Rule 2's exclusion, in the negative: opening the record, a match
        // recalculation, or a nightly stock refresh are not a PERSON doing
        // anything, so none of them may touch last_contacted_at. Simulated
        // here as an ordinary ContactMatch save unrelated to any of the
        // real triggers (share/note/button/message).
        $match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'name' => 'Test', 'listing_type' => 'sale',
        ]);
        $match->price_max = 2000000;
        $match->save();

        $this->contact->refresh();
        $this->assertNull($this->contact->last_contacted_at, 'a plain match save must never be treated as buyer contact');
    }
}
