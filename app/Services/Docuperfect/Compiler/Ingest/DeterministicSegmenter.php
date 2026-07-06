<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Contracts\SegmentationService;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Enums\LegalClass;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationWarning;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * AT-177 / WS4-E — deterministic segmentation (spec §3 step 2). Splits the normalized
 * intermediate into TYPED ADDRESSABLE BLOCKS with stable block_ids, replacing today's fragile
 * marker fuzzy-matching with a deterministic walk + human-confirmation warnings.
 *
 * Detects, per top-level block:
 *   - letterhead (a leading header/bordered block),
 *   - page_break (explicit break markers),
 *   - signature zones ("signature"/"signed"/"thus done and signed" or marker/sig surfaces) →
 *     a signature block with one anchor per party it can identify (else a flagged placeholder),
 *   - fill-points (`____` runs, underlined empty spans, data-field markers) → a field_group of
 *     UNBOUND fields (binding happens in the Studio; an unbound field cannot compile — L1),
 *   - everything else → prose / clause.
 *
 * Parties are INFERRED from the signature zones and role vocabulary as a starting point; the
 * operator confirms/edits topology in the Studio. legal_class is inferred from the title so an
 * Offer-to-Purchase seeds `alienation_of_land` (L7) with e-sign pre-disabled.
 */
final class DeterministicSegmenter implements SegmentationService
{
    /** role vocabulary → canonical party key. */
    private const ROLE_WORDS = [
        'seller' => 'seller', 'owner' => 'seller', 'transferor' => 'seller',
        'buyer' => 'buyer', 'purchaser' => 'buyer', 'transferee' => 'buyer',
        'agent' => 'agent', 'practitioner' => 'agent', 'broker' => 'agent',
        'landlord' => 'lessor', 'lessor' => 'lessor',
        'tenant' => 'lessee', 'lessee' => 'lessee',
        'witness' => 'witness',
    ];

    public function segment(IngestedDocument $document): SegmentationResult
    {
        $elements = $this->topLevelElements($document->normalizedHtml);

        $blocks = [];
        $warnings = [];
        $seenPartyRoles = [];
        $unbound = 0;
        $index = 0;

        foreach ($elements as $element) {
            $blockId = 'b' . (++$index);

            if ($this->isPageBreak($element)) {
                $blocks[] = $this->baseBlock($blockId, 'page_break');
                continue;
            }

            if ($index === 1 && $this->isLetterhead($element)) {
                $blocks[] = $this->baseBlock('letterhead', 'letterhead', $this->innerHtml($element));
                continue;
            }

            if ($this->isSignatureZone($element)) {
                [$block, $roles, $blockWarnings] = $this->signatureBlock($blockId, $element);
                $blocks[] = $block;
                $warnings = array_merge($warnings, $blockWarnings);
                foreach ($roles as $role) {
                    $seenPartyRoles[$role] = true;
                }
                continue;
            }

            $fields = $this->detectFillPoints($element);
            if ($fields !== []) {
                $unbound += count($fields);
                $blocks[] = array_merge($this->baseBlock($blockId, 'field_group', $this->innerHtml($element)), [
                    'editability' => ['mode' => 'only', 'party_keys' => ['agent']],
                    'fields' => $fields,
                ]);
                $warnings[] = SegmentationWarning::warn($blockId, 'unbound_fields', sprintf('%d fill-point(s) detected — bind each to a dictionary entry.', count($fields)));
                continue;
            }

            $type = $this->looksLikeClause($element) ? 'clause' : 'prose';
            $blocks[] = $this->baseBlock($blockId, $type, $this->innerHtml($element));
        }

        $legalClass = $this->inferLegalClass($document);
        $parties = $this->buildParties(array_keys($seenPartyRoles));
        if ($parties === []) {
            $warnings[] = SegmentationWarning::warn('', 'no_parties', 'No signing parties could be identified — declare them in the Studio.');
        }

        $structure = [
            'family' => (string) ($document->meta['family'] ?? $document->sourceRef),
            'data_dictionary_version' => (int) ($document->meta['data_dictionary_version'] ?? 1),
            'legal_class' => $legalClass->value,
            'delivery_modes' => array_map(
                static fn (DeliveryMode $m): string => $m->value,
                $legalClass->forbidsEsign()
                    ? [DeliveryMode::PdfWetInk, DeliveryMode::Download]
                    : [DeliveryMode::WebEsign, DeliveryMode::PdfWetInk, DeliveryMode::Download],
            ),
            'parties' => $parties,
            'blocks' => $blocks,
            'assets' => [],
        ];

        return new SegmentationResult($structure, $warnings, $this->confidence($blocks, $warnings, $unbound));
    }

    // ── classification ────────────────────────────────────────────────────────

    private function isPageBreak(DOMElement $el): bool
    {
        $class = $el->getAttribute('class');
        $style = $el->getAttribute('style');

        return str_contains($class, 'page-break')
            || str_contains($style, 'page-break')
            || (strtolower($el->tagName) === 'hr' && str_contains($class, 'page'));
    }

