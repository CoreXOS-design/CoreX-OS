<?php

namespace App\Services\Docuperfect\Compiler\Contracts;

use App\Support\Docuperfect\Cds\Cds;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness) → WS2 seam.
 *
 * The golden harness (§7) drives a full signing of each party-combination through the
 * render-only runtime and asserts on the rendered body. That rendering is the WS2 render-only
 * runtime + Puppeteer/headless-Chromium parity service (§6 / §12-decision-3) — NOT owned by
 * this lane. So the harness fronts the render tier with this pluggable probe (the same
 * dependency-inversion pattern WS1's L6 uses with `RenderParityVerifier`):
 *
 *   - probe PRESENT (WS2 wired) → the harness renders every combination and asserts fields
 *     populate, anchors are placed for every present party, web↔PDF parity holds, and the
 *     completed-body hash is stable.
 *   - probe ABSENT (WS2 not yet landed) → the render tier is reported PENDING and BLOCKS
 *     certification. Never a silent green.
 *
 * INTEGRATION (AT-177): consumer-owned interface. WS2 (cc2) implements it atop the same
 * `render(CDS, party, mode)` primitive (§6) that backs the L6 `RenderParityVerifier` — this is
 * just the harness's observation view of one signed combination.
 */
interface GoldenRenderProbe
{
    /**
     * Render + sign the given party-combination and report what the completed body contains.
     *
     * @param string[]            $presentParties the party INSTANCE keys present in this
     *                                            combination (e.g. ["seller_1","seller_2","agent"])
     * @param array<string,mixed> $fieldValues    field_id → value assigned in this scenario, so
     *                                            data-driven conditionals (field_equals /
     *                                            field_truthy) render the correct branch
     */
    public function observe(Cds $cds, array $presentParties, array $fieldValues = []): GoldenRenderObservation;
}
