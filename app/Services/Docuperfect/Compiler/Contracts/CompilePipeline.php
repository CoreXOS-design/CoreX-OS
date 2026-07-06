<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Contracts;

use App\Models\Docuperfect\CompiledTemplate;
use App\Models\User;
use App\Services\Docuperfect\Compiler\Golden\GoldenReport;
use App\Services\Docuperfect\Compiler\Linter\LintReport;

/**
 * AT-177 / WS4-E → WS4-S seam (spec §3 steps 5–6). The orchestrator that runs a compile draft
 * through the gate and publishes it. It wires the sibling lanes together:
 *   - lint()    → WS1 linter over the CDS, with the WS0 Eloquent dictionary resolver + the WS2
 *                 render-parity verifier (so L6 is LIVE, not pending).
 *   - certify() → WS3 golden harness with the WS2 render probe (render tier LIVE).
 *   - publish() → gated on lint().publishable() AND certify().certifiable(); then WS0's
 *                 CompiledTemplate::publishAsNewVersion() freezes an immutable hashed version
 *                 and stamps the lint report + parity hashes. NEVER publishes an ungated draft.
 *
 * INTEGRATION (AT-177): WS4-E (cc2) implements; the Studio (WS4-S, cc1) calls lint()/certify()
 * for the live gate panel and publish() for the publish button.
 */
interface CompilePipeline
{
    /** Lint the draft's CDS (L1–L7, L6 live). Blocking findings are block-addressed. */
    public function lint(CompiledTemplate $draft): LintReport;

    /** Certify every party combination through the render-only runtime (golden harness). */
    public function certify(CompiledTemplate $draft): GoldenReport;

    /**
     * Publish the draft as an immutable hashed version — only if it lints publishable AND
     * certifies. Returns the published CompiledTemplate.
     *
     * @throws \RuntimeException with the blocking reason if the draft is not gate-clean.
     */
    public function publish(CompiledTemplate $draft, ?User $publisher = null): CompiledTemplate;
}
