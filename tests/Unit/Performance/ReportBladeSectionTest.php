<?php

namespace Tests\Unit\Performance;

use Tests\TestCase;

/**
 * Guards the blank-page regression (found 2026-08-06 during AT-366-D/E verification).
 *
 * cc3's report blades declared @section('content') while layouts.corex-app yields
 * @yield('corex-content'), so the report body NEVER rendered inside the layout — every
 * view (company/branch/agent) was a blank page in the browser, yet all service-level
 * unit tests were green because none renders a blade into the layout.
 *
 * This locks the contract: report blade section name == layout content yield.
 */
class ReportBladeSectionTest extends TestCase
{
    public function test_report_blades_target_the_corex_app_content_section(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/corex-app.blade.php'));
        $this->assertStringContainsString(
            "@yield('corex-content')",
            $layout,
            'corex-app main content yield changed — update this guard AND the report blades to match.'
        );

        foreach (['index', 'branch', 'agent'] as $view) {
            $path  = resource_path("views/performance/agency-report/{$view}.blade.php");
            $blade = file_get_contents($path);

            $this->assertStringContainsString(
                "@section('corex-content')",
                $blade,
                "{$view}.blade.php must @section('corex-content') so its body renders inside corex-app."
            );
            $this->assertStringNotContainsString(
                "@section('content')",
                $blade,
                "{$view}.blade.php uses @section('content') — the layout yields 'corex-content', so the body renders BLANK."
            );
        }
    }
}
