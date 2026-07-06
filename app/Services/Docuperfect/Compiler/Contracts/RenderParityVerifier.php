<?php

namespace App\Services\Docuperfect\Compiler\Contracts;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * L6 (web↔PDF render-parity, §4) is the only lint rule that requires actually
 * rendering the document in both modes. That rendering is the WS2 render-only runtime
 * (the pure `render(CDS, party, mode)` fn) + the Puppeteer/headless-Chromium PDF engine
 * ruled in §12-decision-3 — NOT built in this lane.
 *
 * So the linter fronts L6 with this pluggable verifier (dependency inversion again):
 *   - When WS2 provides an implementation, L6 renders every party-combination web+PDF
 *     and asserts the structural diff is empty, stamping the parity hashes.
 *   - When NO verifier is supplied (WS2 absent, tonight), the L6 rule emits an HONEST
 *     `PENDING` finding — never a silent pass. A template with a PENDING L6 is NOT
 *     publishable (parity is unproven), which is the correct, truthful state until the
 *     renderer lands. See {@see \App\Services\Docuperfect\Compiler\Linter\Rules\RenderParityRule}.
 *
 * INTEGRATION (AT-177): consumer-owned interface; WS2 (cc1, after DR2) implements it.
 */
interface RenderParityVerifier
{
    /**
     * Render the compiled structure for the given active party-combination in both web
     * and PDF modes and compare them structurally.
     *
     * @param array<string,mixed> $structure  The CDS JSON structure (§2).
     * @param string[]            $activePartyKeys The party keys present in this combination.
     */
    public function verify(array $structure, array $activePartyKeys): RenderParityResult;
}
