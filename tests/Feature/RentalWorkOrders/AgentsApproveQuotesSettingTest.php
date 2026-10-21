<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\RentalSettingAuditEntry;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalWorkOrderApprovalRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-494 (Johan's ruling): ONE agency switch - "Agents can approve quotes and send them to the owner" (default ON). It decides, in one place
 * (RentalWorkOrderApprovalRules, called from PermissionService), whether the default `agent` role holds the two money-decision keys
 * (record_approval, manage_quotes). Branch managers / admins / custom roles keep exactly what Role Manager gives them. Where a control is
 * not allowed the screen says "Ask your branch manager to approve" instead of showing a dead button.
 */
final class AgentsApproveQuotesSettingTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('AgentsApprove');
        $this->ownerLimits(500.0);
    }

    private function role(string $name, array $keyToScope): User
    {
        Role::firstOrCreate(['name' => $name, 'agency_id' => $this->agency->id], ['label' => ucfirst($name)]);
        foreach ($keyToScope as $key => $scope) {
            RolePermission::updateOrCreate(['role' => $name, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $name]);
    }

    private function agent(array $extra = []): User
    {
        // the default agent role as shipped: it can run a job, but is NOT granted the two money keys by Role Manager
        return $this->agentWith(array_merge(['rental_work_orders.view' => 'own', 'rental_work_orders.create' => 'own', 'rental_work_orders.complete' => 'own'], $extra));
    }

    public function test_with_the_switch_on_by_default_the_agent_role_holds_both_money_keys(): void
    {
        $agent = $this->agent();

        $this->assertTrue(RentalWorkOrderSetting::agentsApproveQuotesFor($this->agency->id), 'ON when nothing was ever saved');
        $this->assertTrue($agent->hasPermission('rental_work_orders.record_approval'));
        $this->assertTrue($agent->hasPermission('rental_work_orders.manage_quotes'));
    }

    public function test_with_the_switch_off_the_agent_role_holds_neither_and_emergency_approval_goes_with_them(): void
    {
        $agent = $this->agent(['rental_work_orders.record_emergency_approval' => 'own']);
        $this->assertTrue($agent->hasPermission('rental_work_orders.record_emergency_approval'), 'ON: approval is implied, so the emergency key counts');

        $this->setting(['agents_approve_quotes' => false]);
        PermissionService::clearCache();

        $this->assertFalse($agent->hasPermission('rental_work_orders.record_approval'));
        $this->assertFalse($agent->hasPermission('rental_work_orders.manage_quotes'));
        $this->assertFalse($agent->hasPermission('rental_work_orders.record_emergency_approval'), 'an emergency tick with no Authorise button behind it is a dead end');
        $this->assertTrue($agent->hasPermission('rental_work_orders.create'), 'the rest of the agent role is untouched');
    }

    public function test_branch_managers_and_custom_roles_are_not_governed_by_the_switch(): void
    {
        $this->setting(['agents_approve_quotes' => false]);
        $manager = $this->role('branch_manager', ['rental_work_orders.view' => 'branch', 'rental_work_orders.record_approval' => 'branch', 'rental_work_orders.manage_quotes' => 'branch']);
        $quoter = $this->role('quote_clerk', ['rental_work_orders.view' => 'branch', 'rental_work_orders.manage_quotes' => 'branch']);

        $this->assertTrue($manager->hasPermission('rental_work_orders.record_approval'));
        $this->assertTrue($manager->hasPermission('rental_work_orders.manage_quotes'));
        $this->assertTrue($quoter->hasPermission('rental_work_orders.manage_quotes'));
        $this->assertFalse($quoter->hasPermission('rental_work_orders.record_approval'), 'a custom role is exactly what Role Manager says');

        $this->setting(['agents_approve_quotes' => true]);
        PermissionService::clearCache();
        $this->assertFalse($quoter->hasPermission('rental_work_orders.record_approval'), 'switching ON does not widen a custom role');
    }

    public function test_the_screen_shows_choose_and_select_when_on_and_ask_your_branch_manager_when_off(): void
    {
        $agent = $this->agent();
        $wo = $this->externalWorkOrder(['created_by_user_id' => $agent->id]);
        $supplier = $this->supplier();
        $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 100, 'quote_date' => now()], $agent);
        $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 120, 'quote_date' => now()], $agent);

        $on = $this->actingAs($agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('Choose a quote', $on);
        $this->assertStringContainsString('/select', $on);
        $this->assertStringNotContainsString('data-ask-branch-manager', $on);

        $this->setting(['agents_approve_quotes' => false]);
        PermissionService::clearCache();
        $off = $this->actingAs($agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString(RentalWorkOrderApprovalRules::ASK_MANAGER, $off);
        $this->assertStringContainsString('data-ask-branch-manager', $off);
        $this->assertStringNotContainsString('Choose a quote', $off, 'no dead button');
        $this->assertStringNotContainsString('data-capture-quote-toggle', $off);
        $this->assertStringNotContainsString('class="corex-btn-outline text-xs">Select</button>', $off);
    }

    public function test_capturing_and_selecting_a_quote_follow_the_switch_by_direct_url_too(): void
    {
        $agent = $this->agent();
        $wo = $this->externalWorkOrder(['created_by_user_id' => $agent->id]);
        $payload = ['agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()->format('Y-m-d')];

        $this->actingAs($agent)->post(route('corex.rental-work-orders.quotes.store', $wo), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $wo->quotes()->count());
        $this->assertTrue($wo->quotes()->first()->is_selected, 'ON: the only quote is chosen for the agent');

        $this->setting(['agents_approve_quotes' => false]);
        PermissionService::clearCache();
        $second = $this->externalWorkOrder(['created_by_user_id' => $agent->id]);
        $this->actingAs($agent)->post(route('corex.rental-work-orders.quotes.store', $second), $payload)->assertForbidden();
        $this->assertSame(0, $second->quotes()->count());
        $this->actingAs($agent)->post(route('corex.rental-work-orders.quotes.select', [$wo, $wo->quotes()->first()]), [])->assertForbidden();
    }

    public function test_someone_who_may_capture_but_not_approve_records_the_quote_and_leaves_the_choosing_to_a_manager(): void
    {
        $clerk = $this->role('quote_clerk', ['rental_work_orders.view' => 'branch', 'rental_work_orders.create' => 'branch', 'rental_work_orders.manage_quotes' => 'branch']);
        $wo = $this->externalWorkOrder();

        $res = $this->actingAs($clerk)->post(route('corex.rental-work-orders.quotes.store', $wo), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 900, 'quote_date' => now()->format('Y-m-d'), 'is_selected' => '1',
        ]);

        $res->assertSessionHasNoErrors();
        $res->assertSessionHas('success', fn ($m) => str_contains($m, RentalWorkOrderApprovalRules::ASK_MANAGER));
        $quote = $wo->quotes()->first();
        $this->assertNotNull($quote);
        $this->assertFalse((bool) $quote->is_selected, 'choosing a quote sends it to the owner - that is the approval key');
        $this->assertNotSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status, 'the owner was not asked');
        $this->assertSame([], $this->sent());
        $this->actingAs($clerk)->post(route('corex.rental-work-orders.quotes.select', [$wo, $quote]), [])->assertForbidden();
    }

    public function test_the_switch_is_saved_audited_and_only_changed_when_it_is_posted(): void
    {
        $url = route('corex.settings.rental-work-orders.approvals');

        $this->actingAs($this->admin)->post($url, ['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 0])->assertRedirect();
        $this->assertNull(RentalWorkOrderSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('agents_approve_quotes'), 'a form that does not carry the switch never writes it');
        $this->assertSame(0, RentalSettingAuditEntry::where('setting_key', 'agents_approve_quotes')->count());

        $this->actingAs($this->admin)->post($url, ['agents_approve_quotes' => '0'])->assertRedirect();
        $this->assertFalse(RentalWorkOrderSetting::agentsApproveQuotesFor($this->agency->id));
        $audit = RentalSettingAuditEntry::where('setting_key', 'agents_approve_quotes')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('settings', $audit->source);

        $this->actingAs($this->admin)->post($url, ['agents_approve_quotes' => '0'])->assertRedirect();
        $this->assertSame(1, RentalSettingAuditEntry::where('setting_key', 'agents_approve_quotes')->count(), 'saving the same value leaves no second row');

        $page = $this->actingAs($this->admin)->get(route('corex.settings.rental-work-orders.edit'))->assertOk();
        $page->assertSee('Agents can approve quotes and send them to the owner');
        $page->assertSee('name="agents_approve_quotes"', false);
    }

    public function test_the_setting_is_in_the_setup_wizard_and_in_settings_search(): void
    {
        $copy = file_get_contents(base_path('config/agency-onboarding-copy.php'));
        $this->assertStringContainsString("'key' => 'agents_approve_quotes'", $copy);
        $wizard = file_get_contents(app_path('Http/Controllers/CoreX/AgencySetupWizardController.php'));
        $this->assertStringContainsString("'agents_approve_quotes' => \\App\\Models\\RentalWorkOrderSetting::agentsApproveQuotesFor(", $wizard);
        $this->assertStringContainsString('agents approve quotes', file_get_contents(resource_path('views/corex/settings.blade.php')));
    }
}
