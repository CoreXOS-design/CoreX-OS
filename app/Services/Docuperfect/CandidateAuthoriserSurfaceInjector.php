<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use Illuminate\Support\Facades\Log;

/**
 * Candidate-flow AUTHORISER surface injector (compose-time, engine-level).
 *
 * A candidate practitioner's work must be authorised by a full-status practitioner
 * (the "authorising practitioner"), who is a FULL-PARITY signer: for EVERY signature or
 * initial the candidate makes the authoriser makes the SAME mark (Johan 2026-08). The
 * candidate signs the practitioner role (`agent`); the authoriser is the supervisor
 * checkpoint identity.
 *
 * MARK-LEVEL, NOT STRUCTURE-LEVEL, and SIGNATURE-vs-INITIAL AWARE (rebuilt 2026-08-03).
 * Johan's ruling after re-testing the real candidate→authoriser flow on a PACK:
 *
 *   • INITIALS — both parties initial the SAME spots (mid-body per-condition / per-page).
 *     Mirror 1:1 by LOCATION: for every candidate initial mark, clone a co-located
 *     authoriser mark as its immediate sibling. Unchanged from the 2026-08-02 fix — the
 *     mid-body-initials parity this delivered is preserved.
 *
 *   • SIGNATURES — the candidate and the full-status practitioner each have their OWN
 *     signature block. Two cases, decided PER SEGMENT, engine-side (never per-template):
 *       (1) The document PROVIDES a designated full-status / co-signature block — a mark
 *           whose party is `co_signer` (hand-authored, e.g. MDF template-123) or
 *           `co_signatory` (CDS-parser output). ROUTE the authoriser's signature AND its
 *           ceremony/detail fields (location/date/time) to that block by stamping its marks
 *           with the authoriser identity (`data-recipient-identity="supervisor"` +
 *           `data-authoriser-mirror`). The mark-level bake then fills the co-signature line
 *           (no more "Awaiting co signer") and the signing UI makes its "Signed at ___ on
 *           ___" fields editable by the authoriser. The candidate's signature line is left
 *           ALONE — no mirror stacked on it — and NO synthetic ceremony block is added (the
 *           designated block carries its own).
 *       (2) The document has NO designated block — clone a co-located authoriser mirror for
 *           each candidate signature, rendered on its OWN LINE (display:block) with the
 *           authoriser DESIGNATION attached, immediately after the candidate's signature —
 *           NOT stacked inline on the candidate's line (Johan: "where there's no specific
 *           section it can just sit on the same line as the candidate but on its own line
 *           and name attached"). One authoriser ceremony attestation is injected once per
 *           signing segment (location/date/time, NO signature line so it can never double a
 *           per-mark signature mirror).
 *
 *   The authoriser signs ONCE per document for SIGNATURES (the designated block, or the
 *   single own-line mirror per candidate signature); INITIALS remain 1:1 by location.
 *
 * The authoriser is bound by ROLE-IDENTITY (`data-recipient-identity`), never a placeholder
 * name — the authoriser is the one signer whose person is unknown at document creation
 * (shared queue), so a name key can never match the claiming practitioner; identity binds
 * instead (folded across supervisor / supervisor_final by CanonicalInkComposer::foldIdentity).
 *
 * Fail-open: any error returns the original HTML unchanged.
 */
class CandidateAuthoriserSurfaceInjector
{
    /** Mark types the authoriser mirrors 1:1 with the candidate. */
    private const MIRRORED_TYPES = ['signature', 'initial'];

    /** Party tokens the CANDIDATE practitioner signs under (the marks we mirror). */
    private const CANDIDATE_PARTIES = ['agent', 'property_practitioner'];

    /**
     * Party tokens that denote a document's DESIGNATED full-status / co-signature block.
     * Engine-general (Johan's J1 ruling): recognise BOTH the hand-authored `co_signer`
     * (e.g. MDF template-123) and the CDS-parser's `co_signatory` — the same signal.
     */
    private const DESIGNATED_AUTHORISER_PARTIES = ['co_signer', 'co_signatory'];

