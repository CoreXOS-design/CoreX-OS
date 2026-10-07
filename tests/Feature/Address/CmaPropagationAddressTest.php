<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentation\PropertyCmaPropagationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Structured address matching, step 6 — PropertyCmaPropagationService finds the property a CMA presentation is about
 * by its subject address / erf. Pass 1 compared lower-cased text (a street type or an old row's number-in-the-name
 * defeated it); Pass 2 matched any two shared words with no number veto; the erf lookup matched the suburb as a
 * substring ("Uvongo" also hit "Uvongo Beach"). Now the shared scorer decides.
 */
final class CmaPropagationAddressTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function property(array $over): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000, 'title' => 'T', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    /** A presentation whose extracted subject fields are the given ones; returns the propagation result. */
    private function propagate(array $fields): array
    {
        $pid = (int) DB::table('presentations')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->user->id, 'title' => 'CMA',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($fields as $key => $value) {
            DB::table('presentation_fields')->insert([
                'presentation_id' => $pid, 'agency_id' => $this->agency->id, 'field_key' => $key, 'extracted_value' => $value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return app(PropertyCmaPropagationService::class)->propagateFromPresentation($pid, true);
    }

    public function test_the_subject_address_finds_stock_whose_street_type_is_missing(): void
    {
        $p = $this->property(['street_number' => '19', 'street_name' => 'Grindewald']);
        $r = $this->propagate(['subject.address' => '19 Grindewald Drive', 'subject.suburb' => 'Uvongo', 'subject.erf' => '1329']);

        $this->assertSame('updated', $r['status']);
        $this->assertSame($p->id, $r['property_id']);
    }

    public function test_the_number_written_inside_the_street_name_of_an_old_row_still_matches(): void
    {
        $p = $this->property(['street_number' => null, 'street_name' => '19 Grindewald Drive']);
        $r = $this->propagate(['subject.address' => '19 Grindewald Drive', 'subject.suburb' => 'Uvongo', 'subject.erf' => '1329']);

        $this->assertSame($p->id, $r['property_id'] ?? null);
    }

    public function test_a_different_street_number_is_never_the_same_property_even_with_two_shared_words(): void
    {
        $this->property(['street_number' => '29', 'street_name' => 'Grindewald Drive']);
        $r = $this->propagate(['subject.address' => '19 Grindewald Drive', 'subject.suburb' => 'Uvongo']);

        $this->assertSame('no_linked_property', $r['status']);
    }

    public function test_the_erf_lookup_needs_the_same_suburb_not_a_substring_of_it(): void
    {
        $beach = $this->property(['street_number' => null, 'street_name' => null, 'suburb' => 'Uvongo Beach', 'erf_number' => '1329']);
        $r = $this->propagate(['subject.erf' => '1329', 'subject.suburb' => 'Uvongo']);
        $this->assertSame('no_linked_property', $r['status'], '"Uvongo" is not "Uvongo Beach"');

        $same = $this->property(['street_number' => null, 'street_name' => null, 'suburb' => 'Uvongo', 'erf_number' => '1329']);
        $r2 = $this->propagate(['subject.erf' => '1329', 'subject.suburb' => 'Uvongo']);
        $this->assertSame($same->id, $r2['property_id'] ?? null);
        $this->assertNotNull($beach->id);
    }

    public function test_another_agencys_property_is_never_found(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        Property::create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'agent_id' => User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id])->id,
            'street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo', 'property_type' => 'house', 'beds' => 1, 'baths' => 1, 'garages' => 0, 'price' => 1, 'title' => 'x', 'status' => 'active', 'listing_type' => 'sale']);

        $r = $this->propagate(['subject.address' => '19 Grindewald Drive', 'subject.suburb' => 'Uvongo', 'subject.erf' => '1329']);
        $this->assertSame('no_linked_property', $r['status']);
    }
}
