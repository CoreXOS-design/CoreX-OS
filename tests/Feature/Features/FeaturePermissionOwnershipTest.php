<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Services\Features\AgencyFeatureService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Features page spec §4 — permission ownership. A permission owned by a feature that is OFF is blocked
 * (routes 404, hidden in Role Manager). This structural guard makes it impossible to add a permission
 * module and forget the decision: every module must be OWNED by a feature in config/corex-features.php
 * or be listed below as always available, with the reason. Reads config only — no database.
 */
final class FeaturePermissionOwnershipTest extends TestCase
{
    /**
     * Permission modules no feature owns — available whatever the agency switches off. Reasons are
     * deliberate: core pillars/governance, or areas the registry has no feature for yet.
     */
    private const ALWAYS_AVAILABLE = [
        // Core pillars and the shell (registry rows marked core — never switched off)
        'dashboard' => 'core', 'properties' => 'core', 'contacts' => 'core', 'deals' => 'core (deal register)',
        'my_portal' => 'core', 'settings' => 'core', 'roles' => 'core (Role Manager)', 'sidebar' => 'core',
        'command_center' => 'core (home)', 'command_center_calendar' => 'core (home)', 'command_center_tasks' => 'core (home)',
        'viewing_feedback_edit' => 'core (calendar)', 'users' => 'core (people)', 'branches' => 'core (tenant structure)',
        'billing' => 'core (tenant)', 'security' => 'core (tenant)', 'backups' => 'core (tenant)', 'monitoring' => 'core (tenant)',
        'diagnostics' => 'core (tenant)', 'soft_deletes' => 'core (archive register)', 'remote_access' => 'core (support access)',
        'data_scope' => 'core (visibility scoping)', 'franchise_admin' => 'core (platform admin)',
        // Pillar-adjacent modules with no registry feature of their own
        'deals_v2' => 'Deal pillar (DR2)', 'dr2_unfiled_emails' => 'Deal pillar (DR2)',
        'buyer_notes' => 'Contact pillar (buyer notes on the Intelligence tab and Core Matches)', 'other_agency_stock' => 'Property pillar (imported other-agency listings; no feature of its own)',
        'buyer_pipeline' => 'Contact pillar', 'contact_governance' => 'Contact pillar', 'contact_rental_history' => 'Contact pillar',
        'client_portal' => 'client access', 'client_app' => 'client access', 'assistants' => 'people (assistant identity)',
        // Areas the registry does not model yet — always available until a feature owns them
        'agency_tracker' => 'only branch assignments + buyers report remain; the rest is owned by key',
        'buyers_report' => 'reports', 'targets' => 'performance targets',
        'agency_api' => 'website API keys', 'ppra_employment_letters' => 'PPRA admin', 'ppra_inspection_pack' => 'PPRA admin',
        'supervision' => 'compliance & supervision (not modelled)', 'finance' => 'finance engine (not modelled)',
        'sales_docs' => 'sales documents (not modelled)', 'p24' => 'P24 market intel (ambiguous owner)',
        'evaluation' => 'evaluation tool (not modelled)', 'flow_map' => 'flow map (not modelled)',
    ];

    private function ownedModules(): array
    {
        $owned = [];
        foreach (config('corex-features') as $feature => $def) {
            foreach (($def['permission_modules'] ?? []) as $m) {
                $owned[$m] = $feature;
            }
        }

        return $owned;
    }

    public function test_every_permission_module_is_owned_by_a_feature_or_explicitly_always_available(): void
    {
        $owned = $this->ownedModules();
        $modules = array_unique(array_map(fn ($p) => $p['module'] ?? $p['section'], config('corex-permissions.permissions')));

        $undecided = array_values(array_filter($modules, fn ($m) => ! isset($owned[$m]) && ! isset(self::ALWAYS_AVAILABLE[$m])));
        sort($undecided);

        $this->assertSame([], $undecided,
            'These permission modules are not owned by any feature and not listed as always available. Add them to a '
            ."feature's 'permission_modules' in config/corex-features.php (so switching it off hides them) or to "
            .'ALWAYS_AVAILABLE here with a reason: '.implode(', ', $undecided));
    }

    public function test_the_always_available_list_has_no_stale_entries(): void
    {
        $owned = $this->ownedModules();
        $modules = array_flip(array_map(fn ($p) => $p['module'] ?? $p['section'], config('corex-permissions.permissions')));

        foreach (array_keys(self::ALWAYS_AVAILABLE) as $m) {
            $this->assertArrayHasKey($m, $modules, "ALWAYS_AVAILABLE lists '{$m}' but no such permission module exists");
            $this->assertArrayNotHasKey($m, $owned, "'{$m}' is both owned by a feature and listed as always available");
        }
    }

    public function test_the_registry_validator_accepts_the_ownership_fields(): void
    {
        $this->assertSame(0, Artisan::call('corex:features:validate'));
    }

    public function test_ownership_resolves_key_over_module_and_leaves_shared_areas_available(): void
    {
        $svc = app(AgencyFeatureService::class);

        // A key listed on a feature wins over the module it sits in.
        $this->assertSame('staff-take-on', $svc->featureForPermission('manage_staff_take_on'));
        $this->assertSame('leave', $svc->featureForPermission('apply_for_leave'));
        $this->assertSame('proforma-invoices', $svc->featureForPermission('proforma.generate'));

        // Agency Tracker owns its tracker keys, but branch administration and the buyers report stay available.
        $this->assertSame('agency-tracker', $svc->featureForPermission('view_performance'));
        $this->assertNull($svc->featureForPermission('access_branch_assignments'));
        $this->assertNull($svc->featureForPermission('view_buyers_report'));

        // Whole-module ownership.
        $this->assertSame('rentals', $svc->featureForPermission('rental_inspections.view'));
        $this->assertSame('presentations', $svc->featureForPermission('access_presentations'));

        // Core pillars are never owned.
        $this->assertNull($svc->featureForPermission('access_properties'));
        $this->assertNull($svc->featureForPermission('access_role_manager'));
    }

    public function test_a_core_feature_owns_no_permissions(): void
    {
        foreach (config('corex-features') as $key => $def) {
            if (! empty($def['core'])) {
                $this->assertEmpty($def['permission_modules'] ?? [], "core feature {$key} must not own permission modules");
                $this->assertEmpty($def['permission_keys'] ?? [], "core feature {$key} must not own permission keys");
            }
        }
    }
}