    /** Marker types re-keyed onto a designated block (signature + its ceremony fields). */
    private const ROUTED_TYPES = ['signature', 'location', 'day', 'month', 'year', 'time'];

    /** Ceremony field types for the once-per-segment authoriser attestation (no-block docs). */
    private const CEREMONY_TYPES = ['location', 'day', 'month', 'year', 'time'];

    /**
     * @param string $html        merged document body (one or more .corex-document-wrapper segments)
     * @param string $identity    authoriser role-identity stamped on every mirrored/routed mark (base checkpoint identity)
     * @param string $designation neutral designation label until the claiming practitioner binds at sign time
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

            $segments = $this->segments($dom, $xpath);
            $mirroredSig = 0;
            $mirroredInit = 0;
            $routedBlocks = 0;
            $ceremonySegments = 0;

            foreach ($segments as $seg) {
                $hasDesignated = $this->segmentHasDesignatedAuthoriserBlock($xpath, $seg);

                // ── INITIALS — always per-mark co-located mirror (mid-body parity) ──────
                foreach ($this->candidateMarksInSegment($xpath, $seg, 'initial') as $mark) {
                    if ($this->pairedAuthoriserMark($mark, 'initial', $identity) !== null) {
                        continue; // idempotent — this location already carries an authoriser mark
                    }
                    $this->insertMirror($mark, 'initial', $identity);
                    $mirroredInit++;
                }

                if ($hasDesignated) {
                    // ── SIGNATURES — route to the document's designated co-signature block ──
                    // Stamp the block's signature + ceremony marks with the authoriser
                    // identity; leave the candidate's signature line untouched; no synthetic
                    // ceremony (the designated block carries its own "Signed at ___ on ___").
                    $routedBlocks += $this->routeSignatureToDesignatedBlock($xpath, $seg, $identity);
                } else {
                    // ── SIGNATURES — own-line co-located mirror (designation attached) ──────
                    foreach ($this->candidateMarksInSegment($xpath, $seg, 'signature') as $mark) {
                        if ($this->pairedAuthoriserMark($mark, 'signature', $identity) !== null) {
                            continue; // idempotent
                        }
                        $this->insertOwnLineSignatureMirror($mark, $identity, $designation);
                        $mirroredSig++;
                    }

                    // One authoriser ceremony attestation per signing segment.
                    if ($this->segmentHasCandidateSignature($xpath, $seg)
                        && ! $this->segmentHasAuthoriserCeremony($xpath, $seg, $identity)) {
                        $seg->appendChild($this->buildCeremonyBlock($dom, $identity, $designation));
                        $ceremonySegments++;
                    }
                }
            }

            $out = $dom->saveHTML();
            $out = trim((string) preg_replace('/^<\?xml encoding="utf-8"\?>/', '', (string) $out));

            Log::info('AUTHORISER_MARKS_MIRRORED', [
                'signature_mirrors' => $mirroredSig,
                'initial_mirrors'   => $mirroredInit,
                'routed_blocks'     => $routedBlocks,
                'ceremony_segments' => $ceremonySegments,
            ]);

            return $out;
        } catch (\Throwable $e) {
            Log::warning('AUTHORISER_SURFACE_INJECT_FAILED', ['error' => $e->getMessage()]);
            return $html;
        }
    }

    /**
     * COMPLETENESS (per-segment, in LOCKSTEP with inject) — every candidate signature/initial
     * mark that lacks a FILLED authoriser mark. Returns a list of violation descriptors; an
     * empty list means the document has full authoriser parity. A non-empty result = the
     * document is incomplete and a bank/conveyancer rejects it.
     *
     * SIGNATURE completeness follows the same signature/designated split as inject():
     *   • segment WITH a designated block → complete iff that block's authoriser signature is
     *     FILLED (the authoriser signs once there for the whole segment);
     *   • segment WITHOUT → each candidate signature needs a FILLED own-line mirror at its own
     *     anchor.
     * INITIAL completeness is unchanged: 1:1 by location (a filled co-located mirror per mark).
     * A genuinely missing/unfilled authoriser mark still FAILS (true-negative preserved).
     *
     * Pure/static so both the runtime finalisation guard and the regression harness call the
     * SAME authority (no drift between "what we enforce" and "what we test").
     *
     * @return array<int, array{type:string, party:string, index:string}>
     */
    public static function unmirroredCandidateMarks(string $html, string $identity = 'supervisor'): array
    {
        if (trim($html) === '') {
            return [];
        }
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            $xpath = new \DOMXPath($dom);
            $inst = new self();
            $violations = [];

            foreach ($inst->segments($dom, $xpath) as $seg) {
                $hasDesignated = $inst->segmentHasDesignatedAuthoriserBlock($xpath, $seg);

                // signatures
                $candSigs = $inst->candidateMarksInSegment($xpath, $seg, 'signature');
                if ($hasDesignated) {
                    $authSig = $inst->designatedAuthoriserSignature($xpath, $seg);
                    $filled = $authSig !== null && $inst->markIsFilled($authSig);
                    if (! $filled) {
                        foreach ($candSigs as $c) {
                            $violations[] = $inst->violationOf($c);
                        }
                    }
                } else {
                    foreach ($candSigs as $c) {
                        $auth = $inst->pairedAuthoriserMark($c, 'signature', $identity);
                        if ($auth === null || ! $inst->markIsFilled($auth)) {
                            $violations[] = $inst->violationOf($c);
                        }
                    }
                }

                // initials — 1:1 by location
                foreach ($inst->candidateMarksInSegment($xpath, $seg, 'initial') as $c) {
                    $auth = $inst->pairedAuthoriserMark($c, 'initial', $identity);
                    if ($auth === null || ! $inst->markIsFilled($auth)) {
                        $violations[] = $inst->violationOf($c);
                    }
                }
            }

            return $violations;
        } catch (\Throwable $e) {
            // Fail-open on parse errors — never block completion on a DOM hiccup.
            Log::warning('AUTHORISER_COMPLETENESS_CHECK_FAILED', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array{type:string, party:string, index:string} */
    private function violationOf(\DOMElement $el): array
    {
        return [
            'type'  => strtolower(trim($el->getAttribute('data-marker-type'))),
            'party' => $el->getAttribute('data-marker-party'),
            'index' => $el->getAttribute('data-marker-index') ?: '(none)',
        ];
    }

    // ── mark helpers ──────────────────────────────────────────────────────────

    /** Base role token (numeric suffix stripped, lower-cased) of a mark's party. */
    private function baseParty(\DOMElement $el): string
    {
        return (string) preg_replace('/_\d+$/', '', strtolower(trim($el->getAttribute('data-marker-party'))));
    }

    /** Is this mark one the CANDIDATE practitioner makes (and not an authoriser mark)? */
    private function isCandidateMark(\DOMElement $el): bool
    {
        if ($el->getAttribute('data-authoriser-mirror') === 'true') {
            return false;
        }
        return in_array($this->baseParty($el), self::CANDIDATE_PARTIES, true);
    }

    /** Does the mark denote the authoriser identity (mirror, routed block, or supervisor family)? */
    private function isAuthoriserMark(\DOMElement $el, string $identity): bool
    {
        if ($el->getAttribute('data-authoriser-mirror') === 'true') {
            return true;
        }
        $rid = strtolower(trim($el->getAttribute('data-recipient-identity')));
        if ($rid !== '' && (string) preg_replace('/_\d+$/', '', $rid) === strtolower($identity)) {
            return true;
        }
        $base = $this->baseParty($el);
        return $base === 'supervisor' || $base === strtolower($identity);
    }

    /**
     * Snapshot (array) of the candidate marks of a given type within a segment. Snapshotting
     * up front is essential: inject() inserts siblings as it goes, so a live NodeList would
     * re-scan freshly-inserted mirrors.
     *
     * @return array<int, \DOMElement>
     */
    private function candidateMarksInSegment(\DOMXPath $xpath, \DOMElement $seg, string $type): array
    {
        $out = [];
        foreach ($xpath->query('.//*[@data-marker-type][@data-marker-party]', $seg) as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            if (strtolower(trim($el->getAttribute('data-marker-type'))) !== $type) {
                continue;
            }
            if (! $this->isCandidateMark($el)) {
                continue;
            }
            $out[] = $el;
        }
        return $out;
    }

    /** Does this segment contain a DESIGNATED full-status / co-signature block? */
    private function segmentHasDesignatedAuthoriserBlock(\DOMXPath $xpath, \DOMElement $seg): bool
    {
        return $this->designatedAuthoriserSignature($xpath, $seg) !== null;
    }

    /** The designated block's SIGNATURE mark in this segment (co_signer / co_signatory), or null. */
    private function designatedAuthoriserSignature(\DOMXPath $xpath, \DOMElement $seg): ?\DOMElement
    {
        foreach ($xpath->query('.//*[@data-marker-type="signature"][@data-marker-party]', $seg) as $el) {
            if ($el instanceof \DOMElement
                && in_array($this->baseParty($el), self::DESIGNATED_AUTHORISER_PARTIES, true)) {
                return $el;
            }
        }
        return null;
    }

    /**
     * The authoriser mark PAIRED to this specific candidate mark, or null. Used for INITIALS
     * (always) and for SIGNATURES in no-designated-block segments.
     *
     * Pairing must be exact per mark: several candidate marks can share one parent, so "any
     * authoriser mark among the siblings" would let the mirror of the FIRST mark satisfy the
     * rest. The paired mark is therefore:
     *   1. this mark's immediate next element sibling, when that is an authoriser mark of the
     *      same type — where insertMirror()/insertOwnLineSignatureMirror() place the mirror
     *      (re-run idempotency) AND where an adjacent enumerated authoriser slot sits; ELSE
     *   2. a PRE-EXISTING (non-mirror) authoriser mark of the same type among the siblings —
     *      the enumerated per-condition/per-row authoriser slot. Our own freshly-inserted
     *      mirrors (tagged data-authoriser-mirror) are excluded here so they never satisfy a
     *      DIFFERENT mark.
     */
    private function pairedAuthoriserMark(\DOMElement $mark, string $type, string $identity): ?\DOMElement
    {
        $next = $this->nextElementSibling($mark);
        if ($next !== null
            && strtolower(trim($next->getAttribute('data-marker-type'))) === $type
            && $this->isAuthoriserMark($next, $identity)) {
            return $next;
        }

        $parent = $mark->parentNode;
        if ($parent instanceof \DOMElement || $parent instanceof \DOMDocument) {
            foreach ($parent->childNodes as $sib) {
                if (! $sib instanceof \DOMElement || $sib === $mark) {
                    continue;
                }
                if ($sib->getAttribute('data-authoriser-mirror') === 'true') {
                    continue; // our own mirror — belongs to whichever mark it trails, not this one
                }
                if (strtolower(trim($sib->getAttribute('data-marker-type'))) === $type && $this->isAuthoriserMark($sib, $identity)) {
                    return $sib;
                }
            }
        }
        return null;
    }

    /** Next sibling that is an element, skipping whitespace/text nodes. */
    private function nextElementSibling(\DOMElement $el): ?\DOMElement
    {
        $n = $el->nextSibling;
        while ($n !== null && ! $n instanceof \DOMElement) {
            $n = $n->nextSibling;
        }
        return $n instanceof \DOMElement ? $n : null;
    }

    /** A mark is filled once the bake has stamped ink (data-signed) or an <img> into it. */
    private function markIsFilled(\DOMElement $el): bool
    {
        if ($el->getAttribute('data-signed') === 'true') {
            return true;
        }
        return $el->getElementsByTagName('img')->length > 0;
    }

    /**
     * Route the authoriser's SIGNATURE (and its ceremony fields) to the document's designated
     * co-signature block: stamp the block's marks with the authoriser identity so the bake
     * owns + fills them and the signing UI makes the "Signed at ___ on ___" fields editable.
     * `data-marker-party` is DELIBERATELY preserved (the `co_signer`/`co_signatory` token is
     * the block's detection signal on re-parse — completeness must still see it). Idempotent.
     *
     * @return int 1 if this segment carried a designated block that was stamped/present, else 0
     */
    private function routeSignatureToDesignatedBlock(\DOMXPath $xpath, \DOMElement $seg, string $identity): int
    {
        $found = 0;
        foreach ($xpath->query('.//*[@data-marker-type][@data-marker-party]', $seg) as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            if (! in_array($this->baseParty($el), self::DESIGNATED_AUTHORISER_PARTIES, true)) {
                continue;
            }
            $type = strtolower(trim($el->getAttribute('data-marker-type')));
            if (! in_array($type, self::ROUTED_TYPES, true)) {
                continue;
            }
            $found = 1;
            // Idempotent — already routed to this identity.
            if (strtolower(trim($el->getAttribute('data-recipient-identity'))) === strtolower($identity)
                && $el->getAttribute('data-authoriser-mirror') === 'true') {
                continue;
            }
            $el->setAttribute('data-recipient-identity', $identity);
            $el->setAttribute('data-authoriser-mirror', 'true');
        }
        return $found;
    }

    /**
     * Clone a candidate initial mark into a co-located authoriser mirror inserted as the
     * mark's immediate sibling (1:1 by location — mid-body initials parity). Cloning preserves
     * the exact structure so the mirror renders identically; we then re-key it to the
     * authoriser identity and strip the candidate's person (bind by identity, never a name).
     */
    private function insertMirror(\DOMElement $mark, string $type, string $identity): void
    {
        $mirror = $mark->cloneNode(true);
        if (! $mirror instanceof \DOMElement) {
            return;
        }
        $this->reKeyToAuthoriser($mirror, $identity);
        foreach (iterator_to_array($mirror->getElementsByTagName('*')) as $child) {
            if ($child instanceof \DOMElement && $child->hasAttribute('data-marker-party')) {
                $this->reKeyToAuthoriser($child, $identity);
            }
        }
        $origIndex = $mark->getAttribute('data-marker-index');
        $mirror->setAttribute('data-marker-index', ($origIndex !== '' ? $origIndex : $type) . '-auth');

        // insertBefore(node, null) appends — correct when the mark is the last child.
        $mark->parentNode?->insertBefore($mirror, $mark->nextSibling);
    }

    /**
     * No-designated-block SIGNATURE mirror: clone a co-located authoriser signature and render
     * it on its OWN LINE (display:block) with the authoriser DESIGNATION attached, immediately
     * after the candidate's signature — never stacked inline on the candidate's line
     * (Johan 2026-08-03). Identity-bound, no placeholder name.
     */
    private function insertOwnLineSignatureMirror(\DOMElement $mark, string $identity, string $designation): void
    {
        $mirror = $mark->cloneNode(true);
        if (! $mirror instanceof \DOMElement) {
            return;
        }
        $this->reKeyToAuthoriser($mirror, $identity);
        foreach (iterator_to_array($mirror->getElementsByTagName('*')) as $child) {
            if ($child instanceof \DOMElement && $child->hasAttribute('data-marker-party')) {
                $this->reKeyToAuthoriser($child, $identity);
            }
        }
        $origIndex = $mark->getAttribute('data-marker-index');
        $mirror->setAttribute('data-marker-index', ($origIndex !== '' ? $origIndex : 'signature') . '-auth');
        // Force onto its own line regardless of whether the cloned mark is inline (a span in
        // a <p>) or block (a sig-cell div) — so it never sits ON the candidate's line.
        $style = trim($mirror->getAttribute('style'));
        $mirror->setAttribute('style', ($style !== '' ? rtrim($style, ';') . ';' : '') . 'display:block;margin-top:6pt;');

        // Designation label (the "name attached" — the specific person binds at sign time via
        // the ink; the shared-queue authoriser has no name at compose).
        $doc = $mark->ownerDocument;
        $label = null;
        if ($doc instanceof \DOMDocument) {
            $label = $doc->createElement('span');
            $label->setAttribute('class', 'authoriser-mirror-label');
            $label->setAttribute('data-authoriser-label', 'true');
            $label->setAttribute('style', 'display:block;font-size:9pt;color:#475569;margin-top:1pt;');
            $label->appendChild($doc->createTextNode($designation));
        }

        $parent = $mark->parentNode;
        $parent?->insertBefore($mirror, $mark->nextSibling);
        if ($label !== null) {
            $parent?->insertBefore($label, $mirror->nextSibling);
        }
    }

    /** Re-stamp a cloned mark element to the authoriser identity; drop the candidate's name. */
    private function reKeyToAuthoriser(\DOMElement $el, string $identity): void
    {
        $el->setAttribute('data-marker-party', 'supervisor');
        $el->setAttribute('data-recipient-identity', $identity);
        $el->setAttribute('data-authoriser-mirror', 'true');
        $el->removeAttribute('data-name');
        $el->removeAttribute('data-signed');
    }

    // ── segment helpers (ceremony attestation) ─────────────────────────────────

    /** @return array<int, \DOMElement> */
    private function segments(\DOMDocument $dom, \DOMXPath $xpath): array
    {
        $segments = [];
        $wrappers = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " corex-document-wrapper ")]');
        if ($wrappers !== false && $wrappers->length > 0) {
            foreach ($wrappers as $w) {
                if ($w instanceof \DOMElement) {
                    $segments[] = $w;
                }
            }
        } elseif ($dom->documentElement !== null) {
            $segments[] = $dom->documentElement;
        }
        return $segments;
    }

