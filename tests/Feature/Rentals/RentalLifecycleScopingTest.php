<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rentals\Concerns\DrivesRentalLifecycle;
use Tests\TestCase;

/**
 * THE RENTAL LIFE, SCOPING - after the chain has produced an application, a lease, an incoming inspection, a fault, a work order
 * and a job card (internal route), prove who can and cannot see them: a SECOND AGENCY (with a full-power agent) sees none of it,
 * on the office screens and on the portals; an agent with OWN scope who is not on any of it sees none; a branch manager of
 * ANOTHER branch sees none; a branch manager of the SAME branch sees all; and the lease's own tenant-side agent sees the lease.
 * Direct URLs by id are refused, not just unlinked. See RentalLifecycleEndToEndTest for the ledger.
 */
final class RentalLifecycleScopingTest extends TestCase
{
    use DrivesRentalLifecycle;
    use RefreshDatabase;

    private const KEYS = ['rental_applications.view', 'leases.view', 'rental_inspections.view', 'rental_fault_reports.view', 'rental_work_orders.view',
        'rental_job_cards.view', 'rental_command_centre.view', 'properties.view', 'access_properties'];

    /** words that identify THIS chain's records on a list screen */
    private const MARKERS = ['Thandi Nkosi', 'Ocean View', 'Kitchen tap', 'kitchen tap'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildLifecycleWorld();
    }

