<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use Illuminate\Support\Facades\Log;

/**
 * Candidate-flow AUTHORISER surface injector (compose-time, engine-level).
 *
 * A candidate practitioner's work must be authorised by a full-status practitioner
 * (the "authorising practitioner"), who is a FULL-PARITY signer — they sign wherever
 * the candidate/recipients sign (Johan 2026-08). The authoriser's per-page INITIALS are
 * already universal (client pagination emits one box per signing identity, and
 * SignatureTemplate::enumeratedSigningParties folds supervisor/supervisor_final to one).
 * Their SIGNATURE block, however, only exists on templates that render the shared
 * `signature-block` component. An imported document authored WITHOUT that component
 * (a Mandatory Disclosure, an Addendum, an arbitrary import) then leaves the authoriser
 * with nowhere to sign — their captured ink binds to nothing and drops from the final
 * document (an incomplete doc = bank/attorney rejection).
 *
 * This resolver guarantees the invariant the ceremony assumes: on EVERY signing segment
 * of a candidate document, the authorising practitioner has exactly ONE parity signature
 * surface — injected where the template omitted it, skipped where the component already
 * rendered it. Same shape/altitude as SigningSurfaceResolver (which injects missing
 * RECIPIENT surfaces): run once at prepareSigning over the merged body, fail-open.
 *
 * Design invariants:
 *   - IDEMPOTENT: a segment that already carries a supervisor-family signature surface
 *     (the mandate component) is left untouched — never double-injected.
 *   - PARITY-SCOPED: inject only into segments that ALREADY hold another party's
 *     signature surface (a signing segment). A pure-information attachment with no
 *     signatures gets nothing — the authoriser signs where the others sign.
 *   - IDENTITY-BOUND, DESIGNATION-LABELLED: the injected block matches the component's
 *     block exactly — data-recipient-identity (never a placeholder name), designation
 *     label (never the raw "supervisor" token). It binds through the same foldIdentity
 *     path and completes under the same single-signing semantics.
 */
