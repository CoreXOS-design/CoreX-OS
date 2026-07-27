<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact-details — outreach number/email selector: the agent picks WHICH of
 * the contact's numbers/emails a send goes to (defaulting to the Phase 3
 * primary/WhatsApp/email designations, changeable per send). Same
 * contact_phone_id/contact_email_id selector the Phase 4 could-not-send
 * reselect-and-resend picker already uses.
 */
final class ContactOutreachNumberSelectorTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        // AT-117 §4a — incrementChannel() gates WhatsApp sends behind the agency
        // send-window (default Mon-Fri 08:00-20:00). Freeze to a fixed weekday
        // daytime so this test is not flaky depending on when the suite runs.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-08 12:00:00', 'Africa/Johannesburg'));

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
            'agency_id' => $this->agencyId, 'first_name' => 'Selector', 'last_name' => 'Test',
            'phone' => '0821111111', 'email' => 'primary@example.com',
        ]);
        $this->contact->phones()->create(['agency_id' => $this->agencyId, 'phone' => '0821111111', 'is_primary' => true]);
        $this->contact->phones()->create(['agency_id' => $this->agencyId, 'phone' => '0829999999', 'is_primary' => false]);
        $this->contact->emails()->create(['agency_id' => $this->agencyId, 'email' => 'primary@example.com', 'is_primary' => true]);
        $this->contact->emails()->create(['agency_id' => $this->agencyId, 'email' => 'secondary@example.com', 'is_primary' => false]);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    /** No selection posted — falls back to the primary number, unchanged behaviour. */
    public function test_no_selection_falls_back_to_primary_number(): void
    {
        $this->post(route('corex.contacts.increment', $this->contact), [
            'channel' => Communication::CHANNEL_WHATSAPP,
        ])->assertSessionHasNoErrors();

        $comm = Communication::where('agency_id', $this->agencyId)->firstOrFail();
        $this->assertSame('0821111111', $comm->participant_identifiers[0] ?? null);
    }

    /** Agent picks the NON-default number — the archived row reflects the selection, not the primary. */
    public function test_selecting_a_non_default_number_logs_that_number(): void
    {
        $altPhone = $this->contact->phones()->where('phone', '0829999999')->first();

        $this->post(route('corex.contacts.increment', $this->contact), [
            'channel'          => Communication::CHANNEL_WHATSAPP,
            'contact_phone_id' => $altPhone->id,
        ])->assertSessionHasNoErrors();

        $comm = Communication::where('agency_id', $this->agencyId)->firstOrFail();
        $this->assertSame('0829999999', $comm->participant_identifiers[0] ?? null);
    }

    /** Same selector applies to email. */
    public function test_selecting_a_non_default_email_logs_that_email(): void
    {
        $altEmail = $this->contact->emails()->where('email', 'secondary@example.com')->first();

        $this->post(route('corex.contacts.increment', $this->contact), [
            'channel'          => Communication::CHANNEL_EMAIL,
            'contact_email_id' => $altEmail->id,
        ])->assertSessionHasNoErrors();

        $comm = Communication::where('agency_id', $this->agencyId)->firstOrFail();
        $this->assertSame('secondary@example.com', $comm->participant_identifiers[0] ?? null);
    }

    /** A phone/email id belonging to ANOTHER contact is never trusted (contact-scoped lookup). */
    public function test_a_phone_id_from_another_contact_is_not_used(): void
    {
        $otherContact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'Other', 'last_name' => 'Contact']);
        $foreignPhone = $otherContact->phones()->create(['agency_id' => $this->agencyId, 'phone' => '0827777777', 'is_primary' => true]);

        $this->post(route('corex.contacts.increment', $this->contact), [
            'channel'          => Communication::CHANNEL_WHATSAPP,
            'contact_phone_id' => $foreignPhone->id,
        ])->assertSessionHasNoErrors();

        $comm = Communication::where('agency_id', $this->agencyId)->firstOrFail();
        $this->assertSame('0821111111', $comm->participant_identifiers[0] ?? null, 'foreign phone id silently ignored, falls back to primary');
    }
}
