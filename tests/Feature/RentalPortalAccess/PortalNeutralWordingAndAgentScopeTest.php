<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * 8 Oct 2026, found by the end-to-end lifecycle test (tests/Feature/Rentals/RentalLifecycle*): (1) the tenant's fault JSON carried the
 * owner's decision word ("declined") next to the neutral line; (2) the agency's own crew's sign-off NAME reached the tenant and owner
 * ("reported by Crew Chief") instead of the agency's team label; (3) an own-scoped agent who is the lease's tenant-side or owner-side
 * agent - or the property's agent - was notified about a fault and then got a 403 opening it.
 */
final class PortalNeutralWordingAndAgentScopeTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
    }

    // ── 1. the tenant never carries the owner's decision word ────────────────

    public function test_a_declined_fault_reads_neutral_to_the_tenant_everywhere(): void
    {
        $fault = $this->faultReport([
            'status' => RentalFaultReport::STATUS_DECLINED, 'owner_approval_status' => RentalFaultReport::APPROVAL_DECLINED,
            'outcome' => RentalFaultReport::OUTCOME_OWNER_DECLINED, 'outcome_note' => 'SECRET reason',
        ]);
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $list = $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk();
        $show = $this->getJson("/api/v1/client/rentals/fault-reports/{$fault->id}")->assertOk();

        foreach ([$list->getContent(), $show->getContent()] as $json) {
            $this->assertStringNotContainsString('declined', strtolower($json), 'the owner\'s decision word reached the tenant');
            $this->assertStringNotContainsString('SECRET', $json);
        }
        $this->assertSame('not_approved', $show->json('fault_report.status'));
        $this->assertSame('not_approved', $show->json('fault_report.outcome'));

        // an owner who handles the repair themselves reads as plain "approved" to the tenant
        $handling = $this->faultReport(['status' => RentalFaultReport::STATUS_OWNER_HANDLING, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED]);
        $this->assertSame('approved', $this->getJson("/api/v1/client/rentals/fault-reports/{$handling->id}")->json('fault_report.status'));

    }

    // ── 2. the crew member's name never reaches the tenant or the owner ──────

    public function test_the_agencys_own_crew_is_the_team_label_never_a_crew_members_name_and_the_label_is_the_agencys_own(): void
    {
        [$wo] = $this->internalJob();
        $round = new RentalWorkCompletionRound(['reported_by_label' => 'Crew Chief']);
        $view = app(RentalWorkOrderClientViewService::class);

        $this->assertSame('Our maintenance team', $view->reportedBy($round, $wo), 'neutral default');
        $this->assertSame('Our maintenance team', $view->payload($wo, 'tenant')['who_label']);

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['internal_team_label' => 'Ramsgate Maintenance']);
        $this->assertSame('Ramsgate Maintenance', $view->reportedBy($round, $wo->fresh()));
        $this->assertSame('Ramsgate Maintenance', $view->payload($wo->fresh(), 'landlord')['who_label']);

        // the "please check the work" mail uses it too
        $with = (new RentalTenantCompletionCheckMail($round, $wo->fresh(), 'Thandi', 'https://example.invalid/check', null, $this->tenant))->content()->with;
        $this->assertSame('Ramsgate Maintenance', $with['reportedBy']);

        // a contractor's reporting label is shown as captured (it is the business, not the agency's crew)
        $external = $this->externalJob();
        $this->assertSame('Crew Chief', $view->reportedBy($round, $external));

        // a second agency has its own wording
        [$other, , , , ] = $this->otherAgencyWorld();
        $this->assertSame('Our maintenance team', RentalWorkOrderSetting::internalTeamLabelFor($other->id));
    }

    public function test_the_team_label_saves_from_settings_blank_restores_the_default_and_the_wizard_has_it(): void
    {
        $url = route('corex.settings.rental-work-orders.completion-check');
        $this->actingAs($this->admin)->post($url, ['internal_team_label' => '  <b>Margate</b> Maintenance  '])->assertSessionHasNoErrors();
        $this->assertSame('Margate Maintenance', RentalWorkOrderSetting::internalTeamLabelFor($this->agency->id));

        $this->actingAs($this->admin)->post($url, ['completion_response_window_days' => 7])->assertSessionHasNoErrors();
        $this->assertSame('Margate Maintenance', RentalWorkOrderSetting::internalTeamLabelFor($this->agency->id), 'a post without the field leaves it alone');

        $this->actingAs($this->admin)->post($url, ['internal_team_label' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Our maintenance team', RentalWorkOrderSetting::internalTeamLabelFor($this->agency->id));
        $this->actingAs($this->admin)->post($url, ['internal_team_label' => str_repeat('x', 61)])->assertSessionHasErrors('internal_team_label');

        $control = collect(config('agency-onboarding-copy.leases.controls'))->firstWhere('key', 'internal_team_label');
        $this->assertNotNull($control, 'a setting reaches the Setup Wizard (non-negotiable #10a)');
        $this->assertGreaterThan(60, strlen((string) $control['affects']));
        $this->actingAs($this->admin)->get(route('corex.settings.rental-work-orders.edit'))->assertOk()->assertSee('name="internal_team_label"', false);
    }

    // ── 3. "own" includes the lease's agents and the property's agent ────────

    public function test_an_own_scoped_agent_who_is_the_leases_agent_or_the_propertys_agent_can_open_its_faults_and_work_orders(): void
    {
        $fault = $this->faultReport();
        $wo = $this->externalJob();
        $grants = ['rental_fault_reports.view' => 'own', 'rental_work_orders.view' => 'own', 'rental_work_orders.create' => 'own'];
        $tenantSide = $this->userWith($grants);
        $ownerSide = $this->userWith($grants);
        $propertyAgent = $this->userWith($grants);
        $stranger = $this->userWith($grants);

        $faultUrl = route('corex.rental-fault-reports.show', $fault);
        $woUrl = route('corex.rental-work-orders.show', $wo);

        foreach ([$tenantSide, $ownerSide, $propertyAgent, $stranger] as $u) {
            $this->actingAs($u)->get($faultUrl)->assertForbidden();
            $this->actingAs($u)->get($woUrl)->assertForbidden();
        }

        $this->lease->forceFill(['tenant_agent_user_id' => $tenantSide->id, 'owner_agent_user_id' => $ownerSide->id])->save();
        $this->property->forceFill(['agent_id' => $propertyAgent->id])->save();

        foreach ([$tenantSide, $ownerSide, $propertyAgent] as $u) {
            $this->actingAs($u)->get($faultUrl)->assertOk();
            $this->actingAs($u)->get($woUrl)->assertOk();
            $this->assertTrue(RentalFaultReport::query()->visibleTo($u)->whereKey($fault->id)->exists(), 'the list agrees with the record');
            $this->assertTrue(\App\Models\RentalWorkOrder::query()->visibleTo($u)->whereKey($wo->id)->exists());
        }
        $this->actingAs($stranger)->get($faultUrl)->assertForbidden();
        $this->actingAs($stranger)->get($woUrl)->assertForbidden();
        $this->assertFalse(RentalFaultReport::query()->visibleTo($stranger)->whereKey($fault->id)->exists());
        $this->assertFalse(\App\Models\RentalWorkOrder::query()->visibleTo($stranger)->whereKey($wo->id)->exists());
    }
}
