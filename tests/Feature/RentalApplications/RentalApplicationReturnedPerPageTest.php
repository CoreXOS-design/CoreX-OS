<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design-standard audit, 2026-09-09 — Johan: "per-page control, matching
 * the index screen's 10-100 range sitting right next to it." The Returned
 * screen paginated at a fixed 25 with no way to change it, unlike index()
 * right beside it. Pins RentalApplicationController::returned()'s new
 * $perPage clamp — identical shape to index()'s own.
 */
final class RentalApplicationReturnedPerPageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function returnedApplication(int $n): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Marker', 'last_name' => (string) $n, 'email' => "marker{$n}-" . uniqid() . '@example.co.za',
        ]);

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->owner()->id, 'status' => 'returned', 'submitted_at' => now(),
            'property_address_override' => "Marker Row {$n}",
        ]);
    }

    public function test_default_per_page_is_twenty_five(): void
    {
        $owner = $this->owner();
        foreach (range(1, 30) as $n) {
            RentalApplication::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                'contact_id' => Contact::create([
                    'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                    'first_name' => 'Row', 'last_name' => (string) $n, 'email' => "row{$n}-" . uniqid() . '@example.co.za',
                ])->id,
                'created_by_user_id' => $owner->id, 'status' => 'returned', 'submitted_at' => now(),
                'property_address_override' => "Default Row {$n}",
            ]);
        }

        $response = $this->actingAs($owner)->get(route('corex.rental-applications.returned'));
        $response->assertOk();
        $this->assertSame(25, substr_count($response->getContent(), 'Default Row'));
    }

    public function test_per_page_can_be_set_to_ten_or_up_to_one_hundred(): void
    {
        $owner = $this->owner();
        foreach (range(1, 15) as $n) {
            RentalApplication::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                'contact_id' => Contact::create([
                    'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                    'first_name' => 'Row', 'last_name' => (string) $n, 'email' => "pprow{$n}-" . uniqid() . '@example.co.za',
                ])->id,
                'created_by_user_id' => $owner->id, 'status' => 'returned', 'submitted_at' => now(),
                'property_address_override' => "PP Row {$n}",
            ]);
        }

        $ten = $this->actingAs($owner)->get(route('corex.rental-applications.returned', ['per_page' => 10]));
        $ten->assertOk();
        $this->assertSame(10, substr_count($ten->getContent(), 'PP Row'));

        $all = $this->actingAs($owner)->get(route('corex.rental-applications.returned', ['per_page' => 100]));
        $all->assertOk();
        $this->assertSame(15, substr_count($all->getContent(), 'PP Row'));
    }

    public function test_per_page_is_clamped_to_the_ten_to_one_hundred_range(): void
    {
        $owner = $this->owner();
        $this->returnedApplication(1);

        // Below the floor clamps to 10, not an error and not "0 per page".
        $this->actingAs($owner)->get(route('corex.rental-applications.returned', ['per_page' => 1]))->assertOk();
        // Above the ceiling clamps to 100, not an unbounded query.
        $this->actingAs($owner)->get(route('corex.rental-applications.returned', ['per_page' => 99999]))->assertOk();
    }
}