    private function isLetterhead(DOMElement $el): bool
    {
        $tag = strtolower($el->tagName);
        $class = strtolower($el->getAttribute('class'));
        $style = $el->getAttribute('style');

        return $tag === 'header'
            || str_contains($class, 'letterhead')
            || str_contains($class, 'header')
            || str_contains($class, 'company-header')
            // The CoreX letterhead needle (see LetterheadRefresher).
            || (str_contains($style, 'border:1px solid #000') && str_contains($style, 'padding:4px 8px'));
    }

    private function isSignatureZone(DOMElement $el): bool
    {
        // Explicit compiled/legacy signable surfaces — check the element ITSELF and descendants
        // (the live signature-line partial puts data-marker-party ON the span itself).
        if ($this->xpathHas($el, 'descendant-or-self::*[@data-marker-type="signature"] | descendant-or-self::*[@data-anchor-kind="signature"] | descendant-or-self::*[contains(@class,"sig-cell-line")] | descendant-or-self::*[contains(@class,"sig-inline-line")]')) {
            return true;
        }

        // A textual signature cue alone is NOT enough — a bare "THUS DONE AND SIGNED" heading is
        // prose, and the real signatures follow. Require the cue PLUS a signing party or a
        // fill-line (so "Signature of the Agent" / "Signature: ____" qualify, the heading doesn't).
        $raw = $this->textOf($el);
        $text = strtolower($raw);
        $hasCue = str_contains($text, 'thus done and signed')
            || str_contains($text, 'signature')
            || (bool) preg_match('/\bsigned\b.{0,40}\b(at|on|by)\b/', $text);
        if (! $hasCue) {
            return false;
        }

        return $this->rolesInText($text) !== [] || preg_match('/_{3,}/', $raw) === 1;
    }

    private function looksLikeClause(DOMElement $el): bool
    {
        $class = strtolower($el->getAttribute('class'));
        if (str_contains($class, 'clause')) {
            return true;
        }

        return (bool) preg_match('/^\s*\d+(\.\d+)*[\.\)]?\s+\S/', $this->textOf($el));
    }

    // ── fill-point + signature extraction ──────────────────────────────────────

    /** @return list<array<string,mixed>> unbound fields */
    private function detectFillPoints(DOMElement $el): array
    {
        $fields = [];

        // (a) Explicit field markers from a CDS-rendered / tagged document.
        foreach ($this->xpathNodes($el, './/*[@data-field] | .//*[@data-field-id]') as $node) {
            $label = $node->getAttribute('data-field') ?: $node->getAttribute('data-field-id');
            $fields[] = $this->unboundField(count($fields) + 1, $label !== '' ? $label : $this->labelBefore($node));
        }

        // (b) Underlined empty spans (fill lines) that are NOT signature surfaces.
        foreach ($this->xpathNodes($el, './/span[contains(@style,"border-bottom")]') as $node) {
            if (trim($this->textOf($node)) === '' && ! $node->hasAttribute('data-marker-type')) {
                $fields[] = $this->unboundField(count($fields) + 1, $this->labelBefore($node));
            }
        }

        // (c) Underscore fill-runs in text ("Purchase Price: ____") — capture the preceding label.
        if (preg_match_all('/([^_]{0,60}?)_{3,}/u', $this->textOf($el), $matches)) {
            foreach ($matches[1] as $before) {
                $words = preg_split('/\s+/', trim((string) $before)) ?: [];
                $label = trim(implode(' ', array_slice($words, -4)), " :\u{00A0}");
                $fields[] = $this->unboundField(count($fields) + 1, $label);
            }
        }

        return $fields;
    }

    /**
     * @return array{0:array<string,mixed>,1:list<string>,2:list<SegmentationWarning>}
     */
    private function signatureBlock(string $blockId, DOMElement $el): array
    {
        $roles = [];

        // Explicit marker parties first (element itself and descendants).
        foreach ($this->xpathNodes($el, 'descendant-or-self::*[@data-marker-party] | descendant-or-self::*[@data-anchor-party]') as $node) {
            $party = $node->getAttribute('data-marker-party') ?: $node->getAttribute('data-anchor-party');
            $role = $this->roleBase($party);
            if ($role !== null) {
                $roles[$role] = true;
            }
        }
        // Fall back to role words in the zone text.
        if ($roles === []) {
            foreach ($this->rolesInText($this->textOf($el)) as $role) {
                $roles[$role] = true;
            }
        }

        $warnings = [];
        $anchors = [];
        if ($roles === []) {
            $anchors[] = ['anchor_id' => $blockId . '_sig', 'kind' => 'signature', 'party_key' => 'signatory'];
            $warnings[] = SegmentationWarning::warn($blockId, 'signature_party_unknown', 'A signature zone was found but its party is unclear — assign it in the Studio.');
        } else {
            foreach (array_keys($roles) as $role) {
                $anchors[] = ['anchor_id' => $blockId . '_' . $role, 'kind' => 'signature', 'party_key' => $role];
            }
        }

        // A signature block is visible to its own party (single-role) or all (multi-role zone).
        $visibility = count($roles) === 1 ? ['mode' => 'only', 'party_keys' => array_keys($roles)] : ['mode' => 'all'];

        $block = array_merge($this->baseBlock($blockId, 'signature'), [
            'visibility' => $visibility,
            'anchors' => $anchors,
        ]);

        return [$block, array_keys($roles), $warnings];
    }

