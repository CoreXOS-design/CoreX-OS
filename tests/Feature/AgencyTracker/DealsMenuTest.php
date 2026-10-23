<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 6 (AT-454 lane): the Deals main menu.
 *
 * Input paths proven: the new "Deals" panel holds Deal Register, My Deals, Unfiled deal emails and, last,
 * Deal settings; the Agency Tracker panel holds none of them (no old item left behind, no "DR2" label); an
 * agent sees the first three and never Deal settings, and a direct URL to each settings door is refused;
 * opening a deal page highlights the Deals panel, not Agency Tracker; the register honours the month and
 * commission state the Performance tiles link with, and ignores a month that is not a real one; a
 * second agency's admin sees none of the first agency's deals and cannot open one by id; an own-scope agent
 * cannot open a colleague's deal in the register (the guard the old register carried).
 */
final class DealsMenuTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;
    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agency->id]);

        $this->admin     = $this->user('admin');
        $this->agent     = $this->user('agent');
        $this->colleague = $this->user('agent');

        // An agent holds the deal-reading keys only: no deal-settings key, no settle key.
        foreach (['sidebar.section.agents', 'view_deals', 'view_own_stats', 'create_deals', 'deals.edit'] as $key) {
            $this->grantAgent($key);
        }
        $this->grantAgent('deals.view', 'own');

        // An admin holds the same keys plus the settlement and deal-settings keys, with the whole agency in scope.
        foreach ([
            'sidebar.section.agents', 'view_deals', 'view_own_stats', 'create_deals', 'settle_deals', 'access_settings',
            'deals.create', 'deals.edit', 'access_agency_tracker', 'deals_v2.manage_suppliers', 'deals_v2.manage_pipeline',
            'deals_v2.manage_distribution_rules', 'deals_v2.view',
        ] as $key) {
            $this->grantRole('admin', $key);
        }
        $this->grantRole('admin', 'deals.view', 'all');
        $this->grantAgent('deals.create');

        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────

    private function user(string $role, ?Agency $agency = null): User
    {
        $agency ??= $this->agency;

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function grantAgent(string $key, ?string $scope = null): void
    {
        $this->grantRole('agent', $key, $scope);
    }

    private function grantRole(string $role, string $key, ?string $scope = null, ?Agency $agency = null): void
    {
        RolePermission::updateOrCreate(
            ['role' => $role, 'permission_key' => $key, 'agency_id' => ($agency ?? $this->agency)->id],
            ['scope' => $scope],
        );
    }

    private function deal(User $owner, string $date, string $commissionStatus, string $address, ?Agency $agency = null): int
    {
        $agency ??= $this->agency;
        $id = DB::table('deals')->insertGetId([
            'agency_id' => $agency->id,
            'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'period' => substr($date, 0, 7), 'deal_date' => $date,
            'property_address' => $address, 'accepted_status' => 'G', 'commission_status' => $commissionStatus,
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_user')->insert([
            'deal_id' => $id, 'user_id' => $owner->id, 'side' => 'listing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** The HTML of one sidebar panel: from its title to the next panel's title. */
    private function panel(string $html, string $title): string
    {
        $parts = explode('<div class="corex-nav-panel-title">' . $title . '</div>', $html, 2);
        if (count($parts) < 2) {
            return '';
        }
        $next = strpos($parts[1], 'corex-nav-panel-title">');

        return $next === false ? $parts[1] : substr($parts[1], 0, $next);
    }

    private function dealsPanelIsOpen(string $html): bool
    {
        return str_contains($html, "corex-nav-panel is-open\" :class=\"{ 'is-open': inStack('deals') }\"");
    }

    private function agencyTrackerPanelIsOpen(string $html): bool
    {
        return str_contains($html, "corex-nav-panel is-open\" :class=\"{ 'is-open': inStack('agency-tracker') }\"");
    }

    // ── the menu ─────────────────────────────────────────────────────────────────────────

    public function test_the_deals_menu_holds_the_four_entries_in_order_with_deal_settings_last(): void
    {
        $html = $this->actingAs($this->admin)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $deals = $this->panel($html, 'Deals');

        $this->assertNotSame('', $deals, 'the Deals panel exists');
        $positions = [];
        foreach (['Deal Register', 'My Deals', 'Unfiled deal emails', 'Deal settings', 'Supplier Directory', 'Deal Pipelines', 'Deal Property Sync', 'Deal Document Distribution'] as $label) {
            $at = strpos($deals, '>' . $label . '<');
            $this->assertNotFalse($at, "{$label} is in the Deals panel");
            $positions[$label] = $at;
        }
        $this->assertSame(array_values($positions), collect($positions)->sort()->values()->all(), 'entries appear in the ruled order, Deal settings at the bottom');
        $this->assertStringNotContainsString('DR2', $deals, 'no "DR2" wording is left on the menu');
    }

    public function test_the_agency_tracker_panel_keeps_none_of_the_moved_deal_entries(): void
    {
        $this->grantAgent('access_agency_tracker');
        PermissionService::clearCache();

        foreach ([$this->admin, $this->agent] as $user) {
            $html = $this->actingAs($user)->get(route('deals-dr2.index'))->assertOk()->getContent();
            $tracker = $this->panel($html, 'Agency Tracker');
            $this->assertNotSame('', $tracker, "the Agency Tracker panel renders for {$user->role}, so these absences mean something");

            foreach (['My Deals', 'Deal Register', 'Deal Register (DR2)', 'Unfiled Emails', 'Unfiled deal emails'] as $label) {
                $this->assertStringNotContainsString('>' . $label . '<', $tracker, "{$label} left the Agency Tracker panel ({$user->role})");
            }
            $this->assertStringNotContainsString('/admin/deals', $tracker, 'no link to the old register remains in the panel');
            $this->assertStringNotContainsString('/deals-dr2', $tracker);
            $this->assertStringNotContainsString('/agent/deals', $tracker);
        }
    }

    public function test_an_agent_sees_the_first_three_entries_and_never_deal_settings(): void
    {
        $html = $this->actingAs($this->agent)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $deals = $this->panel($html, 'Deals');

        foreach (['Deal Register', 'My Deals', 'Unfiled deal emails'] as $label) {
            $this->assertStringContainsString('>' . $label . '<', $deals, "{$label} shown to an agent");
        }
        foreach (['Deal settings', 'Supplier Directory', 'Deal Pipelines', 'Deal Property Sync', 'Deal Document Distribution'] as $label) {
            $this->assertStringNotContainsString($label, $deals, "{$label} never shown to an agent");
        }
    }

    public function test_a_non_admin_cannot_open_any_deal_settings_door_by_url(): void
    {
        foreach (['deals-v2.suppliers.index', 'deals-v2.pipeline.index', 'admin.settings.deal-property-sync.index', 'admin.settings.document-distribution'] as $name) {
            $this->actingAs($this->agent)->get(route($name))->assertForbidden();
        }
    }

    public function test_opening_a_deal_page_highlights_the_deals_panel_not_agency_tracker(): void
    {
        $this->grantAgent('access_agency_tracker');
        PermissionService::clearCache();

        $html = $this->actingAs($this->admin)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $this->assertTrue($this->dealsPanelIsOpen($html), 'Deal Register opens the Deals panel');
        $this->assertFalse($this->agencyTrackerPanelIsOpen($html), 'and not Agency Tracker');

        $html = $this->actingAs($this->agent)->get(route('agent.deals.index'))->assertOk()->getContent();
        $this->assertTrue($this->dealsPanelIsOpen($html), 'My Deals opens the Deals panel');
        $this->assertFalse($this->agencyTrackerPanelIsOpen($html));

        $html = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertTrue($this->dealsPanelIsOpen($html), 'a deal-settings door opens the Deals panel');
    }

    // ── the register honours what the Performance tiles link with ───────────────────────

    public function test_the_register_honours_period_and_commission_status_and_ignores_a_bad_month(): void
    {
        $this->deal($this->agent, '2026-10-04', 'Not Paid', '1 Oct-Unpaid Rd');
        $this->deal($this->agent, '2026-10-20', 'Paid', '2 Oct-Paid Rd');
        $this->deal($this->agent, '2026-09-12', 'Not Paid', '3 Sep-Unpaid Rd');

        // The list the register shows (the page also carries a separate "Paid, not settled" exception list, so the
        // rows are read from the view's own data, not from the page text).
        $listed = fn (array $q): array => $this->actingAs($this->admin)->get(route('deals-dr2.index', $q))->assertOk()
            ->viewData('deals')->pluck('property_address')->sort()->values()->all();

        $this->assertSame(['1 Oct-Unpaid Rd'], $listed(['period' => '2026-10', 'commission_status' => 'Not Paid']));
        $this->assertSame(['3 Sep-Unpaid Rd'], $listed(['period' => '2026-09']));

        // the register's own parameter still works
        $this->assertSame(['2 Oct-Paid Rd'], $listed(['commission' => 'Paid']));

        // and a month that is not a real one is ignored, not an error
        foreach (['2026-13', 'soon', '2026-1', '99999-01'] as $bad) {
            $this->assertSame(['1 Oct-Unpaid Rd', '2 Oct-Paid Rd', '3 Sep-Unpaid Rd'], $listed(['period' => $bad]), "bad month {$bad} ignored");
        }

        // the month box shows what is being filtered on
        $this->actingAs($this->admin)->get(route('deals-dr2.index', ['period' => '2026-10']))
            ->assertSee('name="period" value="2026-10"', false);
    }

    // ── scoping: nothing about who sees what has changed ────────────────────────────────

    public function test_another_agencys_admin_sees_none_of_this_agencys_deals_and_cannot_open_one_by_id(): void
    {
        $dealId = $this->deal($this->agent, '2026-10-04', 'Not Paid', '77 Private Rd');

        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . Str::random(6)]);
        Branch::create(['agency_id' => $other->id, 'name' => 'Other Branch']);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $other->id]);
        foreach (['sidebar.section.agents', 'view_deals', 'create_deals', 'deals.create', 'deals.edit'] as $key) {
            $this->grantRole('admin', $key, null, $other);
        }
        $this->grantRole('admin', 'deals.view', 'all', $other);
        PermissionService::clearCache();
        $otherAdmin = $this->user('admin', $other);
        $this->deal($otherAdmin, '2026-10-05', 'Not Paid', '88 Other Rd', $other);

        $html = $this->actingAs($otherAdmin)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $this->assertStringContainsString('88 Other Rd', $html);
        $this->assertStringNotContainsString('77 Private Rd', $html, 'no deal of another agency in the list');

        $this->actingAs($otherAdmin)->get(route('deals-dr2.edit', $dealId))->assertNotFound();
        $this->actingAs($otherAdmin)->get(route('deals-dr2.log', $dealId))->assertNotFound();
    }

    public function test_an_own_scope_agent_sees_only_their_deals_in_the_register_and_cannot_open_a_colleagues(): void
    {
        $mine = $this->deal($this->agent, '2026-10-04', 'Not Paid', '10 Mine Rd');
        $theirs = $this->deal($this->colleague, '2026-10-05', 'Not Paid', '20 Theirs Rd');

        $html = $this->actingAs($this->agent)->get(route('deals-dr2.index'))->assertOk()->getContent();
        $this->assertStringContainsString('10 Mine Rd', $html);
        $this->assertStringNotContainsString('20 Theirs Rd', $html, 'own scope: the colleague\'s deal is not listed');

        $this->actingAs($this->agent)->get(route('deals-dr2.edit', $mine))->assertOk();
        $this->actingAs($this->agent)->get(route('deals-dr2.edit', $theirs))->assertForbidden();
        $this->actingAs($this->agent)->get(route('deals-dr2.log', $theirs))->assertForbidden();
        $this->actingAs($this->agent)->post(route('deals-dr2.remark', $theirs), ['remark' => 'hello'])->assertForbidden();
        $this->actingAs($this->agent)->post(route('deals-dr2.quickUpdate', $theirs), ['accepted_status' => 'R'])->assertForbidden();
        $this->actingAs($this->agent)->post(route('deals-dr2.update', $theirs), [])->assertForbidden();
    }

    public function test_the_old_register_addresses_never_404_for_a_signed_in_user(): void
    {
        foreach ([
            ['get', route('admin.deals')], ['get', route('admin.deals.create')],
            ['get', route('admin.deals.edit', 5)], ['get', route('admin.deals.log', 5)],
            ['post', route('admin.deals.store')], ['post', route('admin.deals.update', 5)],
            ['post', route('admin.deals.quickUpdate', 5)], ['post', route('admin.deals.remark', 5)],
        ] as [$method, $url]) {
            $status = $this->actingAs($this->agent)->{$method}($url)->getStatusCode();
            $this->assertContains($status, [301, 308], "{$method} {$url} redirects ({$status})");
        }
    }
}
