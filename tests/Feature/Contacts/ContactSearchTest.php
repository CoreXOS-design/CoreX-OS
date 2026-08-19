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
 * Regression for a real production incident (2026-08-13): Contact::scopeSearch()
 * extracted digits from ANY search token and loosely matched them against
 * phone_normalised, so searching an email like "a.roets12@gmail.com" (digits
 * "12") returned 519 unrelated contacts whose phone number merely CONTAINED
 * "12" somewhere — burying the real match dozens of pages deep and making it
 * look, from the Contacts page, like the contact had been deleted.
 *
 * Fixed by only treating a token as a phone-number fragment when the token
 * itself (after stripping common phone punctuation) is entirely digits.
 */
final class ContactSearchTest extends TestCase
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

    public function test_search_by_email_containing_digits_does_not_match_unrelated_phone_numbers(): void
    {
        $target = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Andre', 'last_name' => 'Roets',
            'phone' => '0813230105', 'email' => 'a.roets12@gmail.com',
        ]);

        // Noise: real SA cell numbers that coincidentally contain "12"
        // somewhere in their digit string (the exact false-positive shape
        // from production). None of these should ever surface for this search.
        foreach (['0821234567', '0712398765', '0839912045', '0665512890'] as $i => $phone) {
            Contact::create([
                'agency_id' => $this->agencyId,
                'first_name' => 'Noise', 'last_name' => 'Contact' . $i,
                'phone' => $phone, 'email' => "noise{$i}@example.com",
            ]);
        }

        $ids = Contact::search('a.roets12@gmail.com')->pluck('id');

        $this->assertSame([$target->id], $ids->all());
    }

    public function test_search_by_full_phone_number_still_matches(): void
    {
        $target = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Sarah', 'last_name' => 'Naidoo',
            'phone' => '0813230105', 'email' => 'sarah@example.com',
        ]);

        $ids = Contact::search('0813230105')->pluck('id');

        $this->assertTrue($ids->contains($target->id));
    }

    public function test_search_by_partial_phone_fragment_still_matches(): void
    {
        $target = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Sarah', 'last_name' => 'Naidoo',
            'phone' => '0813230105', 'email' => 'sarah2@example.com',
        ]);

        $ids = Contact::search('323010')->pluck('id');

        $this->assertTrue($ids->contains($target->id));
    }

    public function test_search_by_name_still_matches(): void
    {
        $target = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Thabo', 'last_name' => 'Mokoena',
            'phone' => '0827654321', 'email' => 'thabo@example.com',
        ]);

        $ids = Contact::search('Thabo Mokoena')->pluck('id');

        $this->assertTrue($ids->contains($target->id));
    }

    public function test_search_by_id_number_still_matches(): void
    {
        $target = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Priya', 'last_name' => 'Govender',
            'phone' => '0846543210', 'email' => 'priya@example.com',
            'id_number' => '8501015800081',
        ]);

        $ids = Contact::search('8501015800081')->pluck('id');

        $this->assertTrue($ids->contains($target->id));
    }

    public function test_search_by_unit_number_style_token_does_not_explode(): void
    {
        // "12B" style tokens (unit numbers, addresses) are exactly the other
        // real-world shape of "alphanumeric token with incidental digits"
        // that must never fall into the phone-fragment matcher.
        Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Random', 'last_name' => 'Person',
            'phone' => '0821234567', 'email' => 'random@example.com',
        ]);

        $ids = Contact::search('Unit12B')->pluck('id');

        $this->assertSame([], $ids->all());
    }
}
