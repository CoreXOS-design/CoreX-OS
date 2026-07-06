<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Contracts\GoldenRenderObservation;
use App\Services\Docuperfect\Compiler\Contracts\GoldenRenderProbe;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;

/**
 * AT-177 / WS2 — the production {@see GoldenRenderProbe} (the WS3↔WS2 seam cc3's golden
 * harness delegates to WS2, exactly as L6 delegates to the {@see CdsRenderParityVerifier}).
 *
 * Plugging this in flips the harness's render tier from PENDING → a live per-combination
 * observation: it renders the party-combination through the ONE render-only runtime and
 * reports which fields populated, which anchors were placed for which present signer,
 * whether web↔PDF parity holds, and a stable body hash (drift detector). Built atop the same
 * `render(CDS, party, mode)` primitive that backs L6 — this is just the harness's observation
 * view of one signed combination.
 */
final class CdsGoldenRenderProbe implements GoldenRenderProbe
{
    public function __construct(
        private readonly CdsRenderer $renderer = new CdsRenderer(),
    ) {
    }

    public function observe(Cds $cds, array $presentParties, array $fieldValues = []): GoldenRenderObservation
    {
        $web = $this->renderer->renderDocument($cds, DeliveryMode::WebEsign, $presentParties, $fieldValues);
        $print = $this->renderer->renderDocument($cds, DeliveryMode::PdfWetInk, $presentParties, $fieldValues);

        $differences = CdsRenderParityVerifier::diffFingerprints($web->fingerprint(), $print->fingerprint());

        return new GoldenRenderObservation(
            renderedFieldIds: $print->fieldIds(),
            placedAnchors: $print->anchorMap(),
            webPdfParityHolds: $differences === [],
            // Stable, presentation-independent hash of the completed body — drift detector.
            bodyHash: $print->fingerprintHash(),
            differences: $differences,
        );
    }
}
