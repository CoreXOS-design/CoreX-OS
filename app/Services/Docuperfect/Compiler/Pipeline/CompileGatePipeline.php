<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Pipeline;

use App\Models\Docuperfect\CompiledTemplate;
use App\Models\User;
use App\Services\Docuperfect\Compiler\Contracts\CompilePipeline;
use App\Services\Docuperfect\Compiler\Golden\CompiledTemplateGoldenHarness;
use App\Services\Docuperfect\Compiler\Golden\GoldenReport;
use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintReport;
use App\Services\Docuperfect\Compiler\Rendering\CdsGoldenRenderProbe;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\EloquentDataDictionaryResolver;
use RuntimeException;

/**
 * AT-177 / WS4-E — the compile orchestrator (spec §3 steps 5–6). Runs a draft through the gate
 * and publishes it, wiring every sibling lane together with L6 and the golden render tier LIVE:
 *   - lint()    → WS1 linter over the CDS, WS0 Eloquent dictionary resolver, WS2 parity verifier.
 *   - certify() → WS3 golden harness with the WS2 render probe.
 *   - publish() → gated on publishable() AND certifiable(), then WS0 publishAsNewVersion() freezes
 *                 an immutable hashed version and stamps the lint report + parity hashes.
 */
final class CompileGatePipeline implements CompilePipeline
{
    public function __construct(
        private readonly CompiledTemplateLinter $linter = new CompiledTemplateLinter(),
        private readonly CompiledTemplateGoldenHarness $harness = new CompiledTemplateGoldenHarness(),
    ) {
    }

    public function lint(CompiledTemplate $draft): LintReport
    {
        return $this->linter->lint(
            $draft->structure ?? [],
            new EloquentDataDictionaryResolver($draft->agency_id),
            null,
            new LinterContext(new CdsRenderParityVerifier()),
        );
    }

    public function certify(CompiledTemplate $draft): GoldenReport
    {
        return $this->harness->certify(
            $draft->cds(),
            new EloquentDataDictionaryResolver($draft->agency_id),
            new CdsGoldenRenderProbe(),
        );
    }

    public function publish(CompiledTemplate $draft, ?User $publisher = null): CompiledTemplate
    {
        $lint = $this->lint($draft);
        if (! $lint->publishable()) {
            $first = $lint->firstBlocking();
            throw new RuntimeException(
                'Cannot publish: the template does not pass the linter'
                . ($first !== null ? " ({$first->rule} @{$first->target}: {$first->message})" : '.'),
            );
        }

        $certify = $this->certify($draft);
        if (! $certify->certifiable()) {
            throw new RuntimeException('Cannot publish: the golden harness did not certify every party combination.');
        }

        $draft->lint_status = CompiledTemplate::LINT_PASSED;
        $draft->lint_report = $lint->toArray();
        $draft->render_parity = $this->parityHashes($draft);

        return $draft->publishAsNewVersion($publisher);
    }

    /**
     * Stamp the proven web/PDF parity hashes for the primary combination (all declared parties,
     * one instance each) — the §2 `render_parity` proof metadata on the published artifact.
     *
     * @return array{web_hash:string,pdf_hash:string}
     */
    private function parityHashes(CompiledTemplate $draft): array
    {
        $parties = $draft->structure['parties'] ?? [];
        $primary = array_map(static fn (array $p): string => ($p['key'] ?? 'party') . '_1', $parties);
        $result = (new CdsRenderParityVerifier())->verify($draft->structure ?? [], $primary);

        return ['web_hash' => $result->webHash, 'pdf_hash' => $result->pdfHash];
    }
}
