<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AT-456 §A.3 — "fix the class, not the instance". Any file under app/ that
 * talks to the Anthropic Messages API must go through AiSpendGuard, so a
 * future AI feature cannot silently spend past a cut-out.
 *
 * If this fails: call app(AiSpendGuard::class)->assertAllowed($agencyId)
 * before the HTTP call, passing the same agency the cost is recorded under.
 */
class AiSpendGuardCoverageTest extends TestCase
{
    /** Markers of a direct Messages API call. */
    private const CALL_MARKERS = ['v1/messages', 'api.anthropic.com'];

    public function test_every_anthropic_caller_goes_through_the_spend_guard(): void
    {
        $appDir   = dirname(__DIR__, 2) . '/app';
        $callers  = [];
        $unguarded = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $calls  = false;
            foreach (self::CALL_MARKERS as $marker) {
                if (str_contains($source, $marker)) {
                    $calls = true;
                    break;
                }
            }
            if (!$calls) {
                continue;
            }

            $relative  = substr($file->getPathname(), strlen($appDir) + 1);
            $callers[] = $relative;
            if (!str_contains($source, 'AiSpendGuard')) {
                $unguarded[] = $relative;
            }
        }

        // Guards the guard: if the scan ever finds nothing, the markers are stale.
        $this->assertContains('Services/AI/AnthropicGateway.php', $callers, 'Scan found no Anthropic callers — update CALL_MARKERS.');

        $this->assertSame(
            [],
            $unguarded,
            "These files call Anthropic without AiSpendGuard (AT-456 §A.3):\n  " . implode("\n  ", $unguarded)
        );
    }
}
