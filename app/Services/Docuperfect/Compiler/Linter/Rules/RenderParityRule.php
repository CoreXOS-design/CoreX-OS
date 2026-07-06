<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\Support\PartyCombinationEnumerator;
use App\Support\Docuperfect\Cds\Cds;

/**
 * L6 — Web + PDF render-parity diff (§4).
 *
 * "Render every party-combination in web and PDF; a structural diff must pass." The
 * guarantee that the three delivery modes never diverge — proven at compile, not hoped for
 * at runtime.
 *
 * L6 is the only rule that must actually RENDER: the WS2 render-only runtime + the
 * Puppeteer/headless-Chromium PDF engine (§12-decision-3), NOT built in this lane. So it
 * fronts a pluggable {@see \App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier}:
 *
 *   - verifier ABSENT (WS2 not yet wired) → a single PENDING finding. PENDING is not a
 *     pass; unproven parity is NOT publishable. The honest state tonight, never a silent
 *     green.
 *   - verifier PRESENT → enumerate every party-combination and fail on the first structural
 *     mismatch (block-addressed via the verifier's differences).
 */
final class RenderParityRule implements LintRule
{
    public function __construct(
        private readonly PartyCombinationEnumerator $enumerator = new PartyCombinationEnumerator(),
    ) {
    }

    public function code(): string
    {
        return 'L6';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        $verifier = $context->renderParityVerifier;

        if ($verifier === null) {
            return [LintFinding::pending(
                'L6',
                '',
                'render_parity_unverified',
                'Web↔PDF render parity is unproven: no render-parity verifier is wired (the WS2 render-only runtime + Puppeteer PDF engine are not yet available). The template cannot be published until parity is proven.',
            )];
        }

        $findings = [];
        $structure = $cds->toArray();
        $enum = $this->enumerator->enumerate($cds->parties, $context->maxInstancesPerParty);

        foreach ($enum['combos'] as $combo) {
            $result = $verifier->verify($structure, $combo['present_parties']);
            if (!$result->matched) {
                $findings[] = LintFinding::error(
                    'L6',
                    '',
                    'render_parity_mismatch',
                    sprintf('Web and PDF renders diverge for party combination [%s]: %s', implode(', ', $combo['present_parties']), $result->differences === [] ? 'structural diff non-empty' : implode('; ', $result->differences)),
                    ['active_parties' => $combo['present_parties'], 'web_hash' => $result->webHash, 'pdf_hash' => $result->pdfHash, 'differences' => $result->differences],
                );
            }
        }

        if ($findings === []) {
            $findings[] = LintFinding::pass('L6', 'Web and PDF renders are structurally identical across all party combinations.');
        }

        return $findings;
    }
}