    private function segmentHasCandidateSignature(\DOMXPath $xpath, \DOMElement $seg): bool
    {
        foreach ($xpath->query('.//*[@data-marker-type="signature"][@data-marker-party]', $seg) as $el) {
            if ($el instanceof \DOMElement && $this->isCandidateMark($el)) {
                return true;
            }
        }
        return false;
    }

    private function segmentHasAuthoriserCeremony(\DOMXPath $xpath, \DOMElement $seg, string $identity): bool
    {
        $q = $xpath->query(
            './/*[@data-marker-type="location"][@data-recipient-identity="' . $identity . '" or @data-marker-party="supervisor"]',
            $seg
        );
        return $q !== false && $q->length > 0;
    }

    /**
     * The authoriser's ceremony attestation — location/date/time marks only, NO signature
     * line (the authoriser's signatures are the per-mark own-line mirrors). Identity-stamped,
     * designation-labelled. Filled by SigningController::completeWeb via `supervisor_*` keys.
     * Injected ONLY into no-designated-block segments (a designated block carries its own).
     */
    private function buildCeremonyBlock(\DOMDocument $dom, string $identity, string $designation): \DOMElement
    {
        $block = $dom->createElement('div');
        $block->setAttribute('class', 'sig-party-block');
        $block->setAttribute('data-authoriser-ceremony', 'true');

        $p = $dom->createElement('p');
        $p->setAttribute('class', 'sig-text');
        $p->appendChild($dom->createTextNode('Thus authorised and signed by the ' . $designation . ' at '));

        $field = function (string $cls, string $type) use ($dom, $identity): \DOMElement {
            $s = $dom->createElement('span');
            $s->setAttribute('class', $cls);
            $s->setAttribute('data-marker-party', 'supervisor');
            $s->setAttribute('data-recipient-identity', $identity);
            $s->setAttribute('data-marker-type', $type);
            $s->setAttribute('data-authoriser-mirror', 'true');
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
        $p->appendChild($dom->createTextNode(' am / pm — authorising the candidate practitioner\'s signatures above.'));
        $block->appendChild($p);

        $labelWrap = $dom->createElement('div');
        $labelWrap->setAttribute('class', 'sig-cell-label');
        $labelWrap->appendChild($dom->createTextNode($designation));
        $block->appendChild($labelWrap);

        return $block;
    }
}
