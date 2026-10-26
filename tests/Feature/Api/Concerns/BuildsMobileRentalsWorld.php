<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DeviceToken;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\User;
use App\Scopes\AgencyScope;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushSendResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/**
 * AT-508 — one small rentals world shared by the mobile-rentals tests: two agencies, a staff admin and agent, one rental
 * (property + active lease), a tenant and an owner each with a mobile login, a fault type, and a push transport that records.
 */
trait BuildsMobileRentalsWorld
{
    protected Agency $agency;
    protected Agency $other;
    protected Branch $branch;
    protected User $admin;
    protected User $agent;
    protected Property $property;
    protected Lease $lease;
    protected Contact $tenant;
    protected Contact $owner;
    protected ClientUser $tenantLogin;
    protected ClientUser $ownerLogin;
    protected RentalFaultType $faultType;
    protected object $pushLog;

    protected function buildWorld(): void
    {
        Mail::fake();
        $this->withoutVite();

        $this->pushLog = new class {
            /** @var list<array{tokens:array,payload:array}> */
            public array $sent = [];
        };
        $log = $this->pushLog;
        $this->app->instance(PushTransport::class, new class($log) implements PushTransport {
            public function __construct(private object $log) {}
            public function send(array $tokens, array $payload): PushSendResult
            {
                $this->log->sent[] = ['tokens' => $tokens, 'payload' => $payload];

                return new PushSendResult(count($tokens), []);
            }
            public function isOperational(): bool { return true; }
        });

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true, 'cell' => '0821112222', 'display_email' => 'agent@cape.test']);

        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => '12 Sea Road', 'address' => '12 Sea Road', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->addMonths(10)->toDateString(), 'lease_type' => 'residential', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->tenant = $this->contact('Tina');
        $this->owner = $this->contact('Lenny');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->property->contacts()->attach($this->owner->id, ['role' => 'landlord']);
        $this->tenantLogin = $this->loginFor($this->tenant);
        $this->ownerLogin = $this->loginFor($this->owner);

        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Dripping tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
    }

    protected function contact(string $first, ?Agency $agency = null): Contact
    {
        $agency ??= $this->agency;

        return Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $agency->id, 'branch_id' => $agency->id === $this->agency->id ? $this->branch->id : null, 'first_name' => $first, 'last_name' => 'Test', 'email' => strtolower($first) . uniqid() . '@example.invalid']);
    }

    protected function loginFor(Contact $contact): ClientUser
    {
        $login = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id, 'password' => 'x-secret-pass-1', 'activated_at' => now()]);
        $contact->forceFill(['client_user_id' => $login->id])->saveQuietly();

        return $login;
    }

    protected function phoneFor(ClientUser $login, string $token): void
    {
        DeviceToken::create(['client_user_id' => $login->id, 'platform' => 'ios', 'token' => $token]);
    }

    protected function fault(array $over = []): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone', 'title' => 'Dripping tap', 'description' => 'It drips.',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    protected function asStaff(User $user): static
    {
        Auth::forgetGuards();
        $this->actingAs($user);

        return $this;
    }

    /** A tenant / owner on the mobile app: a client token with the `client` ability. */
    protected function asClient(ClientUser $login): static
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        Sanctum::actingAs($login, ['client']);

        return $this;
    }

    /**
     * Real permission rows for a role in an agency at a scope (own | branch | all). Until a role has rows, staff are not held to a permission
     * (see RentalLifecycleScopingTest), so a scoping test grants first. Owner roles ('admin') ignore agency scoping by design.
     *
     * @param  list<string>  $keys
     */
    protected function grantScope(Agency $agency, string $role, string $scope, array $keys): void
    {
        \App\Models\Role::firstOrCreate(['name' => $role, 'agency_id' => $agency->id], ['label' => ucfirst($role)]);
        foreach ($keys as $key) {
            \App\Models\RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id], ['scope' => $scope]);
        }
        \App\Services\PermissionService::clearCache();
    }

    protected function pushedTo(string $token): array
    {
        return array_values(array_filter($this->pushLog->sent, fn ($s) => in_array($token, $s['tokens'], true)));
    }
}