class CandidateAuthoriserSurfaceInjector
{
    /**
     * @param string $html        merged document body (one or more .corex-document-wrapper segments)
     * @param string $identity    authoriser role-identity stamped on the markers (base checkpoint identity)
     * @param string $designation neutral designation label until the claiming practitioner's binds at sign time
     */
    public function inject(string $html, string $identity = 'supervisor', string $designation = 'Authorising Practitioner'): string
    {
        if (trim($html) === '') {
            return $html;
        }

        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            $xpath = new \DOMXPath($dom);

            // Each .corex-document-wrapper is one pack segment; a single doc with no
            // wrapper is treated as one segment (the document element).
            $segments = [];
            $wrappers = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " corex-document-wrapper ")]');
            if ($wrappers !== false && $wrappers->length > 0) {
                foreach ($wrappers as $w) {
                    $segments[] = $w;
                }
            } elseif ($dom->documentElement !== null) {
                $segments[] = $dom->documentElement;
            }

            $injected = 0;
            foreach ($segments as $seg) {
                if (! $seg instanceof \DOMElement) {
                    continue;
                }
                if ($this->hasAuthoriserSignature($xpath, $seg, $identity)) {
                    continue; // idempotent — component already rendered the authoriser block
                }
                if (! $this->hasAnySignature($xpath, $seg)) {
                    continue; // not a signing segment — the authoriser signs where others sign
                }
                $this->placeInSegment($xpath, $seg, $this->buildAuthoriserBlock($dom, $identity, $designation));
                $injected++;
            }

            $out = $dom->saveHTML();
            $out = trim((string) preg_replace('/^<\?xml encoding="utf-8"\?>/', '', (string) $out));

            Log::info('AUTHORISER_SURFACE_INJECTED', [
                'segments'          => count($segments),
                'segments_injected' => $injected,
            ]);

            return $out;
        } catch (\Throwable $e) {
            Log::warning('AUTHORISER_SURFACE_INJECT_FAILED', ['error' => $e->getMessage()]);
            return $html;
        }
    }

    /** Does this segment already carry a supervisor-family signature surface? */
    private function hasAuthoriserSignature(\DOMXPath $xpath, \DOMElement $seg, string $identity): bool
    {
        $q = $xpath->query(
            './/*[@data-marker-type="signature"]'
            . '[@data-recipient-identity="' . $identity . '"'
            . ' or @data-marker-party="supervisor" or @data-marker-party="supervisor_final"]',
            $seg
        );
        return $q !== false && $q->length > 0;
    }

    /** Does this segment hold any signature surface at all (a signing segment)? */
    private function hasAnySignature(\DOMXPath $xpath, \DOMElement $seg): bool
    {
        $q = $xpath->query('.//*[@data-marker-type="signature"]', $seg);
        return $q !== false && $q->length > 0;
    }

    /**
     * Build the authoriser's parity signature block — the exact structure/selectors of
     * signature-block.blade.php's candidate block, so an injected surface is
     * indistinguishable from a component-rendered one (identity-stamped, designation
     * label, ceremony spans + signature cell).
     */
    private function buildAuthoriserBlock(\DOMDocument $dom, string $identity, string $designation): \DOMElement
    {
        $block = $dom->createElement('div');
        $block->setAttribute('class', 'sig-party-block');
        $block->setAttribute('data-authoriser-injected', 'true');

        $p = $dom->createElement('p');
        $p->setAttribute('class', 'sig-text');
        $p->appendChild($dom->createTextNode('Thus authorised and signed by the ' . $designation . ' at '));

        $field = function (string $cls, string $type) use ($dom, $identity): \DOMElement {
            $s = $dom->createElement('span');
            $s->setAttribute('class', $cls);
            $s->setAttribute('data-marker-party', 'supervisor');
            $s->setAttribute('data-recipient-identity', $identity);
            $s->setAttribute('data-marker-type', $type);
            return $s;
        };

        $p->appendChild($field('sig-field', 'location'));
        $p->appendChild($dom->createTextNode(' on this '));
        $p->appendChild($field('sig-field sig-field-short', 'day'));
        $p->appendChild($dom->createTextNode(' day of '));
        $p->appendChild($field('sig-field sig-field-medium', 'month'));
        $p->appendChild($dom->createTextNode(' 20'));
        $p->appendChild($field('sig-field sig-field-year', 'year'));
        $p->appendChild($dom->createTextNode(' at '));
        $p->appendChild($field('sig-field sig-field-short', 'time'));
        $p->appendChild($dom->createTextNode(' am / pm.'));
        $block->appendChild($p);

        $row = $dom->createElement('div');
        $row->setAttribute('class', 'sig-row-adaptive cols-1');
        $cell = $dom->createElement('div');
        $cell->setAttribute('class', 'sig-cell');

        $line = $dom->createElement('div');
        $line->setAttribute('class', 'sig-cell-line');
        $line->setAttribute('data-marker-party', 'supervisor');
        $line->setAttribute('data-recipient-identity', $identity);
        $line->setAttribute('data-marker-type', 'signature');
        $line->setAttribute('data-marker-index', 'authoriser-0');
        $line->setAttribute('style', 'border-bottom:1px solid #333;min-height:28pt;');
        $cell->appendChild($line);

        $label = $dom->createElement('div');
        $label->setAttribute('class', 'sig-cell-label');
        $label->appendChild($dom->createTextNode($designation));
        $cell->appendChild($label);

        $row->appendChild($cell);
        $block->appendChild($row);

        return $block;
    }

    /**
     * Place the block immediately AFTER the segment's last existing signature block, so
     * it groups with the document's signatures; fall back to appending to the segment.
     */
    private function placeInSegment(\DOMXPath $xpath, \DOMElement $seg, \DOMElement $block): void
    {
        $anchor = null;
        $markers = $xpath->query('.//*[@data-marker-type="signature"]', $seg);
        if ($markers !== false && $markers->length > 0) {
            $node = $markers->item($markers->length - 1);
            while ($node instanceof \DOMElement && $node->parentNode !== $seg) {
                $node = $node->parentNode;
            }
            if ($node instanceof \DOMElement && $node->parentNode === $seg) {
                $anchor = $node;
            }
        }

        if ($anchor !== null && $anchor->nextSibling !== null) {
            $seg->insertBefore($block, $anchor->nextSibling);
        } else {
            $seg->appendChild($block);
        }
    }
}