    private function grant(Agency $agency, string $role, string $scope): void
    {
        Role::firstOrCreate(['name' => $role, 'agency_id' => $agency->id], ['label' => ucfirst($role)]);
        foreach (self::KEYS as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id], ['scope' => $scope]);
        }
    }

    private function user(Agency $agency, Branch $branch, string $role, string $name): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true, 'name' => $name, 'email' => 'can.assurance+' . \Illuminate\Support\Str::slug($name) . '@gmail.com']);
    }

    /** @return array<string, string> label => url of every office list screen */
    private function listScreens(): array
    {
        return [
            'applications' => route('corex.rental-applications.index'), 'leases' => route('corex.leases.index'),
            'inspections' => route('corex.rental-inspections.index'), 'faults' => route('corex.rental-fault-reports.index'),
            'work orders' => route('corex.rental-work-orders.index'), 'job cards' => route('corex.rental-job-cards.index'),
            'command centre' => route('corex.rentals.command-centre.index'),
        ];
    }

    /** @return array<string, string> label => url of every single record of the chain */
    private function recordScreens(): array
    {
        $wo = $this->fault->fresh()->workOrder;

        return [
            'application' => route('corex.rental-applications.show', $this->application), 'lease' => route('corex.leases.show', $this->lease),
            'inspection' => route('corex.rental-inspections.show', $this->inspection), 'fault' => route('corex.rental-fault-reports.show', $this->fault),
            'work order' => route('corex.rental-work-orders.show', $wo), 'job card' => route('corex.rental-job-cards.show', $wo->jobCard),
        ];
    }

    private function assertSeesNothing(User $who, string $why): void
    {
        $leaks = [];
        foreach ($this->listScreens() as $label => $url) {
            $this->asStaff($who);
            $res = $this->get($url);
            if ($res->getStatusCode() === 200) {
                foreach (self::MARKERS as $marker) {
                    if (str_contains($res->getContent(), $marker)) {
                        $leaks[] = "list '{$label}' shows '{$marker}'";
                    }
                }
            } elseif (! in_array($res->getStatusCode(), [302, 403, 404], true)) {
                $leaks[] = "list '{$label}' answered " . $res->getStatusCode();
            }
        }
        foreach ($this->recordScreens() as $label => $url) {
            $this->asStaff($who);
            $status = $this->get($url)->getStatusCode();
            if (! in_array($status, [302, 403, 404], true)) {
                $leaks[] = "direct URL of the {$label} answered {$status}";
            }
        }
        $this->assertSame([], $leaks, "{$why} - " . implode('; ', $leaks));
    }

    public function test_who_can_and_cannot_see_the_rental_life(): void
    {
        $this->driveToActiveLeaseWithInspection();
        $this->ownerDecides(['decision' => 'approve', 'handled_by' => 'agency'], '8.1', 'the owner approves - "my agent arranges it"');
        $this->step('9.0', 'an internal work order with a job card exists to be scoped', 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), ['assignment_type' => 'internal', 'title' => 'Fix the kitchen tap', 'description' => 'Drips'])->assertSessionHasNoErrors();
            $this->assertNotNull($this->fault->fresh()->workOrder?->jobCard);
        }, ['8.1']);

        // From here on real permission rows exist, so every user below is held to them (the staff above were not).
        $other = Agency::create(['name' => 'Fynbos Properties', 'slug' => 'fynbos-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'F-' . $other->id, 'is_active' => true]);
        $this->grant($this->agency, 'admin', 'all');
        $this->grant($this->agency, 'agent', 'own');
        $this->grant($this->agency, 'branch_manager', 'branch');
        $this->grant($other, 'agent', 'all'); // the stranger is full-power IN HER OWN agency
        PermissionService::clearCache();
        $stranger = $this->user($other, $otherBranch, 'agent', 'Sandra Stranger');
        $unrelated = $this->user($this->agency, $this->branch, 'agent', 'Uma Unrelated');
        $managerElsewhere = $this->user($this->agency, $this->otherBranch, 'branch_manager', 'Bongani Elsewhere');
        $managerHere = $this->user($this->agency, $this->branch, 'branch_manager', 'Bianca Here');

        $this->step('S.0', 'control: an admin of this agency sees the records (so the refusals below mean something)', 'cc6', function () {
            $this->asStaff($this->agent);
            $this->get(route('corex.rental-fault-reports.index'))->assertOk()->assertSee('Kitchen tap');
            foreach ($this->recordScreens() as $label => $url) {
                $this->get($url)->assertOk();
            }
        }, ['9.0']);

        $this->step('S.1', 'a second agency (full-power agent) sees none of it - lists empty, direct URLs refused', 'cc6', function () use ($stranger) {
            $this->assertSeesNothing($stranger, 'second agency');
        }, ['S.0']);

        $this->step('S.2', "a second agency's portal logins cannot read this tenant's fault or work orders", 'cc6', function () use ($other, $otherBranch) {
            $contact = Contact::create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Zinzi', 'last_name' => 'Other', 'email' => 'can.assurance+zinzi@gmail.com']);
            $login = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $other->id]);
            $contact->forceFill(['client_user_id' => $login->id])->saveQuietly();
            \Laravel\Sanctum\Sanctum::actingAs($login, ['client']);
            $this->withHeaders(['Origin' => 'http://localhost', 'Accept' => 'application/json'])->get("/api/v1/client/rentals/fault-reports/{$this->fault->id}")->assertNotFound();
            $this->assertStringNotContainsString('Kitchen tap', $this->portalGet('/fault-reports')->getContent());
            $this->assertStringNotContainsString('Kitchen tap', $this->portalGet('/work-orders')->getContent());
        }, ['S.0']);

        $this->step('S.3', 'an agent with OWN scope who is on none of it sees none of it', 'cc6', function () use ($unrelated) {
            $this->assertSeesNothing($unrelated, 'unrelated own-scoped agent');
        }, ['S.0']);

        $this->step('S.4', 'a branch manager of ANOTHER branch sees none of it', 'cc6', function () use ($managerElsewhere) {
            $this->assertSeesNothing($managerElsewhere, 'branch manager of another branch');
        }, ['S.0']);

        $this->step('S.5', 'a branch manager of the SAME branch sees the lot', 'cc6', function () use ($managerHere) {
            $this->asStaff($managerHere);
            $this->get(route('corex.rental-fault-reports.index'))->assertOk()->assertSee('Kitchen tap');
            foreach ($this->recordScreens() as $label => $url) {
                $this->assertSame(200, $this->get($url)->getStatusCode(), "branch manager cannot open the {$label}");
            }
        }, ['S.0']);

        $leaseAgent = $this->user($this->agency, $this->branch, 'agent', 'Lena Lease-agent');
        $this->step('S.6', "the lease's own tenant-side agent (own scope) can open the lease", 'cc1', function () use ($leaseAgent) {
            $this->lease->forceFill(['tenant_agent_user_id' => $leaseAgent->id])->saveQuietly();
            $this->asStaff($leaseAgent);
            $this->get(route('corex.leases.show', $this->lease))->assertOk();
        }, ['S.0']);

        $this->step('S.7', 'and the fault on that lease (agents on a lease are the ones notified of its faults; the notification links straight to it)', 'cc6', function () use ($leaseAgent) {
            $this->asStaff($leaseAgent);
            $this->get(route('corex.rental-fault-reports.show', $this->fault))->assertOk();
        }, ['S.6']);

        $this->finishLifecycle('scoping');
    }
}
