<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\OfficerAppointment;
use App\Models\User;
use App\Services\Compliance\OfficerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Per-module RO / CO registry — spec esign-compliance-approval-gate.md §5.1 / §6.6 / §7 / §13.
 * Paths proven: one-CO invariant (re-appoint same = no-op); RO diff-set never deletes; CO dropped
 * from the RO list; route guard (no CO → refused); end-CO guard while route is on; agency-scoped
 * user ids on the HTTP saver; legacy approver backfill (first → CO, rest → RO, policy flipped).
 */
final class OfficerAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $a;
    private User $b;
    private OfficerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->admin  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);
        $this->a      = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Anelisa Mthembu']);
        $this->b      = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'name' => 'Ben Botha']);
        // Agency-wide, full-status — the only kind of person who may be the e-sign CO.
        $this->c      = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Carla Coetzee', 'designation' => 'Principal Property Practitioner']);
        $this->d      = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Dawie Nel', 'designation' => 'Property Practitioner']);
        $this->registry = app(OfficerRegistry::class);
    }

    private User $c;
    private User $d;

    public function test_esign_co_must_see_the_whole_agency(): void
    {
        foreach ([$this->a, $this->b] as $narrow) {
            try {
                $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $narrow->id, $this->admin->id);
                $this->fail("{$narrow->role} sees only own / branch documents and cannot be the e-sign CO");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('must see the whole agency', $e->errors()['co_user_id'][0]);
            }
        }
        $this->assertNull($this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_ESIGN));

        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->c->id, $this->admin->id);
        $this->assertSame($this->c->id, $this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_ESIGN)->user_id);
    }

    public function test_whistleblow_co_must_see_every_report(): void
    {
        // Own- and branch-scoped people would never see the reports they must decide. (With seeded
        // grants a branch manager holding "View All Agency Complaints" qualifies; the test posture
        // has no grants, so the explicit widening — which never fails open — does not apply here.)
        foreach ([$this->a, $this->b] as $narrow) {
            try {
                $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $narrow->id, $this->admin->id);
                $this->fail("{$narrow->role} cannot be the compliance-reporting CO");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('would not see every compliance report', $e->errors()['co_user_id'][0]);
            }
        }

        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $this->c->id, $this->admin->id);
        $this->assertSame($this->c->id, $this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW)->user_id);
    }

    public function test_route_two_needs_a_full_status_officer_and_keeps_one(): void
    {
        $officeAdmin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Odette Office', 'designation' => 'Office Admin']);
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $officeAdmin->id, $this->admin->id);

        try {
            $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);
            $this->fail('no full-status officer → a candidate could never be authorised');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('full-status', $e->errors()['esign_approval_route'][0]);
        }

        // A full-status RO makes the route switchable…
        $fullRo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'name' => 'Fikile Full', 'designation' => 'Property Practitioner']);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [$fullRo->id], $this->admin->id);
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);

        // …and, while on, cannot be unticked if they were the last one.
        try {
            $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [], $this->admin->id);
            $this->fail('the last full-status officer cannot be removed while the route is on');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ro_user_ids', $e->errors());
        }
        $this->assertEquals([$fullRo->id], $this->registry->activeRos($this->agency->id, OfficerAppointment::MODULE_ESIGN)->pluck('user_id')->all());

        // Replacing the CO with a full-status one, then unticking the RO, is fine.
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->c->id, $this->admin->id);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [], $this->admin->id);
        $this->assertTrue($this->registry->esignRouteIsRoCo($this->agency->id));
    }

    public function test_one_co_per_module_and_reappointing_the_same_person_is_a_no_op(): void
    {
        $first = $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->c->id, $this->admin->id);
        $same  = $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->c->id, $this->admin->id);
        $this->assertSame($first->id, $same->id);

        $second = $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->d->id, $this->admin->id);

        $this->assertNotNull($first->fresh()->ended_on, 'the previous CO is ended, never deleted');
        $this->assertSame($this->d->id, $this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_ESIGN)->user_id);
        $this->assertSame(2, OfficerAppointment::withoutGlobalScopes()->where('module', 'esign')->co()->count());
        // The other module is untouched.
        $this->assertNull($this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW));
        $this->assertNotNull($second);
    }

    public function test_ro_list_is_a_diff_set_that_never_deletes_and_drops_the_co(): void
    {
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $this->c->id, $this->admin->id);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, [$this->a->id, $this->c->id, ' ', 0], $this->admin->id);

        $ros = $this->registry->activeRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW)->pluck('user_id');
        $this->assertEquals([$this->a->id], $ros->all(), 'the CO is silently dropped from the RO list; junk ids ignored');

        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, [], $this->admin->id);
        $this->assertCount(0, $this->registry->activeRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW));
        $this->assertSame(1, OfficerAppointment::withoutGlobalScopes()->where('module', 'whistleblow')->ro()->count(), 'ended, not deleted');
        $this->assertTrue($this->registry->isCo($this->c, OfficerAppointment::MODULE_WHISTLEBLOW, $this->agency->id));
        $this->assertFalse($this->registry->isRo($this->a, OfficerAppointment::MODULE_WHISTLEBLOW, $this->agency->id));
    }

    public function test_route_cannot_switch_on_without_a_co_and_last_co_cannot_end_while_on(): void
    {
        try {
            $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);
            $this->fail('route must be refused without a CO');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('esign_approval_route', $e->errors());
        }
        $this->assertSame('full_status', $this->registry->esignRoute($this->agency->id));

        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->c->id, $this->admin->id);
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);
        $this->assertTrue($this->registry->esignRouteIsRoCo($this->agency->id));

        try {
            $this->registry->endCo($this->agency->id, OfficerAppointment::MODULE_ESIGN);
            $this->fail('the last CO cannot be ended while the route is on');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('co_user_id', $e->errors());
        }
        $this->assertNotNull($this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_ESIGN));

        // Replacing is always allowed; switching the route off first then ending is allowed.
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->d->id, $this->admin->id);
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_FULL_STATUS);
        $this->registry->endCo($this->agency->id, OfficerAppointment::MODULE_ESIGN);
        $this->assertNull($this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_ESIGN));
    }

    public function test_http_saver_refuses_a_user_from_another_agency(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $foreign = User::factory()->create(['agency_id' => $rival->id, 'role' => 'agent']);

        $this->actingAs($this->admin)
            ->post(route('corex.settings.officers.co', ['module' => 'esign']), ['co_user_id' => $foreign->id])
            ->assertSessionHasErrors('co_user_id');
        $this->assertNull($this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_ESIGN));

        $this->actingAs($this->admin)
            ->post(route('corex.settings.officers.ros', ['module' => 'esign']), ['ro_user_ids' => [$this->a->id, $foreign->id]])
            ->assertSessionHasErrors('ro_user_ids.1');
        $this->assertCount(0, $this->registry->activeRos($this->agency->id, OfficerAppointment::MODULE_ESIGN));

        $this->actingAs($this->admin)
            ->post(route('corex.settings.officers.co', ['module' => 'payroll']), ['co_user_id' => $this->a->id])
            ->assertNotFound();
    }

    // ── Page renders (functional verification through the booted app, test DB) ──

    public function test_settings_hub_and_queue_pages_render_for_an_officer(): void
    {
        $this->admin->update(['designation' => 'Principal Property Practitioner']); // a full-status officer is required to switch on
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->admin->id, $this->admin->id);
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);

        $this->actingAs($this->admin)->get(route('corex.settings', ['s' => 'user']))
            ->assertOk()
            ->assertSee('E-sign approval — Reporting Officers and Compliance Officer')
            ->assertSee('Compliance reporting — Reporting Officers and Compliance Officer')
            ->assertDontSee('Approval Authority');

        $this->actingAs($this->admin)->get(route('corex.approvals.index'))
            ->assertOk()
            ->assertSee('Documents awaiting release')
            ->assertSee('Compliance reports')
            ->assertSee('FICA');

        $this->actingAs($this->admin)->get(route('docuperfect.approvals.index'))
            ->assertOk()
            ->assertSee('Nothing is waiting for your approval.');

        $this->actingAs($this->admin)->get(route('docuperfect.esign.myDocuments'))
            ->assertOk();

        $this->actingAs($this->admin)->getJson(route('api.v1.approvals.pending'))
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    public function test_queue_page_explains_itself_to_a_non_officer(): void
    {
        $this->actingAs($this->a)->get(route('docuperfect.approvals.index'))
            ->assertOk()
            ->assertSee('You are not an e-sign officer for this agency.');
    }

    public function test_legacy_whistleblow_approvers_backfill_first_to_co_rest_to_ro(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $foreign = User::factory()->create(['agency_id' => $rival->id, 'role' => 'agent']);
        DB::table('agencies')->where('id', $this->agency->id)->update([
            'whistleblow_approver_user_ids' => json_encode([$this->b->id, $this->a->id, $foreign->id, 999999]),
            'whistleblow_ro_can_submit'     => false,
        ]);

        $migration = require base_path('database/migrations/2026_09_14_100001_create_officer_appointments_table.php');
        $m = new \ReflectionMethod($migration, 'backfillWhistleblowApprovers');
        $m->setAccessible(true);
        $m->invoke($migration);

        $co = $this->registry->currentCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW);
        $this->assertSame($this->b->id, $co->user_id, 'first listed approver becomes the CO');
        $this->assertEquals([$this->a->id], $this->registry->activeRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW)->pluck('user_id')->all(), 'foreign / dead ids never appointed');
        $this->assertTrue($this->registry->whistleblowRosMaySubmit($this->agency->id), 'everyone who could send yesterday still can');
    }
}
