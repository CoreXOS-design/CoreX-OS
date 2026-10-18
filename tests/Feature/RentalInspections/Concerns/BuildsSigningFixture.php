<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections\Concerns;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Models\User;
use App\Services\Rentals\RentalInspectionSigningLinkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * One agency, one rental property with a lease (two tenants), a landlord, an agent who is also the admin, an inspector —
 * and helpers to build an inspection of any type that is ready to sign, sign it by every route, and complete it.
 * Used by the §46/§47 signing tests. MAIL SAFETY: Mail::fake(), non-production redirect set to the one permitted address.
 */
trait BuildsSigningFixture
{
    use RecordsAttendance;

    protected const TEST_ADDRESS = 'can.assurance@gmail.com';
    protected const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected Agency $agency;
    protected Branch $branch;
    protected User $admin;
    protected User $inspector;
    protected Property $property;
    protected Lease $lease;
    protected Contact $tenant;
    protected Contact $tenant2;
    protected Contact $landlord;
    protected PropertyRoom $room;
    protected RentalInspectionItem $item;

    protected function buildFixture(): void
    {
        Auth::logout();
        config(['mail.non_production_redirect' => self::TEST_ADDRESS]);
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        \App\Models\DocumentType::firstOrCreate(['slug' => 'inspection_report'], ['label' => 'Inspection Report', 'is_active' => true]);

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Sea Point', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Aileen Agent', 'email' => 'aileen@cape.test']);
        $this->inspector = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Ivan Inspector', 'email' => 'ivan@cape.test']);
        $this->actingAs($this->admin);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '14 Jackson Street', 'status' => 'active', 'listing_type' => 'rental', 'suburb' => 'Sea Point', 'address' => '14 Jackson Street',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subMonths(6), 'created_by_user_id' => $this->admin->id,
        ]);
        $this->tenant = $this->makeContact('Naledi', 'Dlamini', 'naledi@example.co.za');
        $this->tenant2 = $this->makeContact('Sipho', 'Khumalo', 'sipho@example.co.za');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant2->id]);
        $this->landlord = $this->makeContact('Pieter', 'van Wyk', 'pieter@example.co.za');
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);

        $this->room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Kitchen', 'label' => 'Kitchen', 'source' => 'manual',
            'sort_order' => 0, 'created_by_user_id' => $this->admin->id,
        ]);
        $this->item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Lounge Ceiling', 'sort_order' => 0, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    protected function tearDownFixture(): void
    {
        User::getEventDispatcher()?->forget('eloquent.retrieved: ' . User::class);
    }

    protected function makeContact(string $first, string $last, ?string $email): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    /** An inspection of $type with the one item graded, still being recorded (draft). */
    protected function recording(string $type = RentalInspection::TYPE_IN, array $extra = []): RentalInspection
    {
        $inspection = RentalInspection::create($extra + [
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->admin->id, 'inspector_user_id' => $this->inspector->id,
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $this->item->id, 'observed_by_user_id' => $this->admin->id,
            'condition' => 'good', 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);
        $this->recordAttendanceForEveryParty($inspection);

        return $inspection->fresh();
    }

    /** Ready to sign, through the real endpoint — works for every type. */
    protected function readyToSign(RentalInspection $inspection): RentalInspection
    {
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))->assertOk();

        return $inspection->fresh();
    }

    protected function ready(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return $this->readyToSign($this->recording($type));
    }

    protected function linkService(): RentalInspectionSigningLinkService
    {
        return app(RentalInspectionSigningLinkService::class);
    }

    protected function issueLink(RentalInspection $inspection, string $role, ?int $contactId = null): RentalInspectionSigningLink
    {
        return $this->linkService()->issue($inspection, $role, $contactId, $this->admin);
    }

    protected function guest(): void
    {
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
    }

    protected function signPayload(array $over = []): array
    {
        return $over + ['action' => 'sign', 'typed_name' => 'Naledi Dlamini', 'signature_image' => self::PNG, 'read_confirmed' => true, 'comment' => null];
    }

    /** A tenant signs from their own link (as a guest), then the agent is logged back in. */
    protected function signByLink(RentalInspection $inspection, string $role, ?Contact $contact, string $name): RentalInspectionSigningLink
    {
        $link = $this->issueLink($inspection, $role, $contact?->id);
        $this->guest();
        $this->postJson(route('rental-inspections.sign.submit', $link->token), $this->signPayload(['typed_name' => $name]))->assertStatus(201);
        $this->actingAs($this->admin);

        return $link;
    }

    /** Everyone signs by a mix of routes: tenant by link, tenant2 on the agent's device, landlord in CoreX, agent with the PIN image. */
    protected function everyoneSigns(RentalInspection $inspection): void
    {
        $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');
        $link2 = $this->issueLink($inspection, 'tenant', $this->tenant2->id);
        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link2]), $this->signPayload(['typed_name' => 'Sipho Khumalo']))->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG,
        ])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG,
        ])->assertStatus(201);
    }

    protected function complete(RentalInspection $inspection): void
    {
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
    }

    protected function photoOn(RentalInspection $inspection): RentalInspectionPhoto
    {
        return RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'property_room_id' => $this->room->id,
            'storage_path' => '/fake/path/photo-' . uniqid() . '.jpg', 'uploaded_by_user_id' => $this->admin->id,
        ]);
    }

    protected function liveSignatures(RentalInspection $inspection)
    {
        return RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->whereNull('superseded_at')->get();
    }
}
