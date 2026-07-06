<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Contracts\RenderParityResult;
use App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use Throwable;

/**
 * AT-177 / WS2 — the PRODUCTION {@see RenderParityVerifier} (spec §4 L6, §6).
 *
 * This is the implementation cc3's linter delegates to (its `RenderParityRule` fronts this
 * seam and emits an honest PENDING when it is absent). Plugging this in flips L6 from
 * PENDING → a live parity proof.
 *
 * For a party combination it renders the document in web AND print via the ONE
 * {@see CdsRenderer}, then structurally diffs the two (same blocks, same bound values, same
 * anchors) using each surface's fingerprint. Because both surfaces are printed from the same
 * canonical CDS by the same engine, parity holds by construction — and this check PROVES it,
 * catching any future mode-specific divergence.
 *
 * This is intentionally a PURE structural diff — no external process. L6 enumerates every
 * party combination, so it must be fast and deterministic (CI-safe without Chromium). PDF
 * PRODUCIBILITY by the real engine is proven separately and once via {@see ChromiumPdfRenderService},
 * not per-combination inside the linter.
 */
final class CdsRenderParityVerifier implements RenderParityVerifier
{
    public function __construct(
        private readonly CdsRenderer $renderer = new CdsRenderer(),
    ) {
    }

    public function verify(array $structure, array $activePartyKeys): RenderParityResult
    {
        try {
            $cds = Cds::fromArray($structure);
        } catch (Throwable $e) {
            return new RenderParityResult(false, '', '', ['structure could not be parsed: ' . $e->getMessage()]);
        }

        $web = $this->renderer->renderDocument($cds, DeliveryMode::WebEsign, $activePartyKeys);
        $print = $this->renderer->renderDocument($cds, DeliveryMode::PdfWetInk, $activePartyKeys);

        $differences = self::diffFingerprints($web->fingerprint(), $print->fingerprint());

        return new RenderParityResult(
            matched: $differences === [],
            webHash: $web->fingerprintHash(),
            pdfHash: $print->fingerprintHash(),
            differences: $differences,
        );
    }

    /**
     * Block-addressed structural diff of two fingerprints.
     *
     * @param list<array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>}> $web
     * @param list<array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>}> $print
     * @return list<string>
     */
    public static function diffFingerprints(array $web, array $print): array
    {
        $notes = [];

        $webById = self::keyByBlock($web);
        $printById = self::keyByBlock($print);

        foreach ($webById as $id => $block) {
            if (! isset($printById[$id])) {
                $notes[] = "block {$id}: present in web, missing in PDF";
                continue;
            }
            $notes = array_merge($notes, self::compareBlock($id, $block, $printById[$id]));
        }
        foreach ($printById as $id => $block) {
            if (! isset($webById[$id])) {
                $notes[] = "block {$id}: present in PDF, missing in web";
            }
        }

        // Block ORDER divergence (same set, different sequence) is also a parity failure.
        $webOrder = array_column($web, 'block_id');
        $printOrder = array_column($print, 'block_id');
        if ($notes === [] && $webOrder !== $printOrder) {
            $notes[] = 'block order differs between web and PDF';
        }

        return $notes;
    }

    /**
     * @param array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>} $web
     * @param array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>} $print
     * @return list<string>
     */
    private static function compareBlock(string $id, array $web, array $print): array
    {
        $notes = [];
        if ($web['type'] !== $print['type']) {
            $notes[] = "block {$id}: type differs (web={$web['type']}, pdf={$print['type']})";
        }
        if ($web['text'] !== $print['text']) {
            $notes[] = "block {$id}: bound text differs";
        }
        if ($web['anchors'] !== $print['anchors']) {
            $notes[] = "block {$id}: anchors differ";
        }

        return $notes;
    }

    /**
     * @param list<array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>}> $blocks
     * @return array<string,array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>}>
     */
    private static function keyByBlock(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $out[$block['block_id']] = $block;
        }

        return $out;
    }
}
