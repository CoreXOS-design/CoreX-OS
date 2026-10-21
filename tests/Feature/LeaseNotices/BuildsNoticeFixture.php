<?php

namespace Tests\Feature\LeaseNotices;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseNotice;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Scopes\AgencyScope;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * One agency (Karoo Lettings - nothing of any real agency), a branch, two lease agents, a property with a landlord, and a running FIXED-TERM
 * lease (started 6 months ago, ends in 6 months) with a tenant. Dates are relative to today so the flags are deterministic.
 */
trait BuildsNoticeFixture
{
    protected Agency $agency;
    protected Branch $branch;
    protected User $agent;
    protected User $agent2;
    protected Property $property;
    protected Lease $lease;
    protected Contact $tenant;
    protected Contact $landlord;

    protected function buildNoticeFixture(): void
    {
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid(), 'phone' => '049 555 0101', 'email' => 'desk@karoo.test', 'address' => '3 Church Street, Graaff-Reinet']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Anja Agent']);
        $this->agent2 = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Bheki Backup']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Flat 4 Vlei Court', 'address' => '4 Vlei Court, Graaff-Reinet', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => Carbon::today()->subMonths(6)->toDateString(), 'end_date' => Carbon::today()->addMonths(6)->toDateString(),
            'is_month_to_month' => false, 'lease_type' => 'residential', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent2->id,
        ]);

        $this->tenant = $this->contact('Tina', 'Tenant');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = $this->contact('Lenny', 'Landlord');
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);

        $this->grant($this->agent, ['leases.view', 'leases.create', 'leases.renew', 'rental_notices.review', 'rental_notices.create', 'rental_notices.view', 'rental_notice_templates.manage_settings', 'rental_breaches.view', 'rental_breaches.manage']);
    }

    protected function contact(string $first, string $last, ?Agency $agency = null, ?Branch $branch = null): Contact
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;

        return Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first) . '+' . uniqid() . '@example.test',
        ]);
    }

    protected function grant(User $user, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::firstOrCreate(['role' => $user->role, 'permission_key' => $key], ['scope' => 'all']);
        }
        PermissionService::clearCache();
    }

    protected function clientUserFor(Contact $contact): ClientUser
    {
        if ($contact->client_user_id) {
            return ClientUser::findOrFail($contact->client_user_id);
        }
        $clientUser = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }

    /** A notice case straight in the database (status received unless told otherwise). */
    protected function notice(array $overrides = []): LeaseNotice
    {
        return LeaseNotice::withoutGlobalScopes()->create($overrides + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'lease_id' => $this->lease->id,
            'given_by' => 'tenant', 'via' => 'portal', 'given_by_contact_id' => $this->tenant->id,
            'notice_date' => Carbon::today()->toDateString(), 'intended_end_date' => Carbon::today()->addMonths(6)->toDateString(),
            'reason' => 'Moving to Cape Town for work', 'status' => LeaseNotice::STATUS_RECEIVED,
        ]);
    }
}
