<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\MarketingSuppression;
use App\Models\User;
use App\Services\Contacts\ContactIdentifierService;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-125 step 4 — suppression reads/writes ALL of a contact's identifiers while
 * keeping opt-out CONTACT-LEVEL. The headline invariant: no identifier is ever a
 * back-door to reach an opted-out person.
 */
final class ContactSuppressionMultiIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

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
        $this->actingAs(User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]));
    }

    private function multiContact(): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Opt', 'last_name' => 'Out',
            'phone' => '', 'email' => null,
        ]);
        app(ContactIdentifierService::class)->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true],
            ['value' => '0822222222', 'is_primary' => false],
        ], [
            ['value' => 'primary@example.com', 'is_primary' => true],
            ['value' => 'secondary@example.com', 'is_primary' => false],
        ]);

        return $contact->refresh();
    }

    private function activeSuppressions(): \Illuminate\Support\Collection
    {
        return MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)->whereNull('lifted_at')
            ->pluck('identifier');
    }

    public function test_opt_out_suppresses_every_identifier_and_blocks_marketing(): void
    {
        $contact = $this->multiContact();
        $consent = app(MarketingConsentService::class);

        $consent->optOutContact($contact, 'tapped stop', null, null);
        $contact->refresh();

        // All 4 identifiers (2 phones normalised + 2 emails) suppressed.
        $active = $this->activeSuppressions();
        $this->assertTrue($active->contains('821111111'));
        $this->assertTrue($active->contains('822222222'));
        $this->assertTrue($active->contains('primary@example.com'));
        $this->assertTrue($active->contains('secondary@example.com'));
        $this->assertNotNull($contact->messaging_opt_out_at, 'contact-level flag set');

        $this->assertTrue($consent->isContactSuppressed($contact));
        $this->assertFalse($consent->canMarketTo($contact, 'whatsapp'), 'blocked on whatsapp');
        $this->assertFalse($consent->canMarketTo($contact, 'email'), 'blocked on email');
    }

    public function test_adding_an_identifier_to_an_opted_out_contact_is_no_backdoor(): void
    {
        $contact = $this->multiContact();
        $consent = app(MarketingConsentService::class);
        $consent->optOutContact($contact, 'tapped stop', null, null);
        $contact->refresh();

        // Add a THIRD email to the already-opted-out contact.
        app(ContactIdentifierService::class)->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true],
            ['value' => '0822222222', 'is_primary' => false],
        ], [
            ['value' => 'primary@example.com', 'is_primary' => true],
            ['value' => 'secondary@example.com', 'is_primary' => false],
            ['value' => 'third@example.com', 'is_primary' => false],
        ]);
        $contact->refresh();

        // Still blocked, AND the new identifier is itself suppressed (no gap).
        $this->assertFalse($consent->canMarketTo($contact, 'email'), 'new email does NOT open a path');
        $this->assertTrue($consent->isContactSuppressed($contact));
        $this->assertTrue($this->activeSuppressions()->contains('third@example.com'), 'the new email is suppressed too');
    }

    public function test_stop_on_a_secondary_identifier_opts_out_the_whole_contact(): void
    {
        $contact = $this->multiContact();
        $consent = app(MarketingConsentService::class);

        // STOP arrives on the SECONDARY email — contact-level cascade.
        $matched = $consent->optOutByIdentifier('secondary@example.com', $this->agencyId, 'STOP reply', 'self_service_link', null);

        $this->assertTrue($matched, 'resolved to the contact via its secondary identifier');
        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at, 'whole contact opted out (contact-level)');
        $active = $this->activeSuppressions();
        // Every identifier suppressed, not just the one the STOP came in on.
        $this->assertTrue($active->contains('821111111'));
        $this->assertTrue($active->contains('822222222'));
        $this->assertTrue($active->contains('primary@example.com'));
        $this->assertTrue($active->contains('secondary@example.com'));
    }

    public function test_a_non_opted_out_contact_stays_marketable(): void
    {
        $contact = $this->multiContact();
        $consent = app(MarketingConsentService::class);

        $this->assertFalse($consent->isContactSuppressed($contact), 'no false suppression');
        $this->assertTrue($consent->canMarketTo($contact, 'whatsapp'));
        $this->assertTrue($consent->canMarketTo($contact, 'email'));
        $this->assertSame(0, $this->activeSuppressions()->count());
    }
}
