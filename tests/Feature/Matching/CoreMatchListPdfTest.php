<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\CoreMatchListPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Print / Download PDF for the Core-Match / Buyer-Pipeline wishlist property
 * list. Agents print the resolved list and work on paper for appointment
 * rounds. The sheet is INTERNAL (seller PII + addresses).
 *
 * Covers the full wire: route → controller guard → CoreMatchListPdfService →
 * dompdf blade, plus the service's per-row shaping (address, price, specs,
 * agent, seller contact) and the defensive ACCESS-column resolution (cc3's
 * field may land before or after this feature).
 */
final class CoreMatchListPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_agent_can_open_pdf_of_the_wishlist_list(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->matchingProperty($agencyId, $agent->id, $suburbId);
        $this->linkSeller($property, $agencyId, 'Sizwe', 'Ndlovu', '0821112222');
        $match = $this->wishlist($agencyId, $suburbId);

        $res = $this->actingAs($agent)->get(
            route('corex.contacts.matches.print', [$match->contact_id, $match->id])
        );

        $res->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower($res->headers->get('content-type')));
        // dompdf's stream() returns a plain Response with the PDF in the body.
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_download_variant_sends_an_attachment(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $this->matchingProperty($agencyId, $agent->id, $suburbId);
        $match = $this->wishlist($agencyId, $suburbId);

        $res = $this->actingAs($agent)->get(
            route('corex.contacts.matches.print', [$match->contact_id, $match->id]) . '?dl=1'
        );

        $res->assertOk();
        $this->assertStringContainsString('attachment', strtolower($res->headers->get('content-disposition')));
    }

    public function test_service_row_carries_address_price_specs_agent_and_seller(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $property = $this->matchingProperty($agencyId, $agent->id, $suburbId, [
            'suburb' => 'Uvongo', 'address' => '12 Marine Drive', 'price' => 1_950_000,
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'size_m2' => 180,
        ]);
        $this->linkSeller($property, $agencyId, 'Sizwe', 'Ndlovu', '0821112222');
        $match = $this->wishlist($agencyId, $suburbId);

        $contact = Contact::withoutGlobalScopes()->find($match->contact_id);
        $data = app(CoreMatchListPdfService::class)->data($contact, $match, includeHidden: false);

        $this->assertNotEmpty($data['rows'], 'the matching property must appear in the list');
        $row = collect($data['rows'])->firstWhere('seller_name', 'Sizwe Ndlovu');
        $this->assertNotNull($row, 'the seller-linked property row must be present');
        $this->assertStringContainsString('Marine Drive', $row['address']);
        $this->assertStringContainsString('R ', $row['price']);
        $this->assertSame(3, $row['beds']);
        $this->assertSame($agent->name, $row['agent_name']);
        $this->assertSame('0821112222', $row['seller_phone']);
    }

    public function test_access_column_is_resolved_defensively_when_absent(): void
    {
        // The test schema has no dedicated access column yet (cc3 adds it). The
        // service must simply report access_shown=false and still render.
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $this->matchingProperty($agencyId, $agent->id, $suburbId);
        $match = $this->wishlist($agencyId, $suburbId);

        $contact = Contact::withoutGlobalScopes()->find($match->contact_id);
        $data = app(CoreMatchListPdfService::class)->data($contact, $match, includeHidden: false);

        $this->assertFalse($data['access_shown']);
    }

    public function test_a_match_that_does_not_belong_to_the_contact_is_rejected(): void
    {
        [$agencyId, $agent, $suburbId] = $this->fixture();
        $match = $this->wishlist($agencyId, $suburbId);

        // A different in-agency contact — the match is not theirs.
        $other = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'is_buyer' => true,
            'first_name' => 'Not', 'last_name' => 'Owner',
            'email' => 'other-' . Str::random(4) . '@example.co.za',
        ]);

        $this->actingAs($agent)
            ->get(route('corex.contacts.matches.print', [$other->id, $match->id]))
            ->assertForbidden();
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function wishlist(int $agencyId, int $suburbId): ContactMatch
    {
        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Peter', 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'peter-' . Str::random(5) . '@example.co.za',
        ]);

        return ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_min' => 1_000_000, 'price_max' => 2_700_000,
            'beds_min' => 3, 'p24_suburb_ids' => [$suburbId],
        ]);
    }

    private function linkSeller(Property $property, int $agencyId, string $first, string $last, string $phone): Contact
    {
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
            'email' => strtolower($first) . '-' . Str::random(4) . '@example.co.za',
        ]);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);
        return $seller;
    }

    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);
        $suburbId = $this->seedP24Suburb();
        return [$agencyId, $agent, $suburbId];
    }

    private function matchingProperty(int $agencyId, int $agentId, int $suburbId, array $extra = []): Property
    {
        return Property::create(array_merge([
            'external_id'   => (string) Str::uuid(),
            'title'         => 'Test Property ' . Str::random(5),
            'agent_id'      => $agentId,
            'branch_id'     => $agencyId,
            'agency_id'     => $agencyId,
            'listing_type'  => 'sale',
            'status'        => 'active',
            'published_at'  => now(),
            'suburb'        => 'Uvongo',
            'p24_suburb_id' => $suburbId,
            'price'         => 1_800_000,
            'beds'          => 3,
            'baths'         => 2,
        ], $extra));
    }

    private function seedP24Suburb(): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 999999), 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_city_id' => $cityId, 'name' => 'Uvongo',
            'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
