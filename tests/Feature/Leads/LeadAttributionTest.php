<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Notifications\NewPortalLeadAgentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-226 — lead notifications must attribute OWNERSHIP.
 *
 * The same NewPortalLeadAgentNotification is sent to the listing agent(s) AND to a
 * matched buyer's existing agent. Only the listing side owns the enquiry. These
 * assert that the copy speaks per-recipient: listing-side gets the AGENT copy
 * ("your listing", act-now); anyone else gets the OVERSIGHT copy (named listing
 * agent, no "reach out while hot", is_oversight flag).
 */
final class LeadAttributionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $listingAgent;
    private User $coListingAgent;
    private User $buyerAgent;
    private PortalLead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Leads Co ' . Str::random(5), 'slug' => 'leads-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mk = fn (string $name) => tap(User::forceCreate([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
            'name' => $name, 'email' => Str::random(10) . '@ex.com', 'password' => bcrypt('x'),
        ]));

        $this->listingAgent   = $mk('Rochelle Combrink');
        $this->coListingAgent = $mk('Barbara Naidoo');
        $this->buyerAgent     = $mk('Elize Reichel');

        $property = Property::withoutEvents(fn () => Property::forceCreate([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'external_id' => 'TEST-' . Str::random(6),
            'title' => '3 Bed Penthouse, Manaba', 'agent_id' => $this->listingAgent->id,
            'pp_second_agent_id' => $this->coListingAgent->id,
        ]));

        $contact = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Belinda', 'last_name' => 'Erasmus', 'created_by_user_id' => $this->buyerAgent->id,
        ]));

        $this->lead = PortalLead::withoutEvents(fn () => PortalLead::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'portal' => PortalLead::PORTAL_P24, 'lead_type' => 'enquiry',
            'listing_id' => $property->id,
            'listing_portal_ref' => 'P24-12345', 'contact_id' => $contact->id,
            'existing_contact_agent_id' => $this->buyerAgent->id,
            'name' => 'Belinda Erasmus', 'lead_source_raw' => [], 'received_at' => now(),
        ]));
    }

    private function notif(): NewPortalLeadAgentNotification
    {
        return new NewPortalLeadAgentNotification($this->lead);
    }

    public function test_listing_agent_gets_the_agent_copy_owning_the_lead(): void
    {
        $mail  = $this->notif()->toMail($this->listingAgent);
        $array = $this->notif()->toArray($this->listingAgent);

        $this->assertStringContainsString('on your listing', $mail->subject);
        $intro = implode(' ', $mail->introLines);
        $this->assertStringContainsString('your listing', $intro);
        $this->assertStringContainsString('Reach out while the enquiry is hot', implode(' ', $mail->outroLines));

        $this->assertFalse($array['is_oversight']);
        $this->assertNull($array['listing_agent_name']);
        $this->assertStringNotContainsStringIgnoringCase('oversight', (string) $array['title']);
    }

    public function test_matched_buyer_agent_gets_the_oversight_copy_never_owning_the_lead(): void
    {
        $mail  = $this->notif()->toMail($this->buyerAgent);
        $array = $this->notif()->toArray($this->buyerAgent);

        // Named for the real listing agent, framed as oversight...
        $this->assertStringContainsString('for Rochelle Combrink', $mail->subject);
        $body = implode(' ', $mail->introLines) . ' ' . implode(' ', $mail->outroLines);
        $this->assertStringContainsString("Rochelle Combrink's listing", $body);
        $this->assertStringContainsString('oversight', $body);
        // ...and NEVER told to work the client.
        $this->assertStringNotContainsString('Reach out while the enquiry is hot', $body);
        $this->assertStringNotContainsString('your listing', $body);
        $this->assertSame('View lead (oversight)', $mail->actionText);

        $this->assertTrue($array['is_oversight']);
        $this->assertSame('Rochelle Combrink', $array['listing_agent_name']);
        $this->assertStringContainsString('Rochelle Combrink', (string) $array['title']);
    }

    public function test_co_listing_agent_is_listing_side_and_gets_the_agent_copy(): void
    {
        // The pp_second_agent_id is a genuine co-owner of the listing — not oversight.
        $array = $this->notif()->toArray($this->coListingAgent);
        $mail  = $this->notif()->toMail($this->coListingAgent);

        $this->assertFalse($array['is_oversight']);
        $this->assertStringContainsString('your listing', implode(' ', $mail->introLines));
    }
}
