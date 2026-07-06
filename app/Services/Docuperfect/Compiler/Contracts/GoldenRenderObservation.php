<?php

namespace App\Services\Docuperfect\Compiler\Contracts;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness).
 *
 * What the golden harness OBSERVES after WS2's render-only runtime signs one party
 * combination (§7: "asserts on the rendered document body: all bound fields populate, every
 * anchor is placed for the right party, web/PDF parity holds, the completed document hash is
 * stable"). Immutable. Returned by a {@see GoldenRenderProbe}.
 *
 *  - $renderedFieldIds : the field_ids whose fill-points actually appear in the rendered body
 *                        for this combination (so the harness can assert the expected bound
 *                        fields populated, none dropped).
 *  - $placedAnchors    : partyKey → anchor_ids placed in the rendered body (so the harness can
 *                        assert every present signer has a place to sign).
 *  - $webPdfParityHolds: the web↔PDF structural diff is empty for this combination (§7/L6).
 *  - $bodyHash         : a stable hash of the completed rendered body (drift detector).
 *  - $differences      : block-addressed parity/render diff notes when something is off.
 */
final class GoldenRenderObservation
{
    /**
     * @param string[]                    $renderedFieldIds
     * @param array<string,array<int,string>> $placedAnchors partyKey => anchor_ids
     * @param string[]                    $differences
     */
    public function __construct(
        public readonly array $renderedFieldIds,
        public readonly array $placedAnchors,
        public readonly bool $webPdfParityHolds,
        public readonly string $bodyHash,
        public readonly array $differences = [],
    ) {
    }

    /** Did an anchor get placed for the given party key (matched by exact key)? */
    public function hasAnchorForParty(string $partyKey): bool
    {
        return !empty($this->placedAnchors[$partyKey] ?? []);
    }

    public function rendersField(string $fieldId): bool
    {
        return in_array($fieldId, $this->renderedFieldIds, true);
    }
}
