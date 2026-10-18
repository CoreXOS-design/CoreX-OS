<?php

namespace Tests\Concerns;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\Scopes\AgencyScope;
use App\Models\User;

/**
 * Fixtures for the crew-page / client-visibility tests (rental-work-orders.md
 * §14.29). Real-looking SA data, one helper per record kind, nothing hidden
 * in setUp so every test states exactly what exists.
 */
trait BuildsRentalPortalFixtures
{
    protected function makeAgency(string $name = 'Coastal Lettings'): Agency
    {
        $agency = Agency::create(['name' => $name . ' ' . uniqid(), 'slug' => 'cp-' . uniqid()]);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Main', 'code' => 'M-' . $agency->id, 'is_active' => true]);

        return $agency;
    }

    protected function makeAgent(Agency $agency, string $role = 'admin'): User
    {
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'role' => $role,
        ]);
    }

    protected function makeProperty(Agency $agency, User $agent, string $title = '12 Marine Drive, Margate'): Property
    {
        return Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id,
            'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    protected function makeLease(Agency $agency, Property $property): Lease
    {
        return Lease::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 9500, 'deposit_amount' => 9500,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
    }

    protected function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');

        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branchId,
            'first_name' => 'Thandi', 'last_name' => 'Nkosi', 'email' => 'contact+' . uniqid() . '@example.invalid',
        ], $overrides));
    }

    /** A tenant contact on the lease. */
    protected function makeTenant(Agency $agency, Lease $lease, array $overrides = []): Contact
    {
        $tenant = $this->makeContact($agency, $overrides);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);

        return $tenant;
    }

    /** A landlord contact on the property. */
    protected function makeLandlord(Agency $agency, Property $property, array $overrides = []): Contact
    {
        $landlord = $this->makeContact($agency, array_merge(['first_name' => 'Pieter', 'last_name' => 'van der Merwe'], $overrides));
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);

        return $landlord;
    }

    protected function clientUserFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }

    protected function makeJobCard(Agency $agency, Property $property, ?Lease $lease, array $overrides = []): RentalJobCard
    {
        return RentalJobCard::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'lease_id' => $lease?->id,
            'title' => 'Replace geyser element',
            'status' => RentalJobCard::STATUS_SCHEDULED,
            'created_by_user_id' => User::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->value('id'),
        ], $overrides));
    }

    protected function makeWorkOrder(Agency $agency, Property $property, ?Lease $lease = null, array $overrides = []): RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'lease_id' => $lease?->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_INTERNAL,
            'title' => 'Geyser not heating', 'description' => 'No hot water since Monday',
            'status' => RentalWorkOrder::STATUS_ORDERED,
            'priority' => RentalWorkOrder::PRIORITY_NORMAL, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_at' => now(),
        ], $overrides));
    }

    /** A photo against a job card. $uploader null = a crew member with no CoreX login. */
    protected function makePhoto(RentalJobCard $card, string $type, ?User $uploader = null, array $overrides = []): RentalWorkOrderPhoto
    {
        return RentalWorkOrderPhoto::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $card->agency_id,
            'rental_work_order_id' => $card->rental_work_order_id,
            'rental_job_card_id' => $card->id,
            'photo_type' => $type,
            'storage_path' => '/storage/properties/' . $card->property_id . '/' . $type . '-' . uniqid() . '.jpg',
            'uploaded_by_user_id' => $uploader?->id,
            'file_size_bytes' => 245_000,
        ], $overrides));
    }

    protected function setCrewPhotoVisibility(Agency $agency, string $value): void
    {
        RentalPortalSetting::withoutGlobalScopes()->updateOrCreate(
            ['agency_id' => $agency->id],
            ['crew_photos_visible_to_clients' => $value],
        );
    }

    protected function makeCrew(Agency $agency, string $name = 'Team 1', array $overrides = []): RentalCrew
    {
        return RentalCrew::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agency->id, 'name' => $name, 'is_active' => true,
            'email' => 'crew+' . uniqid() . '@example.invalid', 'phone' => '082 123 4567',
        ], $overrides));
    }

    protected function makeTask(RentalJobCard $card, string $description = 'Drain the geyser', array $overrides = []): RentalJobCardTask
    {
        return RentalJobCardTask::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $card->agency_id, 'rental_job_card_id' => $card->id, 'description' => $description, 'sort_order' => 1,
        ], $overrides));
    }

    /** A catalogue PART (or labour) item for the agency, with its type + unit seeded. */
    protected function makeCatalogueItem(Agency $agency, string $code, string $description, string $kind = 'part', string $unitName = 'each'): RentalCatalogueItem
    {
        RentalCatalogueItemType::seedDefaultsFor($agency->id);
        RentalCatalogueUnit::seedDefaultsFor($agency->id);
        $type = RentalCatalogueItemType::withoutGlobalScopes()->where('agency_id', $agency->id)->where('kind', $kind)->orderBy('id')->firstOrFail();
        $unit = RentalCatalogueUnit::withoutGlobalScopes()->where('agency_id', $agency->id)->orderBy('id')->firstOrFail();

        return RentalCatalogueItem::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'rental_catalogue_item_type_id' => $type->id, 'code' => $code,
            'description' => $description, 'rental_catalogue_unit_id' => $unit->id, 'default_price' => 100, 'is_active' => true,
        ]);
    }

    /** A job card line. type 'part' = stock to load; 'labour' = not stock. */
    protected function makeLine(RentalJobCard $card, string $type, string $description, float $quantity, ?string $unit = 'each', array $overrides = []): RentalJobCardLine
    {
        $unitPrice = $overrides['unit_price'] ?? 100.0;

        return RentalJobCardLine::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $card->agency_id, 'rental_job_card_id' => $card->id, 'type' => $type,
            'description' => $description, 'unit' => $unit, 'quantity' => $quantity,
            'unit_price' => $unitPrice, 'line_total' => round($quantity * $unitPrice, 2), 'sort_order' => 1,
        ], $overrides));
    }
}
