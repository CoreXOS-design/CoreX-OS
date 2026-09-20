<?php

namespace Tests\Feature\RoleManager;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CoreXPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug found 2026-09-20 — cc1 proved it live on the real Role Manager screen
 * as admin: for the rental_fault_reports.* keys, .cancel/.record_approval/
 * .resolve showed a generic auto-derived title with the real configured
 * label demoted to a subtitle, and .create was worse — a fully generic
 * "Create" / "Can create records in this module" template with the real
 * label ("Report & Edit Faults") rendered nowhere at all.
 *
 * Root cause, verified directly against resources/views/corex/role-manager.blade.php
 * (not assumed from cc1's read of the rendered page, though that read was
 * accurate): the view special-cases the CRUD-slot suffixes create/edit/archive
 * (there is no .restore key anywhere in config/corex-permissions.php — .archive
 * is the single reversible archive/restore key) with a HARDCODED
 * ucfirst($action)/Str::headline($otherKey) title, discarding or demoting the
 * permission's own configured `label` regardless of whether one exists.
 *
 * Fixed as a class, not a one-off: the configured label is now the title
 * whenever one exists; the generic word is the FALLBACK for a permission
 * with no label, never the override for one that has one. Verified this is
 * the right shape and not a quiet mass-relabel by diffing every rendered
 * permission row (411 of them) on a real QA1 page load before vs after —
 * zero access-type ("Menu / section visibility") rows changed, and the 60
 * permissions whose configured label is genuinely the literal word
 * "Create"/"Edit"/"Archive" render byte-identical before and after (the fix
 * only changes rows where the discarded label actually differed from the
 * generic word).
 */
final class PermissionLabelDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function makePermissions(): void
    {
        CoreXPermission::create([
            'key' => 'zzz_test_widget.view', 'label' => 'View Zzz Test Widgets',
            'section' => 'agency-tracker', 'type' => 'access', 'module' => 'zzz_test_widget', 'sort_order' => 1,
        ]);
        // A CRUD-slot key with a real, distinctive configured label — this is
        // exactly rental_fault_reports.create's shape (label unrelated to the
        // bare word "Create").
        CoreXPermission::create([
            'key' => 'zzz_test_widget.create', 'label' => 'Forge A New Widget',
            'section' => 'agency-tracker', 'type' => 'action', 'module' => 'zzz_test_widget', 'sort_order' => 2,
        ]);
        // A non-CRUD-slot ("other action") key with a real, distinctive label
        // — this is exactly rental_fault_reports.record_approval's shape.
        CoreXPermission::create([
            'key' => 'zzz_test_widget.custom_ping', 'label' => 'Ping The Widget',
            'section' => 'agency-tracker', 'type' => 'action', 'module' => 'zzz_test_widget', 'sort_order' => 3,
        ]);
        // The genuine fallback case: `label` is NOT NULL at the DB level but
        // CAN be an empty string — the one shape a real permission could take
        // that has "no label". Must fall back to the generic derived title,
        // never render blank.
        CoreXPermission::create([
            'key' => 'zzz_test_widget.no_label_edge_case', 'label' => '',
            'section' => 'agency-tracker', 'type' => 'action', 'module' => 'zzz_test_widget', 'sort_order' => 4,
        ]);
    }

    /**
     * Extracts (title, subtitle) pairs from the module's own detail block —
     * the same "<p class=\"text-sm font-medium\">TITLE</p> ... <p class=\"text-xs
     * mt-0.5\">SUBTITLE</p>" row shape every permission row in this view uses —
     * scoped to one module so assertions can't accidentally match an unrelated
     * permission elsewhere on the (very large) page.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function extractRows(string $html, string $moduleKey): array
    {
        // Bug found 2026-09-20 (cc1, re-running these tests after landing):
        // "selectedFeature === '{$moduleKey}'" as a bare substring also
        // matches the SIDEBAR NAV BUTTON's own `:style="selectedFeature ===
        // '{$moduleKey}' ? ..."` binding, which renders BEFORE the actual
        // detail panel in DOM order — so $start landed on the ~900-byte
        // sidebar button snippet instead of the real content, and the real
        // permission rows fell entirely outside the extracted window. The
        // `x-show="` prefix is unique to the detail panel's own opening tag
        // (the sidebar button uses `:style=`, never `x-show=`), so anchoring
        // on the full attribute — not just the expression inside it — picks
        // the right occurrence deterministically.
        $needle = "x-show=\"selectedFeature === '{$moduleKey}'\"";
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, "module block for '{$moduleKey}' not found in the rendered page");
        $nextStart = strpos($html, "x-show=\"selectedFeature === '", $start + strlen($needle));
        $section = $nextStart !== false ? substr($html, $start, $nextStart - $start) : substr($html, $start);

        preg_match_all(
            '/<p class="text-sm font-medium"[^>]*>([^<]*)<\/p>\s*<p class="text-xs mt-0\.5"[^>]*>([^<]*)<\/p>/',
            $section,
            $matches,
            PREG_SET_ORDER
        );

        return array_map(fn ($m) => [trim($m[1]), trim($m[2])], $matches);
    }

    public function test_a_crud_slot_permission_with_a_configured_label_shows_that_label_not_the_generic_word(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        $this->makePermissions();

        $response = $this->actingAs($admin)->get(route('corex.role-manager'))->assertOk();
        $rows = $this->extractRows($response->getContent(), 'zzz_test_widget');

        $titles = array_column($rows, 0);
        $this->assertContains('Forge A New Widget', $titles, 'the configured label must be the title');
        $this->assertNotContains('Create', $titles, 'the bare generic word must not stand in for a configured label');
    }

    public function test_an_other_action_permission_with_a_configured_label_shows_that_label_as_the_title_not_a_subtitle(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        $this->makePermissions();

        $response = $this->actingAs($admin)->get(route('corex.role-manager'))->assertOk();
        $rows = $this->extractRows($response->getContent(), 'zzz_test_widget');

        $match = array_values(array_filter($rows, fn ($r) => $r[0] === 'Ping The Widget'));
        $this->assertNotEmpty($match, 'the configured label must be the TITLE (first column), not left in the subtitle');
        // Pre-fix, this permission would have rendered title="Custom Ping"
        // (Str::headline of the key) with the real label demoted to subtitle.
        $titles = array_column($rows, 0);
        $this->assertNotContains('Custom Ping', $titles, 'the auto-derived heading must not be the title when a real label exists');
    }

    public function test_a_permission_with_no_label_falls_back_to_the_generic_derived_title_without_rendering_blank(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        $this->makePermissions();

        $response = $this->actingAs($admin)->get(route('corex.role-manager'))->assertOk();

        // Str::headline('no_label_edge_case') => "No Label Edge Case" — the
        // fallback the conductor asked for: generic copy for a key with no
        // label, not a blank row.
        $response->assertSee('No Label Edge Case');
    }

    /** The already-correct access/.view row (real label as title) must be untouched by this fix. */
    public function test_access_type_permissions_are_unaffected(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        $this->makePermissions();

        $response = $this->actingAs($admin)->get(route('corex.role-manager'))->assertOk();

        $response->assertSee('View Zzz Test Widgets');
        $response->assertSee('Menu / section visibility');
    }
}
