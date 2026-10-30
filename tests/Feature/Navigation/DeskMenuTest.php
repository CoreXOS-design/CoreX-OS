<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Support\Navigation\Hubs\ComplianceReportsTabs;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Desk\DeskTestCase;

/**
 * CoreX Desk lives under the TOOLS menu group "Desk" (Johan, 27 Oct 2026 out of Compliance/Settings; moved from Admin to Tools 30 Oct 2026 so agents and
 * branch managers see it): Get help, Desk console, Desk sessions (the audit) and Desk settings (a link into Company Settings > CoreX Desk). Gated on the
 * Desk permissions and the agency's desk_enabled only. Spec .ai/specs/corex-desk.md section 12.
 */
final class DeskMenuTest extends DeskTestCase
{
    private function menu(\App\Models\User $user): string
    {
        return $this->actingAs($user)->get('/corex')->assertOk()->getContent();
    }

    private function deskPanel(string $html): string
    {
        $i = strpos($html, '<div class="corex-nav-panel-title">Desk</div>');

        return $i === false ? '' : substr($html, $i, 3200);
    }

    public function test_owner_roles_see_the_desk_group_with_all_three_pages(): void
    {
        $html = $this->menu($this->owner());
        $panel = $this->deskPanel($html);

        $this->assertStringContainsString("push('desk')", $html, 'the Desk group is in the Tools section');
        $this->assertStringContainsString('>Desk</span>', $html);
        $this->assertStringContainsString('data-desk-open', $panel);
        $this->assertStringContainsString('>Get help</button>', $panel);
        $this->assertStringContainsString(route('corex.desk.console'), $panel);
        $this->assertStringContainsString('>Desk console</a>', $panel);
        $this->assertStringContainsString(route('corex.desk.log.index'), $panel);
        $this->assertStringContainsString('>Desk sessions</a>', $panel);
        $this->assertStringContainsString(route('admin.company-settings') . '#desk', $panel);
        $this->assertStringContainsString('>Desk settings</a>', $panel);
    }

    public function test_a_default_agency_admin_sees_get_help_the_console_the_log_and_the_settings(): void
    {
        $panel = $this->deskPanel($this->menu($this->agentIn($this->agencyA, 'admin')));

        $this->assertStringContainsString('>Get help</button>', $panel);
        $this->assertStringContainsString('>Desk console</a>', $panel);
        $this->assertStringContainsString('>Desk sessions</a>', $panel, 'their own agency\'s audit, as before');
        $this->assertStringContainsString('>Desk settings</a>', $panel, 'they may edit the agency\'s settings');
    }

    public function test_an_agency_admin_given_desk_support_also_sees_the_console(): void
    {
        $panel = $this->deskPanel($this->menu($this->adminWithSupport($this->agencyA)));

        $this->assertStringContainsString('>Desk console</a>', $panel);
        $this->assertStringContainsString('>Desk sessions</a>', $panel);
    }

    /** @return array<string, array{string}> */
    public static function ordinaryRoles(): array
    {
        return ['agent' => ['agent'], 'branch manager' => ['branch_manager']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryRoles')]
    public function test_an_agent_and_a_branch_manager_see_desk_under_tools_with_get_help_the_console_and_the_log(string $role): void
    {
        $html = $this->menu($this->agentIn($this->agencyA, $role));
        $panel = $this->deskPanel($html);

        $this->assertStringContainsString("push('desk')", $html);
        $this->assertLessThan(strpos($html, 'Admin</div>') ?: PHP_INT_MAX, strpos($html, "push('desk')"), 'Desk sits in Tools, before the Admin label');
        $this->assertStringContainsString('>Get help</button>', $panel);
        $this->assertStringContainsString('data-desk-open', $panel);
        $this->assertStringContainsString('>Desk console</a>', $panel);
        $this->assertStringContainsString('>Desk sessions</a>', $panel);
        $this->assertStringNotContainsString('>Desk settings</a>', $panel, 'only those who may edit the agency settings');
    }

    public function test_a_viewer_sees_get_help_only(): void
    {
        $panel = $this->deskPanel($this->menu($this->agentIn($this->agencyA, 'viewer')));

        $this->assertStringContainsString('>Get help</button>', $panel);
        $this->assertStringNotContainsString('>Desk console</a>', $panel);
        $this->assertStringNotContainsString('>Desk sessions</a>', $panel);
    }

    public function test_a_role_without_desk_request_help_sees_no_desk_group(): void
    {
        \App\Models\RolePermission::where('role', 'viewer')->where('permission_key', 'desk.request_help')->delete();
        \App\Services\PermissionService::clearCache();

        $html = $this->menu($this->agentIn($this->agencyA, 'viewer'));

        $this->assertStringNotContainsString("push('desk')", $html);
        $this->assertSame('', $this->deskPanel($html));
    }

    public function test_with_desk_switched_off_the_agent_loses_it_and_the_admin_keeps_the_log_and_settings(): void
    {
        $this->agencyA->update(['desk_enabled' => false]);

        $this->assertStringNotContainsString("push('desk')", $this->menu($this->agentIn($this->agencyA, 'agent')));
        $this->assertStringNotContainsString("push('desk')", $this->menu($this->agentIn($this->agencyA, 'viewer')));

        $panel = $this->deskPanel($this->menu($this->agentIn($this->agencyA, 'admin')));
        $this->assertStringNotContainsString('>Get help</button>', $panel);
        $this->assertStringNotContainsString('>Desk console</a>', $panel);
        $this->assertStringContainsString('>Desk sessions</a>', $panel);
        $this->assertStringContainsString('>Desk settings</a>', $panel, 'so an admin can switch Desk back on');
    }

    public function test_the_old_homes_are_gone_and_the_new_routes_are_the_only_ones(): void
    {
        // not a Compliance tab any more
        $this->assertArrayNotHasKey('desk-sessions', ComplianceReportsTabs::tabs());
        $this->assertFalse(Route::has('compliance.desk-sessions.index'));
        $this->assertFalse(Route::has('compliance.desk-sessions.show'));
        // not a Settings-menu item either
        $sidebar = (string) file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        $this->assertStringNotContainsString('>CoreX Desk</a>', $sidebar);
        $this->assertStringNotContainsString("'view_payroll_reports', 'desk.support']))", $sidebar, 'desk.support no longer opens the Admin section');
        $this->assertStringNotContainsString('Desk (slide-panel group, Admin)', $sidebar);

        $this->assertSame('corex/desk-log', Route::getRoutes()->getByName('corex.desk.log.index')->uri());
        $this->assertSame('corex/desk', Route::getRoutes()->getByName('corex.desk.console')->uri());
    }

    public function test_opening_a_desk_page_opens_the_desk_panel(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/corex/desk')->assertOk()->assertSee('corex-nav-panel is-open', false);
        $html = $this->actingAs($owner)->get('/corex/desk-log')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/corex-nav-group-toggle active"[^>]*>\s*<svg[\s\S]{0,900}?<span>Desk<\/span>/', $html, 'the Desk group is the active one on its own pages');
    }

    public function test_the_spec_navigation_section_names_the_group_and_its_three_pages(): void
    {
        $spec = (string) file_get_contents(base_path('.ai/specs/corex-desk.md'));
        foreach (['Tools › Desk', 'Get help', 'Desk console', 'Desk sessions', 'Desk settings', '/corex/desk-log'] as $term) {
            $this->assertStringContainsString($term, $spec, "section 12 must mention {$term}");
        }
    }
}