    // ── party + legal inference ────────────────────────────────────────────────

    /** @param list<string> $roles @return list<array<string,mixed>> */
    private function buildParties(array $roles): array
    {
        // Deterministic ordering: agent first, then seller/lessor, then buyer/lessee, witness last.
        $order = ['agent' => 1, 'seller' => 2, 'lessor' => 2, 'buyer' => 3, 'lessee' => 3, 'witness' => 9];
        usort($roles, static fn (string $a, string $b): int => ($order[$a] ?? 5) <=> ($order[$b] ?? 5));

        $parties = [];
        foreach ($roles as $i => $role) {
            $parties[] = [
                'key' => $role,
                'role' => ucfirst($role),
                'cardinality' => in_array($role, ['seller', 'buyer', 'lessor', 'lessee', 'witness'], true) ? 'one_or_more' : 'one',
                'required' => true,
                'ordering' => $i + 1,
            ];
        }

        return $parties;
    }

    private function inferLegalClass(IngestedDocument $document): LegalClass
    {
        $haystack = strtolower(($document->meta['title'] ?? '') . ' ' . substr(strip_tags($document->normalizedHtml), 0, 400));

        foreach (['offer to purchase', 'deed of sale', 'agreement of sale', 'sale agreement', 'alienation of land'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return LegalClass::AlienationOfLand;
            }
        }
        if (str_contains($haystack, 'last will') || str_contains($haystack, 'testament')) {
            return LegalClass::Will;
        }

        return LegalClass::General;
    }

    private function confidence(array $blocks, array $warnings, int $unbound): float
    {
        if ($blocks === []) {
            return 0.0;
        }
        $penalty = 0.06 * count($warnings) + 0.02 * $unbound;

        return max(0.2, round(1.0 - $penalty, 2));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function baseBlock(string $blockId, string $type, ?string $html = null): array
    {
        $block = [
            'block_id' => $blockId,
            'type' => $type,
            'visibility' => ['mode' => 'all'],
            'editability' => ['mode' => 'none'],
            'condition' => ['kind' => 'always'],
        ];
        if ($html !== null) {
            $block['html'] = $html;
        }

        return $block;
    }

    private function unboundField(int $n, string $label): array
    {
        return [
            'field_id' => 'f' . $n,
            'label' => $label !== '' ? $label : 'Field ' . $n,
            'binding' => '',
            'source' => 'agent_input',
            'required' => true,
        ];
    }

    /** @return list<DOMElement> */
    private function topLevelElements(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__seg_root__">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = (new \DOMXPath($dom))->query('//*[@id="__seg_root__"]')->item(0);
        if (! $root instanceof DOMElement) {
            return [];
        }

        $out = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private function innerHtml(DOMElement $el): string
    {
        return $el->ownerDocument?->saveHTML($el) ?? '';
    }

    private function textOf(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }

    private function labelBefore(DOMNode $node): string
    {
        $prev = $node->previousSibling;
        while ($prev !== null) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $prev->textContent));
            if ($text !== '') {
                $words = preg_split('/\s+/', $text) ?: [];

                return trim(implode(' ', array_slice($words, -4)), " :\u{00A0}");
            }
            $prev = $prev->previousSibling;
        }

        return '';
    }

    /** @return list<string> distinct canonical roles found in text */
    private function rolesInText(string $text): array
    {
        $text = strtolower($text);
        $found = [];
        foreach (self::ROLE_WORDS as $word => $role) {
            if (preg_match('/\b' . preg_quote($word, '/') . 's?\b/', $text)) {
                $found[$role] = true;
            }
        }

        return array_keys($found);
    }

    private function roleBase(string $partyKey): ?string
    {
        $base = strtolower(preg_replace('/_\d+$/', '', $partyKey) ?? $partyKey);

        return self::ROLE_WORDS[$base] ?? (in_array($base, self::ROLE_WORDS, true) ? $base : null);
    }

    private function xpathHas(DOMElement $el, string $query): bool
    {
        return $this->xpathNodes($el, $query) !== [];
    }

    /** @return list<DOMElement> */
    private function xpathNodes(DOMElement $el, string $query): array
    {
        $xpath = new \DOMXPath($el->ownerDocument);
        $nodes = $xpath->query($query, $el);
        if ($nodes === false) {
            return [];
        }
        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }
}
