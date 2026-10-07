<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixture for the lead-response suite (Johan, 2026-10-07). "Now" is frozen at Wednesday 2026-10-07 12:00
 * (Africa/Johannesburg). Default counting hours = every day 08:00–20:00; default target = 60 minutes.
 */
abstract class LeadResponseTestCase extends TestCase
{
    use RefreshDatabase;

    protected Agency $agency;
    protected Branch $branch1;
    protected Branch $branch2;
    protected User $admin;
    protected User $manager;   // branch manager, branch 1
    protected User $agentA;    // branch 1
    protected User $agentB;    // branch 1
    protected User $agentC;    // branch 2
    protected Property $listingA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'Africa/Johannesburg'));

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        $this->branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'South']);

        $this->admin   = $this->user('Ada Admin', 'admin', $this->branch1);
        $this->manager = $this->user('Mo Manager', 'branch_manager', $this->branch1);
        $this->agentA  = $this->user('Anna Agent', 'agent', $this->branch1);
        $this->agentB  = $this->user('Ben Agent', 'agent', $this->branch1);
        $this->agentC  = $this->user('Cara Agent', 'agent', $this->branch2);

        $this->listingA = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agentA->id, 'branch_id' => $this->branch1->id,
            'external_id' => (string) Str::uuid(), 'title' => '12 Beach Rd', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_500_000, 'published_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(string $name, string $role, Branch $branch): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true,
        ]);
    }

    protected function contact(string $first, ?User $agent = null, string $last = 'Lead'): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id,
            'created_by_user_id' => ($agent ?? $this->admin)->id, 'agent_id' => ($agent ?? $this->admin)->id,
            'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => $first, 'last_name' => $last,
            'phone' => '082' . random_int(1000000, 9999999), 'email' => strtolower($first) . '-' . Str::random(4) . '@example.co.za',
        ]);
    }

    /**
     * A tracked portal enquiry. $o: received (local datetime string), by (User|null for the listing agent), portal,
     * contact (Contact), responded (local datetime), responder (User), channel, tracked (bool), listing (bool).
     */
    protected function lead(array $o = []): PortalLead
    {
        $by = array_key_exists('by', $o) ? $o['by'] : $this->agentA;
        $contact = $o['contact'] ?? $this->contact($o['name'] ?? 'Lee', $by ?? $this->agentA);
        $tz = 'Africa/Johannesburg';

        return PortalLead::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id,
            'portal' => $o['portal'] ?? PortalLead::PORTAL_P24,
            'lead_type' => 'enquiry',
            'listing_id' => ($o['listing'] ?? true) ? $this->listingA->id : null,
            'contact_id' => $contact->id,
            'received_by_user_id' => $by?->id,
            'name' => $o['name'] ?? 'Lee Lead',
            'lead_source_raw' => [],
            'received_at' => Carbon::parse($o['received'] ?? '2026-10-07 09:00:00', $tz),
            'first_response_at' => isset($o['responded']) ? Carbon::parse($o['responded'], $tz) : null,
            'first_response_by_user_id' => isset($o['responded']) ? ($o['responder'] ?? $by ?? $this->agentA)->id : null,
            'first_response_channel' => isset($o['responded']) ? ($o['channel'] ?? 'contacted_action') : null,
            'response_tracked' => $o['tracked'] ?? true,
        ]);
    }

    /** Role-manager rows the two reports read (view gate + data scope), as in BuyersReportPrintPdfTest. */
    protected function grantReports(): void
    {
        foreach (['admin' => 'all', 'branch_manager' => 'branch', 'agent' => 'own'] as $role => $scope) {
            DB::table('role_permissions')->insert([
                ['role' => $role, 'permission_key' => 'view_buyers_report', 'agency_id' => $this->agency->id, 'scope' => null],
                ['role' => $role, 'permission_key' => 'buyers_report.view', 'agency_id' => $this->agency->id, 'scope' => $scope],
                ['role' => $role, 'permission_key' => 'view_performance', 'agency_id' => $this->agency->id, 'scope' => null],
                ['role' => $role, 'permission_key' => 'performance_report.view', 'agency_id' => $this->agency->id, 'scope' => $scope],
                ['role' => $role, 'permission_key' => 'command_center.settings', 'agency_id' => $this->agency->id, 'scope' => null],
            ]);
        }
        PermissionService::clearCache();
    }
}
