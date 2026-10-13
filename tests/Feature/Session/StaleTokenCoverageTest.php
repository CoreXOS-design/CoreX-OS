<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use Tests\TestCase;

/**
 * 13 Oct 2026 (Sherry demo): a stale CSRF token silently killed photo and observation saves on an open inspection page. The fix
 * lives in ONE place - the connection guard every staff page already mounts (behaviour: tests/js/connection-guard.mjs) - so
 * "which screens are covered" is answered structurally here: every full rental working page extends a layout that mounts the
 * guard, and the guard carries the three save paths (fetch, XMLHttpRequest, native form). A rental page that stood outside the
 * guarded layouts would fail this test by name.
 */
final class StaleTokenCoverageTest extends TestCase
{
    private const GUARDED_LAYOUTS = ["layouts.corex", "layouts.corex-app"];

    public function test_both_staff_layouts_mount_the_guard(): void
    {
        foreach (['corex', 'corex-app'] as $layout) {
            $this->assertStringContainsString("layouts.partials._session-guard", (string) file_get_contents(resource_path("views/layouts/{$layout}.blade.php")), $layout);
        }
    }

    public function test_every_full_rental_working_page_extends_a_guarded_layout(): void
    {
        $dirs = ['rental-inspections', 'rental-inventories', 'rental-fault-reports', 'rental-work-orders', 'rental-job-cards', 'rental-completion', 'rental-notices', 'rentals', 'rental-applications', 'rental-signatures'];
        $checked = 0;
        $outside = [];
        foreach ($dirs as $dir) {
            $path = resource_path('views/corex/' . $dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (! preg_match("/@extends\\(\\s*['\"]([^'\"]+)['\"]/", $source, $m)) {
                    continue; // a partial, a print list or a PDF - not a page an agent saves from
                }
                $checked++;
                if (! in_array($m[1], self::GUARDED_LAYOUTS, true) && ! str_starts_with($m[1], 'layouts.corex')) {
                    $outside[] = str_replace(resource_path('views/'), '', $file->getPathname()) . ' extends ' . $m[1];
                }
            }
        }

        $this->assertGreaterThan(10, $checked, 'the scan found the rental pages');
        $this->assertSame([], $outside, 'every rental working page must sit on a layout that mounts the connection guard');

        $this->assertStringContainsString("@extends('layouts.corex')", (string) file_get_contents(resource_path('views/corex/properties/show.blade.php')), 'the property page (inspections, items, photos) is guarded');
    }

    public function test_the_guard_carries_all_three_save_paths_and_the_retry_rule(): void
    {
        $js = (string) file_get_contents(public_path('js/corex-connection-guard.js'));

        $this->assertStringContainsString('_sendGuarded', $js, 'fetch: live token + one retry on 419');
        $this->assertStringContainsString('XMLHttpRequest', $js, 'XHR (photo uploads): token refreshed just before the send');
        $this->assertStringContainsString('_syncFormToken', $js, 'native forms: hidden _token synced on submit');
        $this->assertStringContainsString('visibilitychange', $js, 'a tab coming back to the foreground refreshes its token');
        $this->assertStringContainsString('X-CSRF-TOKEN', $js);
    }
}
