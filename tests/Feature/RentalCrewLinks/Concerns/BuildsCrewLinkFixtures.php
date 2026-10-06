<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks\Concerns;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Shared fixtures for the crew-link tests (.ai/specs/rental-work-orders.md
 * §14.27 / §14.28): an agency with one branch, an admin, a rental property, a
 * crew and a SCHEDULED job card with two tasks, a part line and a labour line.
 * Every email address is @example.invalid — nothing here can reach a real inbox.
 */
trait BuildsCrewLinkFixtures
{
    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private RentalCrew $crew;

    protected function buildCrewLinkWorld(string $label = 'Crew Link'): void
    {
        Auth::logout();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = Agency::create(['name' => "{$label} Agency", 'slug' => strtolower(str_replace(' ', '-', $label)) . '-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
            'email' => 'admin-' . uniqid() . '@example.invalid',
        ]);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '12 Crew Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->crew = RentalCrew::create([
            'agency_id' => $this->agency->id, 'name' => 'Team 1', 'email' => 'team1@example.invalid',
            'phone' => '082 123 4567', 'created_by_user_id' => $this->admin->id,
        ]);
    }

    /** A scheduled job card assigned to $crew (or $this->crew), with two tasks, one part line and one labour line. */
    protected function makeJobCard(array $overrides = [], ?RentalCrew $crew = null): RentalJobCard
    {
        $card = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id,
            'title' => 'Fix the geyser',
            'access_notes' => 'Key is under the pot plant',
            'tasks' => [
                ['description' => 'Drain the geyser', 'lines' => [
                    ['type' => 'part', 'description' => 'Geyser element', 'unit' => 'each', 'quantity' => 2, 'unit_price' => 450],
                ]],
                ['description' => 'Refill and test', 'lines' => [
                    ['type' => 'labour', 'description' => 'Plumber hour', 'unit' => 'hour', 'quantity' => 3, 'unit_price' => 300],
                ]],
            ],
        ], $this->admin);

        $card->forceFill(array_merge([
            'rental_crew_id' => ($crew ?? $this->crew)->id,
            'status' => RentalJobCard::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ], $overrides))->save();

        return $card->fresh();
    }

    /** A lease on the card's property with one named tenant, attached to the card. */
    protected function attachTenant(RentalJobCard $card, string $first = 'Tina', string $last = 'Tenant', string $phone = '0831112222'): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
            'email' => strtolower($first) . '-' . uniqid() . '@example.invalid',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10),
            'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
        $card->forceFill(['lease_id' => $lease->id])->save();
    }
}
