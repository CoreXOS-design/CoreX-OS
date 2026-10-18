<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AT-456 §A.3 — "fix the class, not the instance". Any file under app/ that
 * talks to a PAID AI API must go through AiSpendGuard, so a future AI feature
 * cannot silently spend past a cut-out.
 *
 * Paid AI surfaces scanned for:
 *   - the Anthropic Messages API (direct),
 *   - OpenAI (chat/completions/embeddings — removed from CoreX 2026-07-26, so
 *     any reappearance is flagged; embeddings are now the free self-hosted
 *     hf-ai /embed),
 *   - the hf-ai service's /chat, which is NOT a local model: it calls Anthropic
 *     server-side (/opt/hf-ai/app.py), so it costs money while looking like a
 *     harmless localhost call. (hf-ai /embed and /transcribe are local and free.)
 *
 * If this fails: call app(AiSpendGuard::class)->assertAllowed($agencyId)
 * before the HTTP call, passing the same agency the cost is recorded under,
 * and record the spend through AiUsageRecorder.
 */
class AiSpendGuardCoverageTest extends TestCase
{
    /** Markers of a direct paid-AI call. */
    private const CALL_MARKERS = [
        'v1/messages', 'api.anthropic.com',      // Anthropic
        'api.openai.com', 'v1/embeddings',       // OpenAI
        '3100/chat',                             // hf-ai /chat (Anthropic behind localhost)
    ];

    public function test_every_paid_ai_caller_goes_through_the_spend_guard(): void
    {
        $appDir = dirname(__DIR__, 2) . '/app';

        $scan = $this->scan($appDir, self::CALL_MARKERS);

        // Guards the guard: if the scan ever finds nothing, the markers are stale.
        $this->assertContains('Services/AI/AnthropicGateway.php', $scan['callers'], 'Scan found no Anthropic callers — update CALL_MARKERS.');
        $this->assertContains('Http/Controllers/Internal/AiChatProxyController.php', $scan['callers'], 'Scan no longer sees the hf-ai /chat proxy — update CALL_MARKERS.');

        $this->assertSame(
            [],
            $scan['unguarded'],
            "These files call a paid AI API without AiSpendGuard (AT-456 §A.3):\n  " . implode("\n  ", $scan['unguarded'])
        );
    }

    public function test_the_scanner_flags_an_unguarded_openai_caller_and_accepts_a_guarded_one(): void
    {
        $dir = sys_get_temp_dir() . '/ai-guard-scan-' . uniqid();
        mkdir($dir . '/sub', 0777, true);

        try {
            file_put_contents($dir . '/Embedder.php', "<?php // Http::post('https://api.openai.com/v1/embeddings', []);");
            file_put_contents($dir . '/sub/Embeddings2.php', "<?php // Http::post(\$base . '/v1/embeddings', []);");
            file_put_contents($dir . '/Proxy.php', "<?php Http::post('http://127.0.0.1:3100/chat', []);");
            file_put_contents($dir . '/Safe.php', "<?php app(AiSpendGuard::class)->assertAllowed(); Http::post('https://api.openai.com/v1/embeddings', []);");
            file_put_contents($dir . '/Unrelated.php', '<?php echo "no ai here";');

            $scan = $this->scan($dir, self::CALL_MARKERS);

            $this->assertEqualsCanonicalizing(['Embedder.php', 'sub/Embeddings2.php', 'Proxy.php', 'Safe.php'], $scan['callers']);
            $this->assertEqualsCanonicalizing(['Embedder.php', 'sub/Embeddings2.php', 'Proxy.php'], $scan['unguarded']);
        } finally {
            foreach (['Embedder.php', 'sub/Embeddings2.php', 'Proxy.php', 'Safe.php', 'Unrelated.php'] as $f) {
                @unlink($dir . '/' . $f);
            }
            @rmdir($dir . '/sub');
            @rmdir($dir);
        }
    }

    /**
     * @param  string[] $markers
     * @return array{callers: string[], unguarded: string[]}
     */
    private function scan(string $dir, array $markers): array
    {
        $callers   = [];
        $unguarded = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $calls  = false;
            foreach ($markers as $marker) {
                if (str_contains($source, $marker)) {
                    $calls = true;
                    break;
                }
            }
            if (!$calls) {
                continue;
            }

            $relative  = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $callers[] = $relative;
            if (!str_contains($source, 'AiSpendGuard')) {
                $unguarded[] = $relative;
            }
        }

        return ['callers' => $callers, 'unguarded' => $unguarded];
    }
}
