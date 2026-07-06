<?php

namespace App\Services\Docuperfect\Compiler\Support;

use App\Services\Docuperfect\Compiler\Contracts\GoldenRenderObservation;
use App\Services\Docuperfect\Compiler\Contracts\GoldenRenderProbe;
use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\PartyExpr;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness) test/dev double.
 *
 * A {@see GoldenRenderProbe} that FAITHFULLY reflects the CDS structure — it reports exactly
 * the bound fields and anchors that a correct renderer would place for a combination, with
 * parity holding and a deterministic body hash. This lets the golden harness be exercised
 * end-to-end (structural + render tiers) before the real WS2 render-only runtime + Puppeteer
 * parity service exist. NOT a production probe — WS2 (cc2) ships that.
 *
 * Factories produce deliberately-broken variants (drops a field, breaks parity) so the
 * harness's render tier can be proven to fail block-addressed.
 *
 * @see \App\Services\Docuperfect\Compiler\Contracts\GoldenRenderProbe
 */
final class CallbackGoldenRenderProbe implements GoldenRenderProbe
{
    /** @var callable(GoldenRenderObservation, Cds, string[], array<string,mixed>): GoldenRenderObservation */
    private $transform;

    /**
     * @param (callable(GoldenRenderObservation, Cds, string[], array<string,mixed>): GoldenRenderObservation)|null $transform
     *        post-processes the faithful observation (for broken variants); identity if null.
     */
    public function __construct(?callable $transform = null)
    {
        $this->transform = $transform ?? static fn (GoldenRenderObservation $o): GoldenRenderObservation => $o;
    }

    /** A probe that renders every combination correctly. */
    public static function faithful(): self
    {
        return new self();
    }

    /** A probe that faithfully renders everything EXCEPT it drops one field from the body. */
    public static function dropsField(string $fieldId): self
    {
        return new self(static function (GoldenRenderObservation $o) use ($fieldId): GoldenRenderObservation {
            return new GoldenRenderObservation(
                renderedFieldIds: array_values(array_filter($o->renderedFieldIds, static fn (string $f): bool => $f !== $fieldId)),
                placedAnchors: $o->placedAnchors,
                webPdfParityHolds: $o->webPdfParityHolds,
                bodyHash: $o->bodyHash,
            );
        });
    }

    /** A probe whose web and PDF renders diverge. */
    public static function parityBroken(string $reason = 'anchor coordinates differ on PDF'): self
    {
        return new self(static function (GoldenRenderObservation $o) use ($reason): GoldenRenderObservation {
            return new GoldenRenderObservation(
                renderedFieldIds: $o->renderedFieldIds,
                placedAnchors: $o->placedAnchors,
                webPdfParityHolds: false,
                bodyHash: $o->bodyHash,
                differences: [$reason],
            );
        });
    }

    public function observe(Cds $cds, array $presentParties, array $fieldValues = []): GoldenRenderObservation
    {
        $scenario = [
            'present_parties' => $presentParties,
            'party_counts' => $this->countByRole($presentParties),
            'field_values' => $fieldValues,
        ];

        $renderedFieldIds = [];
        $placedAnchors = [];

        foreach ($cds->blocks as $block) {
            if (!$block->condition->evaluate($scenario)) {
                continue;
            }
            foreach ($block->fields as $field) {
                if ($field->isBound()) {
                    $renderedFieldIds[] = $field->fieldId;
                }
            }
            foreach ($block->anchors as $anchor) {
                if ($anchor->kind->isSigningSurface() && $anchor->partyKey !== '') {
                    $placedAnchors[$anchor->partyKey][] = $anchor->anchorId;
                }
            }
        }

        $faithful = new GoldenRenderObservation(
            renderedFieldIds: array_values(array_unique($renderedFieldIds)),
            placedAnchors: $placedAnchors,
            webPdfParityHolds: true,
            bodyHash: substr(hash('sha256', (string) json_encode([$cds->contentHash(), $this->sorted($presentParties), $fieldValues])), 0, 32),
        );

        return ($this->transform)($faithful, $cds, $presentParties, $fieldValues);
    }

    /** @param string[] $presentParties @return array<string,int> */
    private function countByRole(array $presentParties): array
    {
        $counts = [];
        foreach ($presentParties as $instance) {
            $base = PartyExpr::roleBase($instance);
            $counts[$base] = ($counts[$base] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param string[] $a @return string[] */
    private function sorted(array $a): array
    {
        sort($a);

        return $a;
    }
}
