<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow\Concerns;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Mail\Signatures\BaseSignatureMail;
use App\Services\PermissionService;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalPortalFixtures;

/**
 * Shared world for the Build 3 tests (.ai/specs/rental-work-orders.md §17.3, §17.10): one agency with a branch, an admin
 * who is the property's agent, a rental property with a landlord and a tenant on an active lease, and one crew. Every
 * address is @example.invalid — nothing here can reach a real inbox. Mail goes through a recording fake of
 * RentalMailDispatcher (the agency mailbox path), and Mail::fake() proves nothing is ever sent as a plain Mailable.
 */
trait BuildsCompletionFlowWorld
{
    use BuildsRentalPortalFixtures;

    protected Agency $agency;
    protected Branch $branch;
    protected User $admin;
    protected Property $property;
    protected Lease $lease;
    protected Contact $tenant;
    protected Contact $landlord;
    protected RentalCrew $crew;
    protected object $mailer;

    protected function buildFlowWorld(string $label = 'Flow Lettings'): void
    {
        Auth::logout();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = $this->makeAgency($label);
        $this->branch = Branch::where('agency_id', $this->agency->id)->firstOrFail();
        $this->admin = $this->makeAgent($this->agency);
        $this->property = $this->makeProperty($this->agency, $this->admin, '14 Ocean View Drive, Ramsgate');
        $this->lease = $this->makeLease($this->agency, $this->property);
        $this->tenant = $this->makeTenant($this->agency, $this->lease, ['first_name' => 'Thandi', 'last_name' => 'Nkosi', 'email' => 'thandi.' . uniqid() . '@example.invalid']);
        $this->landlord = $this->makeLandlord($this->agency, $this->property, ['email' => 'pieter.' . uniqid() . '@example.invalid']);
        $this->crew = $this->makeCrew($this->agency, 'Team Ramsgate', ['email' => 'team.' . uniqid() . '@example.invalid']);

        $this->mailer = new class extends RentalMailDispatcher {
            /** @var array<int, array{0: ?string, 1: BaseSignatureMail}> */
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }

            /** @return array<int, array{0: ?string, 1: BaseSignatureMail}> */
            public function sentOf(string $mailClass): array
            {
                return array_values(array_filter($this->sent, fn ($m) => $m[1] instanceof $mailClass));
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $this->mailer);
        Mail::fake();
    }

    /** An INTERNAL job: the work order AND its draft job card, created the way the one "Create work order" action does. */
    protected function internalJob(array $workOrderOverrides = []): array
    {
        $card = app(RentalJobCardService::class)->createForProperty($this->property, [
            'title' => 'Fix the geyser', 'description' => 'No hot water', 'lease_id' => $this->lease->id,
        ], $this->admin);
        $card->forceFill(['rental_crew_id' => $this->crew->id, 'status' => RentalJobCard::STATUS_IN_PROGRESS])->save();
        $workOrder = $card->workOrder;
        if ($workOrderOverrides) {
            $workOrder->forceFill($workOrderOverrides)->save();
        }

        return [$workOrder->fresh(), $card->fresh()];
    }

    /** An EXTERNAL job: a work order with a supplier, already ordered. */
    protected function externalJob(array $overrides = []): RentalWorkOrder
    {
        $supplier = AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => 'Ramsgate Plumbing', 'email' => 'plumber.' . uniqid() . '@example.invalid',
            'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);

        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'agency_service_provider_id' => $supplier->id,
            'title' => 'Replace burst pipe', 'description' => 'Pipe burst under the sink', 'status' => RentalWorkOrder::STATUS_ORDERED,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $overrides));
    }

    protected function faultReport(array $overrides = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_PHONE, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Geyser not heating', 'description' => 'No hot water since Monday', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $overrides));
    }

    /**
     * A same-agency user holding exactly $grants (permission key => 'own'|'branch'|'all'). Creating ANY grant row makes the
     * agency "seeded", after which the admin resolves from role_permissions like everyone else — so the admin is granted
     * every key in $adminKeys here too, or every admin request would 403.
     *
     * @param array<string, string> $grants
     * @param array<int, string> $adminKeys
     */
    protected function userWith(array $grants, string $role = 'agent', array $adminKeys = []): User
    {
        $adminKeys = $adminKeys ?: array_keys($grants);
        foreach ($adminKeys as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        Role::firstOrCreate(['name' => $role, 'agency_id' => $this->agency->id], ['label' => ucfirst($role)]);
        foreach ($grants as $key => $scope) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role]);
    }

    /** A second, completely separate agency — for the "other agency is a 404" and "second agency has its own settings" paths. */
    protected function otherAgencyWorld(): array
    {
        $agency = $this->makeAgency('Cape Town Rentals');
        $agent = $this->makeAgent($agency);
        $property = $this->makeProperty($agency, $agent, '9 Kloof Street, Gardens');
        $lease = $this->makeLease($agency, $property);
        $tenant = $this->makeTenant($agency, $lease, ['first_name' => 'Zinzi', 'email' => 'zinzi.' . uniqid() . '@example.invalid']);

        return [$agency, $agent, $property, $lease, $tenant];
    }

    /**
     * A work order that belongs to ANOTHER agency, for the "another agency is a 404" paths. The acting user is logged out
     * first: BelongsToAgency stamps agency_id from whoever is authenticated (authoritatively), so a record built after an
     * earlier actingAs() would silently land in the acting user's own agency and the test would prove nothing.
     */
    protected function foreignWorkOrder(array $overrides = []): RentalWorkOrder
    {
        Auth::logout();
        [$otherAgency, $otherAgent, $otherProperty] = $this->otherAgencyWorld();

        return RentalWorkOrder::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherProperty->branch_id, 'property_id' => $otherProperty->id,
            'assignment_type' => 'outside_supplier', 'title' => 'Theirs', 'description' => 'x', 'status' => 'ordered',
            'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $otherAgent->id,
        ], $overrides));
    }
}
