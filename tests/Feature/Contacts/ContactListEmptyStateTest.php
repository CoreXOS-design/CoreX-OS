<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Go-live walk, 14 Oct 2026: a contacts search that matched nothing said "No contacts yet - Add your first contact" to an
 * agent who had contacts. Three honest states: a book with contacts but no match ("nothing matches", with a way back),
 * a genuinely empty book ("No contacts yet"), and the normal list.
 */
final class ContactListEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);
    }

    private function contact(string $first, string $last): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => $first, 'last_name' => $last, 'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }

    public function test_a_search_that_matches_nothing_says_nothing_matches_and_offers_the_way_back(): void
    {
        $this->contact('Anna', 'Smit');

        $html = $this->actingAs($this->agent)->get(route('corex.contacts.index', ['search' => 'zzqqxx-nothing']))->assertOk()->getContent();

        $this->assertStringContainsString('No contacts match this search or filter', $html);
        $this->assertStringContainsString('data-qa="contacts-clear-filters"', $html);
        $this->assertStringNotContainsString('data-qa="contacts-none-yet"', $html, 'the agent has contacts - it is not an empty book');
        $this->assertStringNotContainsString('Add your first contact', $html);
    }

    public function test_a_type_filter_that_matches_nothing_is_also_a_no_match_not_an_empty_book(): void
    {
        $this->contact('Anna', 'Smit');
        $typeId = (int) DB::table('contact_types')->insertGetId(['name' => 'Witness ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->agent)->get(route('corex.contacts.index', ['type' => $typeId]))->assertOk()->getContent();

        $this->assertStringContainsString('No contacts match this search or filter', $html);
    }

    public function test_a_genuinely_empty_book_still_says_no_contacts_yet(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.contacts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="contacts-none-yet"', $html);
        $this->assertStringContainsString('Add your first contact', $html);
        $this->assertStringNotContainsString('data-qa="contacts-no-match"', $html);
        $this->assertStringNotContainsString('data-qa="contacts-clear-filters"', $html);
    }

    public function test_a_normal_list_shows_neither_message(): void
    {
        $this->contact('Anna', 'Smit');

        $html = $this->actingAs($this->agent)->get(route('corex.contacts.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-qa="contacts-no-match"', $html);
        $this->assertStringNotContainsString('data-qa="contacts-none-yet"', $html);
        $this->assertStringContainsString('Anna', $html);
    }
}
