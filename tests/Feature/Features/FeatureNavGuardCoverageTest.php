<?php

namespace Tests\Feature\Features;

use Tests\TestCase;

/**
 * AC-11 (corex-feature-registry.md §12): every non-core, non-switchboard MODULE
 * feature that declares a `sidebar_section` MUST have a matching `@feature('<key>')`
 * guard in the sidebar — otherwise its Settings/onboarding toggle is a silent no-op
 * (the module stays visible when the agency switches it off). This structural test
 * is the guard the original build shipped without, which is how ~9 toggles reached
 * production doing nothing. It reads files only — no DB, so it is fast and safe.
 */
class FeatureNavGuardCoverageTest extends TestCase
{
    /** Switchboard keys are gated via their own capability controls, not a plain @feature nav wrap. */
    private const SWITCHBOARD = [
        'marketing', 'syndication-p24', 'syndication-pp',
        'core-matches', 'multi-branch', 'public-website',
    ];

    public function test_every_module_feature_with_a_sidebar_section_has_a_nav_guard(): void
    {
        $registry = config('corex-features', []);
        $sidebar  = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));

        $missing = [];
        foreach ($registry as $key => $def) {
            if (!empty($def['core'])) {
                continue;                               // core is never gated
            }
            if (in_array($key, self::SWITCHBOARD, true)) {
                continue;                               // gated by its capability control
            }
            if (empty($def['sidebar_section'])) {
                continue;                               // no nav item => nothing to guard
            }
            if (! str_contains($sidebar, "@feature('{$key}')")) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Module features with a sidebar_section but no @feature nav guard (their toggle is a silent no-op): '
                . implode(', ', $missing)
        );
    }
}
