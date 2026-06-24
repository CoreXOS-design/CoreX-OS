<?php

namespace Tests\Feature\Tours;

use App\Support\Tours\TourRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AT-41 — guards the guided-tour catalogue (core() + every defs/*.php pack).
 *
 * The tour engine SILENTLY skips any step whose CSS selector is not on the
 * page, so a typo or a removed anchor degrades a tour without any error. This
 * test makes that failure loud: it asserts every tour is well-formed, bound to
 * a real route, free of duplicate keys, and — for the data-tour anchors the
 * AT-41 packs use — that the anchor actually exists in a Blade view.
 *
 * Pure static assertions: no DB, so it runs fast and is immune to the
 * test-DB infra baseline.
 */
class TourRegistryIntegrityTest extends TestCase
{
    public function test_every_tour_is_well_formed_and_route_bound(): void
    {
        foreach (TourRegistry::all() as $key => $tour) {
            $this->assertSame($key, $tour['key'] ?? null, "Tour '$key' key mismatch");
            $this->assertNotEmpty($tour['title'] ?? null, "Tour '$key' has no title");
            $this->assertNotEmpty($tour['route'] ?? null, "Tour '$key' has no route");
            $this->assertTrue(
                Route::has($tour['route']),
                "Tour '$key' binds to unknown route '{$tour['route']}'"
            );
            $this->assertNotEmpty($tour['steps'] ?? [], "Tour '$key' has no steps");
            foreach ($tour['steps'] as $i => $step) {
                $this->assertNotEmpty($step['element'] ?? null, "Tour '$key' step $i has no element");
                $this->assertNotEmpty($step['title'] ?? null, "Tour '$key' step $i has no title");
                $this->assertNotEmpty($step['body'] ?? null, "Tour '$key' step $i has no body");
            }
        }
    }

    public function test_defs_keys_are_unique_and_do_not_clobber_core(): void
    {
        $coreRef = new \ReflectionMethod(TourRegistry::class, 'core');
        $coreRef->setAccessible(true);
        $coreKeys = array_keys($coreRef->invoke(null));

        $seen = [];
        foreach (glob(app_path('Support/Tours/defs/*.php')) as $file) {
            foreach (array_keys(require $file) as $key) {
                $this->assertArrayNotHasKey($key, $seen, "Duplicate tour key '$key' (".basename($file).")");
                $this->assertNotContains($key, $coreKeys, "Defs key '$key' clobbers a core tour");
                $seen[$key] = basename($file);
            }
        }
    }

    public function test_every_data_tour_anchor_exists_in_a_view(): void
    {
        $blob = '';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $blob .= file_get_contents((string) $file);
            }
        }

        foreach (TourRegistry::all() as $key => $tour) {
            foreach ($tour['steps'] as $step) {
                if (preg_match('/^\[data-tour="([^"]+)"\]$/', $step['element'], $m)) {
                    $this->assertStringContainsString(
                        'data-tour="'.$m[1].'"',
                        $blob,
                        "Tour '$key' anchors [data-tour=\"{$m[1]}\"] but no view declares it"
                    );
                }
            }
        }
    }
}
