<?php

namespace App\Services\Docuperfect;

use Illuminate\Support\Facades\Log;

/**
 * §20 — Single Authoritative Signing-Surface Resolver (APPROVED).
 *
 * Root cause (.ai/AUDIT-esign-signing-surface-seam.md): signing-surface
 * identity is independently re-derived at four disagreeing sites, and the
 * on-disk CDS blade is hand-authored and can be stale relative to the
 * persisted field config (#119: blade SIG 1 is agent-only, but
 * field_mappings correctly holds [Agent,Buyer,Seller]). Signing reads the
 * stale blade and never re-compiles, so a recipient's surface can be
 * physically absent.
 *
 * This resolver is the single authority. Run once at prepareSigning over
 * the merged document body, BEFORE merged_html is persisted. Its inputs are
 * the document's actual recipients (the real party set that becomes the
 * signature_requests rows) — NOT the blade, NOT field_mappings, NOT
 * cds_json. It guarantees, per the §20 invariant:
 *
 *   1. Every [data-marker-party] element is stamped with a canonical
 *      recipient role key (standalone generalisation of the pack-only
 *      normalizePackMarkerParties — family-collapse, numeric suffix kept).
 *   2. Every recipient has exactly one signature surface: where the
 *      (possibly stale) blade omitted one, it is INJECTED — re-keying
 *      alone cannot fix an absent surface.
 *   3. Recipient-driven by construction: a party ticked on a sig block
 *      with no matching recipient produces nothing (no buyer recipient =>
 *      no buyer surface, ever).
 *
 * Fail-open: any error returns the original HTML unchanged (parity with
 * normalizePackMarkerParties — never make a document worse than it was).
 *
 * NOTE: the CDS-compile heuristic, the live-fallback context branch, and
 * the fuzzy isMyWebSigBlock scan are intentionally NOT removed here — they
 * are retired in a separate follow-up once this resolver is verified.
 */
class SigningSurfaceResolver
{
    /** Role-family vocabularies (mirror normalizePackMarkerParties). */
    private const OWNER_TERMS     = ['owner_party', 'owner', 'lessor', 'landlord', 'seller'];
    private const ACQUIRING_TERMS = ['acquiring_party', 'lessee', 'tenant', 'buyer', 'purchaser'];
    private const AGENT_TERMS     = ['agent', 'property_practitioner'];

