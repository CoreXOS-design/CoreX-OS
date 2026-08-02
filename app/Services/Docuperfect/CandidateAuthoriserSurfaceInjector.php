<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use Illuminate\Support\Facades\Log;

/**
 * Candidate-flow AUTHORISER surface injector (compose-time, engine-level).
 *
 * A candidate practitioner's work must be authorised by a full-status practitioner
 * (the "authorising practitioner"), who is a FULL-PARITY signer: for EVERY signature or
 * initial the candidate makes — wherever it sits, in whatever markup — the authoriser
 * makes the SAME mark at the SAME place (Johan 2026-08). The candidate signs the
 * practitioner role (`agent`); the authoriser is the supervisor checkpoint identity.
 *
 * MARK-LEVEL, NOT STRUCTURE-LEVEL (rebuilt 2026-08-02).
 * The previous implementation provisioned ONE authoriser surface per *segment*, placed at
 * the segment tail, and skipped any segment that already held a supervisor marker. That is
 * structure-level: a document with N candidate signature blocks mid-body received ONE (or
 * zero) authoriser surfaces — the marks in the middle of the body were never mirrored
 * (the "3 mid-body signature blocks" defect). An incomplete document = bank/attorney
 * rejection.
 *
 * This pass is driven by the MARKS themselves, so it is structure-agnostic and holds for
 * ANY imported document regardless of how it was authored:
 *
 *   1. PER-MARK MIRROR — for every candidate `data-marker-type="signature|initial"` mark,
 *      a mirrored authoriser mark is cloned and inserted as its immediate SIBLING (same
 *      parent, same location). The existing mark-level bake (CanonicalInkComposer) then
 *      fills every mirrored marker automatically — the ink core is unchanged.
 *   2. SIBLING-IDEMPOTENT — a candidate mark whose parent ALREADY holds an authoriser
 *      mark of the same type is left alone. This makes re-runs a no-op AND correctly
 *      leaves the per-condition initial rows untouched (the enumerated authoriser slot is
 *      already a sibling of the candidate slot there), so initials are never doubled.
 *   3. CEREMONY ATTESTATION — one authoriser ceremony block (location/date/time marks, NO
 *      signature line so it can never double a per-mark signature mirror) is injected once
 *      per SIGNING segment, recording where/when the authoriser authorised. Idempotent.
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

    /** Ceremony field types for the once-per-segment authoriser attestation. */
    private const CEREMONY_TYPES = ['location', 'day', 'month', 'year', 'time'];

    /**
     * @param string $html        merged document body (one or more .corex-document-wrapper segments)
     * @param string $identity    authoriser role-identity stamped on every mirrored mark (base checkpoint identity)
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

            // ── 1. PER-MARK MIRROR ────────────────────────────────────────────────
            // Snapshot the candidate marks BEFORE mutating (we insert siblings as we go).
            $candidateMarks = [];
            foreach ($xpath->query('//*[@data-marker-type][@data-marker-party]') as $el) {
                if (! $el instanceof \DOMElement) {
                    continue;
                }
                $type = strtolower(trim($el->getAttribute('data-marker-type')));
                if (! in_array($type, self::MIRRORED_TYPES, true)) {
                    continue;
                }
                if (! $this->isCandidateMark($el)) {
                    continue;
                }
                $candidateMarks[] = $el;
            }

            $mirrored = 0;
            foreach ($candidateMarks as $mark) {
                $type = strtolower(trim($mark->getAttribute('data-marker-type')));
                if ($this->pairedAuthoriserMark($mark, $type, $identity) !== null) {
                    continue; // idempotent — this exact location already carries an authoriser mark
                }
                $this->insertMirror($mark, $type, $identity);
                $mirrored++;
            }

            // ── 2. CEREMONY ATTESTATION (once per signing segment) ────────────────
            $segments = $this->segments($dom, $xpath);
            $ceremonySegments = 0;
            foreach ($segments as $seg) {
                if (! $this->segmentHasCandidateSignature($xpath, $seg)) {
                    continue; // authoriser attests only where the candidate signs
                }
                if ($this->segmentHasAuthoriserCeremony($xpath, $seg, $identity)) {
                    continue; // idempotent — already carries an authoriser attestation
                }
                $seg->appendChild($this->buildCeremonyBlock($dom, $identity, $designation));
                $ceremonySegments++;
            }

            $out = $dom->saveHTML();
            $out = trim((string) preg_replace('/^<\?xml encoding="utf-8"\?>/', '', (string) $out));

            Log::info('AUTHORISER_MARKS_MIRRORED', [
                'candidate_marks'   => count($candidateMarks),
                'marks_mirrored'    => $mirrored,
                'ceremony_segments' => $ceremonySegments,
            ]);

            return $out;
        } catch (\Throwable $e) {
            Log::warning('AUTHORISER_SURFACE_INJECT_FAILED', ['error' => $e->getMessage()]);
            return $html;
        }
    }

    /**
     * COMPLETENESS (1:1 by location) — every candidate signature/initial mark that lacks a
     * FILLED authoriser mark at the SAME anchor (an immediate sibling). Returns a list of
     * violation descriptors; an empty list means the document has full authoriser parity.
     * A non-empty result = the document is incomplete and a bank/conveyancer rejects it.
     *
     * Pure/static so both the runtime finalisation guard and the regression harness call
     * the SAME authority (no drift between "what we enforce" and "what we test").
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
            foreach ($xpath->query('//*[@data-marker-type][@data-marker-party]') as $el) {
                if (! $el instanceof \DOMElement) {
                    continue;
                }
                $type = strtolower(trim($el->getAttribute('data-marker-type')));
                if (! in_array($type, self::MIRRORED_TYPES, true) || ! $inst->isCandidateMark($el)) {
                    continue;
                }
                $auth = $inst->pairedAuthoriserMark($el, $type, $identity);
                if ($auth === null || ! $inst->markIsFilled($auth)) {
                    $violations[] = [
                        'type'  => $type,
                        'party' => $el->getAttribute('data-marker-party'),
                        'index' => $el->getAttribute('data-marker-index') ?: '(none)',
                    ];
                }
            }
            return $violations;
        } catch (\Throwable $e) {
            // Fail-open on parse errors — never block completion on a DOM hiccup.
            Log::warning('AUTHORISER_COMPLETENESS_CHECK_FAILED', ['error' => $e->getMessage()]);
            return [];
        }
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

    /** Does the mark denote the authoriser identity (mirror, or supervisor family)? */
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
     * The authoriser mark PAIRED to this specific candidate mark, or null.
     *
     * Pairing must be exact per mark: several candidate marks can share one parent (three
     * inline signature lines directly under the document wrapper), so "any authoriser mark
     * among the siblings" would let the mirror of the FIRST mark satisfy the rest — the very
     * under-mirroring this rebuild fixes. The paired mark is therefore:
     *   1. this mark's immediate next element sibling, when that is an authoriser mark of the
     *      same type — this is where insertMirror() places our mirror (re-run idempotency)
     *      AND where an adjacent enumerated authoriser slot sits; ELSE
     *   2. a PRE-EXISTING (non-mirror) authoriser mark of the same type among the siblings —
     *      the enumerated per-condition/per-row authoriser slot, which need not be adjacent
     *      (agent → seller → … → supervisor). Our own freshly-inserted mirrors (tagged
     *      data-authoriser-mirror) are excluded here so they never satisfy a DIFFERENT mark.
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
     * Clone a candidate mark into an authoriser mirror and insert it as the mark's
     * immediate sibling. Cloning preserves the exact structure/classes so the mirror
     * renders identically; we then re-key it to the authoriser identity and strip the
     * candidate's person (bind by identity, never a placeholder name).
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

    /** Re-stamp a mark element to the authoriser identity; drop the candidate's name. */
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
     * line (the authoriser's signatures are the per-mark mirrors). Identity-stamped,
     * designation-labelled. Filled by SigningController::completeWeb via `supervisor_*`
     * keys, exactly as the removed component block was.
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
