<?php

namespace App\Services\Docuperfect\Compiler\Reference;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Golden\CompiledTemplateGoldenHarness;
use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Rendering\CdsGoldenRenderProbe;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderer;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\PartyExpr;

/**
 * E-Sign Document Compiler — WS5 (reference proofs).
 *
 * Runs the FULL chain on a hand-compiled reference CDS (§8) and returns a {@see ReferenceProof}:
 *
 *   1. LINTER — with the LIVE {@see CdsRenderParityVerifier}, so L6 render-parity is truly
 *      proven (not PENDING) and legal-class L7 is evaluated.
 *   2. GOLDEN HARNESS — with the LIVE {@see CdsGoldenRenderProbe}, so every party combination
 *      is rendered and certified end-to-end.
 *   3. SIDE-BY-SIDE — renders a representative combination through the ONE render-only runtime
 *      and checks the compiled render reproduces the document's signature topology and its
 *      essential legal phrases (content-drop detector), with legitimate differences named.
 *
 * Pure over its injected dependencies: the dictionary is reached only through the resolver
 * contract; the renderer/verifier/probe are WS2's real services.
 */
final class ReferenceProofRunner
{
    public function __construct(
        private readonly CompiledTemplateLinter $linter = new CompiledTemplateLinter(),
        private readonly CompiledTemplateGoldenHarness $harness = new CompiledTemplateGoldenHarness(),
        private readonly CdsRenderer $renderer = new CdsRenderer(),
        private readonly CdsRenderParityVerifier $parity = new CdsRenderParityVerifier(),
        private readonly CdsGoldenRenderProbe $probe = new CdsGoldenRenderProbe(),
    ) {
    }

    /**
     * @param array<string,mixed> $structure       the hand-compiled CDS structure
     * @param string[]            $essentialPhrases legal phrases that MUST survive into the render
     */
    public function run(array $structure, DataDictionaryResolver $dictionary, array $essentialPhrases): ReferenceProof
    {
        $cds = Cds::fromArray($structure);

        $lint = $this->linter->lint($cds, $dictionary, $this->parity);
        $golden = $this->harness->certify($cds, $dictionary, $this->probe);
        $sideBySide = $this->sideBySide($cds, $essentialPhrases);

        return new ReferenceProof(
            family: $cds->family,
            legalClass: $cds->legalClass->value,
            lint: $lint,
            golden: $golden,
            sideBySide: $sideBySide,
        );
    }

    /**
     * @param string[] $essentialPhrases
     */
    private function sideBySide(Cds $cds, array $essentialPhrases): SideBySideVerdict
    {
        // Declared signers (role bases) — every declared party signs.
        $declared = array_values(array_unique(array_map(
            static fn ($p): string => PartyExpr::roleBase($p->key),
            $cds->parties,
        )));

        // Render a representative combination: one present instance per declared party.
        $presentInstances = array_map(static fn ($p): string => $p->key, $cds->parties);
        $surface = $this->renderer->renderDocument($cds, DeliveryMode::WebEsign, $presentInstances);

        // Rendered signers = the role bases that actually got a signing surface.
        $rendered = [];
        foreach (array_keys($surface->anchorMap()) as $instanceKey) {
            $rendered[PartyExpr::roleBase($instanceKey)] = true;
        }
        $renderedSigners = array_keys($rendered);

        // Essential-phrase coverage over the normalised rendered text.
        $renderedText = $this->normalise(implode(' ', array_map(
            static fn (array $b): string => $b['text'],
            $surface->fingerprint(),
        )));
        $covered = [];
        $missing = [];
        foreach ($essentialPhrases as $phrase) {
            if (str_contains($renderedText, $this->normalise($phrase))) {
                $covered[] = $phrase;
            } else {
                $missing[] = $phrase;
            }
        }

        return new SideBySideVerdict(
            declaredSigners: $declared,
            renderedSigners: $renderedSigners,
            coveredPhrases: $covered,
            missingPhrases: $missing,
            notedDifferences: [
                'Compiled per-instance signature expansion (one surface per present role instance) replaces the legacy RoleBlockExpansionService LCA guessing — a fix, not a defect (§9).',
                'Presentation classes differ web vs print by design; L6 parity compares the presentation-independent structural fingerprint, which is identical.',
            ],
        );
    }

    private function normalise(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
