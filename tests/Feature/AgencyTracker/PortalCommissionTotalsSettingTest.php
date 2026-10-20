<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Agent\MyPortalNumbers;
use App\Services\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md 4.2 / 4.5 check 6: the branch and agency commission totals on My Portal exist only
 * when the AGENCY has switched "Show agents their branch and agency commission totals" on (default off), and
 * even then only for a user whose deal data scope reaches that far - decided in the query layer. The setting
 * is on the commission settings page and in the Setup Wizard (non-negotiable 10a), saved only when posted.
 */
final class PortalCommissionTotalsSettingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Branch $branch2;
    private User $agent;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-20 10:00:00');
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Camps Bay']);
        $this->agent = $this->user($this->branch);
        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(Branch $branch, ?Agency $agency = null): User
    {
        return User::factory()->create(['agency_id' => ($agency ?? $this->agency)->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    private function scope(string $scope): void
    {
        RolePermission::query()->delete();
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_settings', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_my_portal', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_daily_activity', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => $scope, 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
    }

    private function line(User $u, Branch $b, string $date, string $status, float $gross, float $net, ?Agency $agency = null): void
    {
        $agency ??= $this->agency;
        $make = function () use ($u, $b, $date, $status, $gross, $net, $agency) {
            $deal = Deal::create([
                'agency_id' => $agency->id, 'branch_id' => $b->id, 'deal_no' => (string) (7000 + ++$this->seq), 'period' => substr($date, 0, 7),
                'deal_date' => $date, 'property_value' => 1000000, 'total_commission' => 57500, 'accepted_status' => $status,
            ]);
            DB::table('deal_money_lines')->insert([
                'deal_id' => $deal->id, 'agency_id' => $agency->id, 'user_id' => $u->id, 'branch_id' => $b->id, 'period' => substr($date, 0, 7),
                'side' => 'listing', 'agent_gross_ex_vat' => $gross, 'agent_net_ex_vat' => $net, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        if ($agency->is($this->agency)) {
            $make();

            return;
        }
        $me = auth()->user();
        auth()->guard()->forgetUser();
        $make();
        $this->actingAs($me);
    }

    private function seedMoney(): void
    {
        $colleague = $this->user($this->branch);
        $far = $this->user($this->branch2);
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(5)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $stranger = $this->user($ob, $other);

        $this->line($this->agent, $this->branch, '2026-10-05', 'R', 60000, 45000);
        $this->line($colleague, $this->branch, '2026-10-06', 'G', 30000, 20000);
        $this->line($far, $this->branch2, '2026-10-07', 'P', 90000, 70000);
        $this->line($colleague, $this->branch, '2026-10-08', 'D', 99999, 99999);      // declined
        $this->line($colleague, $this->branch, '2026-09-30', 'R', 11111, 11111);      // another month
        $this->line($stranger, $ob, '2026-10-09', 'R', 777777, 777777, $other);       // another agency
    }

    public function test_the_setting_is_off_by_default_and_then_nobody_gets_totals(): void
    {
        $this->assertFalse((bool) $this->agency->fresh()->portal_show_agency_commission_totals);
        $this->scope('all');
        $this->seedMoney();

        $this->assertNull(app(MyPortalNumbers::class)->commissionTotals($this->agent, '2026-10'));
        $this->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->assertDontSee('data-testid="commission-totals"', false);
    }

    public function test_on_with_an_agency_wide_scope_shows_branch_and_agency_totals_without_declined_other_months_or_other_agencies(): void
    {
        $this->scope('all');
        $this->seedMoney();
        $this->agency->forceFill(['portal_show_agency_commission_totals' => true])->save();

        $t = app(MyPortalNumbers::class)->commissionTotals($this->agent, '2026-10');

        $this->assertSame(['gross' => 90000.0, 'net' => 65000.0], $t['branch'], 'agent + colleague in the branch');
        $this->assertSame(['gross' => 180000.0, 'net' => 135000.0], $t['agency'], 'plus the other branch; not declined, not September, not the other agency');

        $html = $this->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="totals-agency-gross">R 180,000', $html);
        $this->assertStringContainsString('data-testid="totals-branch-gross">R 90,000', $html);
    }

    public function test_a_branch_scope_gets_the_branch_total_only(): void
    {
        $this->scope('branch');
        $this->seedMoney();
        $this->agency->forceFill(['portal_show_agency_commission_totals' => true])->save();

        $t = app(MyPortalNumbers::class)->commissionTotals($this->agent, '2026-10');

        $this->assertSame(90000.0, $t['branch']['gross']);
        $this->assertNull($t['agency']);
    }

    public function test_an_own_scope_gets_nothing_even_with_the_setting_on(): void
    {
        $this->scope('own');
        $this->seedMoney();
        $this->agency->forceFill(['portal_show_agency_commission_totals' => true])->save();

        $this->assertNull(app(MyPortalNumbers::class)->commissionTotals($this->agent, '2026-10'));
        $this->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->assertDontSee('data-testid="commission-totals"', false);
    }

    // ── the setting itself: settings page, saver, wizard ──────────────────────────────

    private function commissionForm(array $extra = []): array
    {
        return $extra + [
            'commission_split_agent' => 60, 'annual_cap' => 100000, 'post_cap_transaction_fee' => 1500, 'post_cap_fee_cap' => 5000,
            'post_cap_reduced_fee' => 500, 'monthly_platform_fee' => 0, 'risk_management_fee' => 0, 'risk_management_cap' => 0,
            'mentor_extra_split' => 10, 'mentor_transactions' => 3, 'revenue_share_pool_percent' => 0,
            'tier_1_percent' => 0, 'tier_2_percent' => 0, 'tier_3_percent' => 0, 'tier_4_percent' => 0, 'tier_5_percent' => 0,
            'tier_6_percent' => 0, 'tier_7_percent' => 0,
            'tier_4_flqa_requirement' => 0, 'tier_5_flqa_requirement' => 0, 'tier_6_flqa_requirement' => 0, 'tier_7_flqa_requirement' => 0,
        ];
    }

    public function test_the_commission_saver_writes_the_setting_only_when_the_form_carried_it(): void
    {
        $url = route('corex.settings.commission.update');

        $this->post($url, $this->commissionForm(['portal_show_agency_commission_totals' => '1']))->assertRedirect();
        $this->assertTrue((bool) $this->agency->fresh()->portal_show_agency_commission_totals, 'switched on');

        // a caller that never rendered the control (an older form, a partial post) must not switch it off
        $this->post($url, $this->commissionForm())->assertRedirect();
        $this->assertTrue((bool) $this->agency->fresh()->portal_show_agency_commission_totals, 'absent key = untouched');

        // the form's own hidden 0 companion switches it off
        $this->post($url, $this->commissionForm(['portal_show_agency_commission_totals' => '0']))->assertRedirect();
        $this->assertFalse((bool) $this->agency->fresh()->portal_show_agency_commission_totals);
    }

    public function test_the_setting_is_per_agency(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(5)]);

        $this->post(route('corex.settings.commission.update'), $this->commissionForm(['portal_show_agency_commission_totals' => '1']))->assertRedirect();

        $this->assertTrue((bool) $this->agency->fresh()->portal_show_agency_commission_totals);
        $this->assertFalse((bool) $other->fresh()->portal_show_agency_commission_totals);
    }

    public function test_the_toggle_is_on_the_settings_pages_and_in_the_setup_wizard(): void
    {
        $partial = view('corex.settings._portal-commission-totals')->render();
        $this->assertStringContainsString('name="portal_show_agency_commission_totals"', $partial);
        $this->assertStringContainsString('Show agents their branch and agency commission totals', $partial);

        $this->assertStringContainsString('corex.settings._portal-commission-totals', (string) file_get_contents(resource_path('views/corex/settings.blade.php')));
        $this->assertStringContainsString('corex.settings._portal-commission-totals', (string) file_get_contents(resource_path('views/corex/settings/commission.blade.php')));

        $step = config('agency-onboarding-copy.commission');
        $control = collect($step['controls'] ?? [])->firstWhere('key', 'portal_show_agency_commission_totals');
        $this->assertNotNull($control, 'surfaced in the wizard commission step (non-negotiable 10a)');
        $this->assertSame('toggle', $control['type']);
        $this->assertSame(0, $control['default'], 'default off');
        $this->assertNotSame('', trim((string) $control['explain']));
        $this->assertStringStartsWith('What this changes:', $control['affects']);
    }
}