    /**
     * @param string $bodyHtml   Document body HTML (no <html>/<body> wrapper).
     * @param array  $recipients Non-agent recipients (wizard stepData), each
     *                           ['role' => ..., 'name' => ...]. This is the
     *                           same array used to create signature_requests.
     * @param string $agentName  The agent — always a signer.
     * @param bool   $isSales    true => seller/buyer canonical keys;
     *                            false => landlord/tenant.
     */
    public function resolve(string $bodyHtml, array $recipients, string $agentName, bool $isSales): string
    {
        if (trim($bodyHtml) === '') {
            return $bodyHtml;
        }

        try {
            $canonRecipients = $this->buildCanonicalRecipients($recipients, $agentName, $isSales);
            if (empty($canonRecipients)) {
                return $bodyHtml;
            }

            $ownerCanon = $isSales ? 'seller' : 'landlord';
            $acqCanon   = $isSales ? 'buyer'  : 'tenant';

            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $bodyHtml,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            $xpath = new \DOMXPath($dom);

            // 1. Re-key EVERY data-marker-party to a canonical recipient key
            //    (family-collapse, numeric suffix preserved). Standalone
            //    generalisation of normalizePackMarkerParties.
            foreach ($xpath->query('//*[@data-marker-party]') as $node) {
                /** @var \DOMElement $node */
                $raw = $node->getAttribute('data-marker-party');
                if ($raw === '') {
                    continue;
                }
                $suffix = preg_match('/_(\d+)$/', $raw, $mm) ? '_' . $mm[1] : '';
                $base   = strtolower(preg_replace('/_\d+$/', '', $raw));

                if (in_array($base, self::OWNER_TERMS, true)) {
                    $new = $ownerCanon . $suffix;
                } elseif (in_array($base, self::ACQUIRING_TERMS, true)) {
                    $new = $acqCanon . $suffix;
                } elseif (in_array($base, self::AGENT_TERMS, true)) {
                    $new = 'agent';
                } else {
                    continue; // unknown role — leave untouched
                }

                if ($new !== $raw) {
                    $node->setAttribute('data-marker-party', $new);
                }
            }

            // 2. Inject a signature surface for any recipient that has none
            //    after re-keying. One surface per recipient is the guarantee;
            //    re-keying alone cannot fix an absent surface (#119 seller).
            $injected = false;
            foreach ($canonRecipients as $rc) {
                $exists = $xpath->query(
                    '//*[@data-marker-party="' . $rc['key'] . '"][@data-marker-type="signature"]'
                )->length > 0;
                if ($exists) {
                    continue;
                }
                if (!isset($sigSection)) {
                    $sigSection = $this->findOrCreateSignatureSection($dom, $xpath);
                }
                $sigSection->appendChild($this->buildSignatureBlock($dom, $rc));
                $injected = true;
            }

            $result = $dom->saveHTML();
            $result = trim(preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result));

            Log::info('SIGNING_SURFACE_RESOLVED', [
                'recipients'   => array_map(fn ($r) => $r['key'], $canonRecipients),
                'injected_any' => $injected,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('SIGNING_SURFACE_RESOLVE_FAILED', ['error' => $e->getMessage()]);
            return $bodyHtml;
        }
    }

    /**
     * The authoritative party set: each recipient's canonical role key
     * (family-collapsed, numeric suffix for multiples within a family) plus
     * the agent (always a signer). Non-recipient ticks never appear here —
     * recipient-driven by construction.
     */
    private function buildCanonicalRecipients(array $recipients, string $agentName, bool $isSales): array
    {
        $ownerCanon = $isSales ? 'seller' : 'landlord';
        $acqCanon   = $isSales ? 'buyer'  : 'tenant';
        $ownerDisp  = $isSales ? 'Seller' : 'Lessor';
        $acqDisp    = $isSales ? 'Buyer'  : 'Lessee';

        $out    = [];
        $counts = [];
        foreach ($recipients as $r) {
            $role = strtolower(preg_replace('/_\d+$/', '', $r['role'] ?? ''));
            if ($role === '' || in_array($role, self::AGENT_TERMS, true)) {
                continue; // agent added explicitly below
            }
            if (in_array($role, self::OWNER_TERMS, true)) {
                $canon = $ownerCanon;
                $disp  = $ownerDisp;
            } elseif (in_array($role, self::ACQUIRING_TERMS, true)) {
                $canon = $acqCanon;
                $disp  = $acqDisp;
            } else {
                continue; // unknown role — not a signing party
            }
            $counts[$canon] = ($counts[$canon] ?? 0) + 1;
            $key = $counts[$canon] === 1 ? $canon : $canon . '_' . $counts[$canon];
            $out[] = [
                'key'     => $key,
                'name'    => trim($r['name'] ?? '') ?: $disp,
                'display' => $disp,
            ];
        }

        $out[] = [
            'key'     => 'agent',
            'name'    => trim($agentName) ?: 'Agent',
            'display' => 'Agent',
        ];

        return $out;
    }

    /**
     * Locate the document's Signatures section so an injected surface sits
     * in the legally-correct place. If none exists, create one at the end
     * of the document.
     */
    private function findOrCreateSignatureSection(\DOMDocument $dom, \DOMXPath $xpath): \DOMElement
    {
        $sections = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " sig-section ")]'
        );
        if ($sections->length > 0) {
            /** @var \DOMElement $last */
            $last = $sections->item($sections->length - 1);
            return $last;
        }

        $section = $dom->createElement('div');
        $section->setAttribute('class', 'sig-section');
        $section->setAttribute('data-resolver-injected-section', 'true');

        $heading = $dom->createElement('p');
        $heading->setAttribute('class', 'corex-section-heading');
        $strong = $dom->createElement('strong');
        $strong->appendChild($dom->createTextNode('Signatures'));
        $heading->appendChild($strong);
        $section->appendChild($heading);

        $pages = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " corex-page ")]'
        );
        $parent = $pages->length > 0
            ? $pages->item($pages->length - 1)
            : $dom->documentElement;
        $parent->appendChild($section);

        return $section;
    }

    /**
     * Build a recipient's signature block — same structure and selectors
     * the signing engine already consumes (signature-block.blade.php), so
     * an injected surface is indistinguishable from a compiled one.
     */
    private function buildSignatureBlock(\DOMDocument $dom, array $rc): \DOMElement
    {
        $key  = $rc['key'];
        $name = $rc['name'];
        $disp = $rc['display'];

        $block = $dom->createElement('div');
        $block->setAttribute('class', 'sig-party-block');
        $block->setAttribute('data-resolver-injected', 'true');

        $p = $dom->createElement('p');
        $p->setAttribute('class', 'sig-text');
        $p->appendChild($dom->createTextNode(
            'Thus done and signed by the ' . $disp . ' (' . $name . ') at '
        ));

        $field = function (string $cls, string $type) use ($dom, $key): \DOMElement {
            $s = $dom->createElement('span');
            $s->setAttribute('class', $cls);
            $s->setAttribute('data-marker-party', $key);
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
        $line->setAttribute('data-marker-party', $key);
        $line->setAttribute('data-marker-type', 'signature');
        $line->setAttribute('data-marker-index', 'resolver-' . $key);
        $line->setAttribute('data-name', $name);
        $line->setAttribute('style', 'border-bottom:1px solid #333;min-height:28pt;');
        $cell->appendChild($line);

        $label = $dom->createElement('div');
        $label->setAttribute('class', 'sig-cell-label');
        $label->appendChild($dom->createTextNode($name !== '' ? $name : $disp));
        $cell->appendChild($label);

        $row->appendChild($cell);
        $block->appendChild($row);

        return $block;
    }
}
