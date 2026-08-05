<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WET-INK SELECTION edit (Johan 2026-08-05, correct UX) — the agent HIGHLIGHTS the exact word / phrase /
 * clause anywhere in the rendered document (no clause numbers). The frontend sends the selected text plus a
 * little context (prefix/suffix) to disambiguate duplicates; this service locates that EXACT span in
 * merged_html and authors, INLINE where it sits:
 *
 *   <span class="change-inline" data-strikethrough-applied="1" data-change-id>
 *       <del class="change-del">{selected}</del> <ins class="change-ins">{replacement}</ins>
 *   </span>
 *   + a right-margin INITIAL BLOCK with one slot per signing party (wet-ink margin initials).
 *
 * The struck old text STAYS visible (wet-ink marked-up contract). Uses cc1's shared classes so styling is
 * one visual language and cc1's body-diff DEFERS (data-strikethrough-applied). data-change-id =
 * sha1(prefix|selected|replacement)[:12]. Every change is captured in web_template_data['pending_body_changes'].
 */
final class SelectionEditService
{
    public function __construct(private SignatureService $signatureService)
    {
    }

    public function strikeSelection(
        SignatureTemplate $template,
        string $selected,
        string $prefix,
        string $suffix,
        string $replacement,
        ?User $actor,
        string $mode = 'inline'
    ): array {
        // Three modes (esign-returned-doc-edit-flow.md §3): 'inline' = strike + reword in place; 'reference' =
        // strike + route the full replacement to a numbered Other Condition; 'strike' = pure strike-out with NO
        // replacement (delete an unwanted alternative / clause). Anything unexpected falls back to inline.
        $mode = in_array($mode, ['reference', 'strike'], true) ? $mode : 'inline';
        $document = $template->document;
        if (! $document) {
            return ['ok' => false, 'error' => 'Document not found.'];
        }
        $selected = trim($selected);
        if ($selected === '') {
            return ['ok' => false, 'error' => 'Highlight the text you want to change first.'];
        }
        // A pure strike-out has no replacement; the other two modes require one.
        if ($mode !== 'strike' && trim($replacement) === '') {
            return ['ok' => false, 'error' => 'Enter the replacement text.'];
        }
        if ($mode === 'strike') {
            $replacement = '';
        }

        $wtd  = is_array($document->web_template_data) ? $document->web_template_data : [];
        // WET-INK: overlay the strike onto the SIGNED canonical when ink is baked (so every signature + the
        // execution/location block carry through byte-intact) — never onto the un-inked merged_html source.
        $canvas = CanonicalDocumentRenderer::amendSource($wtd);
        $html   = $canvas['html'];
        if (trim($html) === '') {
            return ['ok' => false, 'error' => 'Document has no editable body.'];
        }

        $dom = new \DOMDocument();
        if (! @$dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        )) {
            return ['ok' => false, 'error' => 'Could not parse the document body.'];
        }
        $xpath = new \DOMXPath($dom);

        // Locate the selected span: find the text node + offset whose text equals $selected AND whose
        // surrounding text best matches the sent context. Single-text-node selections (the common case:
        // a word/phrase inside a paragraph). Returns [textNode, offset] or null.
        $hit = $this->locate($dom, $selected, $prefix, $suffix);
        if ($hit === null) {
            return ['ok' => false, 'error' => 'Could not locate the highlighted text — try selecting it again.'];
        }
        [$node, $offset] = $hit;

        $changeId = substr(sha1($this->norm($prefix) . '|' . $selected . '|' . $replacement), 0, 12);

        $baked = $canvas['baked'];
        $result = DB::transaction(function () use ($template, $document, $dom, $node, $offset, $selected, $replacement, $changeId, $actor, $wtd, $mode, $baked) {
            $ocNumber = null;
            $condition = null;
            if ($mode === 'reference') {
                // BIG change → hold the full replacement as an Other-Conditions entry (#N); the struck span
                // then carries a "See Other Conditions — clause N" cross-reference instead of an inline insert.
                $next = (int) DocumentCondition::query()
                    ->where('signature_template_id', $template->id)
                    ->where('block_id', 'other_conditions')
                    ->whereNull('superseded_at')
                    ->max('condition_number');
                $ocNumber  = $next + 1;
                $condition = DocumentCondition::create([
                    'signature_template_id' => $template->id,
                    'agency_id'             => $actor?->effectiveAgencyId(),
                    'block_id'              => 'other_conditions',
                    'block_purpose'         => 'other_conditions',
                    'condition_number'      => $ocNumber,
                    'content'               => sprintf('Amendment to "%s": %s', Str::limit($selected, 60), $replacement),
                    'is_override'           => true,
                    'overrides_clause_ref'  => null,
                    'added_by_user_id'      => $actor?->id,
                    'added_via'             => 'agent_signing',
                    'source'                => 'custom',
                ]);
            }

            $this->authorStrike($dom, $node, $offset, $selected, $replacement, $changeId, $template, $mode, $ocNumber);

            DocumentClauseStrikethrough::create([
                'signature_template_id'    => $template->id,
                'agency_id'                => $actor?->effectiveAgencyId(),
                'clause_ref'               => 'selection',           // selection-based; no printed clause ref
                'clause_original_text'     => $selected,
                'replacement_condition_id' => $condition?->id,
                'proposed_by_user_id'      => $actor?->id,
                'amendment_id'             => 0,
                'status'                   => DocumentClauseStrikethrough::STATUS_PROPOSED,
            ]);

            $newHtml = $this->innerHtml($dom, $xpath = new \DOMXPath($dom));
            $changes = $wtd['pending_body_changes'] ?? [];
            $changes[] = [
                'change_id' => $changeId,
                'mode'      => $mode === 'reference' ? 'reference' : ($mode === 'strike' ? 'strike' : 'selection'),
                'old'       => $selected,
                'new'       => $replacement,
                'oc_ref'    => $ocNumber,
                'actor_id'  => $actor?->id,
                'actor_name'=> $actor?->name,
                'at'        => now()->toIso8601String(),
            ];
            $wtd['pending_body_changes'] = $changes;
            $wtd['amendment_render']     = true;
            // Overlay onto the signed canonical when baked (ink + location preserved); else merged_html + recompose.
            $wtd = CanonicalDocumentRenderer::writeAmend($wtd, $newHtml, $baked);
            $document->update(['web_template_data' => $wtd]);

            return ['ok' => true, 'change_id' => $changeId, 'old' => $selected, 'mode' => $mode, 'oc_ref' => $ocNumber];
        });

        return $result;
    }

    /** @return array{0: \DOMText, 1: int}|null */
    private function locate(\DOMDocument $dom, string $selected, string $prefix, string $suffix): ?array
    {
        $xpath = new \DOMXPath($dom);
        $textNodes = $xpath->query('//text()');
        if ($textNodes === false) {
            return null;
        }

        $best = null;
        $bestScore = -1;
        $normSuffix = $this->norm($suffix);
        $normPrefix = $this->norm($prefix);

        foreach ($textNodes as $tn) {
            if (! $tn instanceof \DOMText) {
                continue;
            }
            $t = $tn->nodeValue ?? '';
            $from = 0;
            while (($pos = mb_strpos($t, $selected, $from)) !== false) {
                // Skip if this node is already inside an authored change mark.
                if ($this->insideChangeMark($tn)) {
                    $from = $pos + 1;
                    continue;
                }
                // Score by context match within the same node (cheap + robust for single-node selections).
                $before = $this->norm(mb_substr($t, max(0, $pos - 40), min(40, $pos)));
                $after  = $this->norm(mb_substr($t, $pos + mb_strlen($selected), 40));
                $score = 0;
                if ($normPrefix !== '' && str_ends_with($before, mb_substr($normPrefix, -20))) $score += 2;
                if ($normSuffix !== '' && str_starts_with($after, mb_substr($normSuffix, 0, 20))) $score += 2;
                if ($normPrefix === '' && $normSuffix === '') $score += 1; // no context → first match
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [$tn, $pos];
                }
                $from = $pos + 1;
            }
        }
        return $best;
    }

    private function insideChangeMark(\DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof \DOMElement && $p->getAttribute('data-strikethrough-applied') === '1') {
                return true;
            }
        }
        return false;
    }

    /** Split $node at the selected span and author the inline strike + margin initial block in place. */
    private function authorStrike(\DOMDocument $dom, \DOMText $node, int $offset, string $selected, string $replacement, string $changeId, SignatureTemplate $template, string $mode = 'inline', ?int $ocNumber = null): void
    {
        $full   = $node->nodeValue ?? '';
        $before = mb_substr($full, 0, $offset);
        $after  = mb_substr($full, $offset + mb_strlen($selected));

        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        $frag = [];
        if ($before !== '') {
            $frag[] = $dom->createTextNode($before);
        }

        $wrap = $dom->createElement('span');
        $wrap->setAttribute('class', 'change-inline');
        $wrap->setAttribute('data-strikethrough-applied', '1');
        $wrap->setAttribute('data-change-id', $changeId);

        $del = $dom->createElement('del');
        $del->setAttribute('class', 'change-del');
        $del->setAttribute('data-change-id', $changeId);
        if ($mode === 'reference' && $ocNumber !== null) {
            $del->setAttribute('data-oc-ref', (string) $ocNumber);
        }
        $del->appendChild($dom->createTextNode($selected));
        $wrap->appendChild($del);
        if ($mode === 'strike') {
            // Pure strike-out — the deleted text stands struck through with NO replacement and NO cross-reference.
        } elseif ($mode === 'reference' && $ocNumber !== null) {
            // BIG change → cross-reference the Other-Conditions entry instead of inlining the replacement.
            $wrap->appendChild($dom->createTextNode(' '));
            $xref = $dom->createElement('span');
            $xref->setAttribute('class', 'change-xref');
            $xref->setAttribute('data-change-id', $changeId);
            $xref->setAttribute('data-oc-ref', (string) $ocNumber);
            $xref->appendChild($dom->createTextNode('See Other Conditions — clause ' . $ocNumber));
            $wrap->appendChild($xref);
        } else {
            $wrap->appendChild($dom->createTextNode(' '));
            $ins = $dom->createElement('ins');
            $ins->setAttribute('class', 'change-ins');
            $ins->setAttribute('data-change-id', $changeId);
            $ins->appendChild($dom->createTextNode($replacement));
            $wrap->appendChild($ins);
        }

        $frag[] = $wrap;
        if ($after !== '') {
            $frag[] = $dom->createTextNode($after);
        }
        foreach ($frag as $n) {
            $parent->insertBefore($n, $node);
        }
        $parent->removeChild($node);

        // FULL-WIDTH INITIAL ROW — inserted as a block right after the clause/paragraph the change sits in.
        // One labeled slot per signing party; each party APPLIES THEIR REAL INITIAL in their own slot (the
        // slot opens the same capture modal the rest of the document uses). Replaces the squashed margin block.
        $row = $this->buildInitialRow($dom, $changeId, $template);
        $block = $this->closestBlock($wrap);
        if ($block instanceof \DOMElement && $block->parentNode) {
            if ($block->nextSibling) {
                $block->parentNode->insertBefore($row, $block->nextSibling);
            } else {
                $block->parentNode->appendChild($row);
            }
        } else {
            $wrap->appendChild($row); // fallback — keep the row attached to the change
        }
    }

    /** Build the full-width per-party initial row for a change. cc1 styling class family: change-initial-row. */
    private function buildInitialRow(\DOMDocument $dom, string $changeId, SignatureTemplate $template): \DOMElement
    {
        $row = $dom->createElement('div');
        $row->setAttribute('class', 'change-initial-row');
        $row->setAttribute('data-change-id', $changeId);
        $row->setAttribute('contenteditable', 'false');
        $label = $dom->createElement('span');
        $label->setAttribute('class', 'cir-label');
        $label->appendChild($dom->createTextNode('Initial this change:'));
        $row->appendChild($label);
        foreach ($this->parties($template) as $party) {
            $slot = $dom->createElement('span');
            $slot->setAttribute('class', 'cir-slot');
            $slot->setAttribute('data-change-id', $changeId);
            $slot->setAttribute('data-party-key', (string) ($party['key'] ?? ''));
            $slot->setAttribute('data-party-name', (string) ($party['name'] ?? ''));
            $nameSpan = $dom->createElement('span');
            $nameSpan->setAttribute('class', 'cir-name');
            $nameSpan->appendChild($dom->createTextNode((string) ($party['name'] ?? 'Party')));
            $slot->appendChild($nameSpan);
            $ink = $dom->createElement('span');
            $ink->setAttribute('class', 'cir-ink');
            $ink->setAttribute('data-empty', '1');
            $ink->appendChild($dom->createTextNode('—'));
            $slot->appendChild($ink);
            $row->appendChild($slot);
        }
        return $row;
    }

    /** Closest block-level ancestor of $node (so the row sits under the clause/paragraph, full width). */
    private function closestBlock(\DOMNode $node): ?\DOMElement
    {
        $blocks = ['p', 'div', 'li', 'td', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'blockquote'];
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof \DOMElement
                && in_array(strtolower($p->nodeName), $blocks, true)
                && $p->getAttribute('id') !== '__root'
                && ! str_contains($p->getAttribute('class'), 'change-inline')) {
                return $p;
            }
        }
        return $node instanceof \DOMElement ? $node : null;
    }

    /** @return array<int, array{key:string, name:string}> */
    private function parties(SignatureTemplate $template): array
    {
        $out = [];
        foreach ($template->requests()->orderBy('signing_order')->get() as $r) {
            $out[] = [
                'key'  => method_exists($r, 'canonicalPartyKey') ? $r->canonicalPartyKey() : (string) $r->party_role,
                'name' => (string) ($r->signer_name ?: ucfirst((string) $r->party_role)),
            ];
        }
        return $out;
    }

    /**
     * Fill ONE party's margin slot for a change (wet-ink: each party initials their OWN slot, independently).
     * Marks the cm-slot[data-party-key] under the change's margin as filled + writes the party's initials as
     * their ink. Idempotent. Returns the html unchanged when the slot isn't present.
     */
    public function fillRowSlot(string $html, string $changeId, string $partyKey, string $name, ?string $imageDataUrl = null): string
    {
        if (trim($html) === '' || ! str_contains($html, 'data-change-id="' . $changeId . '"')) {
            return $html;
        }
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath = new \DOMXPath($dom);
            $slots = $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " change-initial-row ") and @data-change-id=' . $this->xpathLiteral($changeId) . ']'
                . '//*[contains(concat(" ", normalize-space(@class), " "), " cir-slot ") and @data-party-key=' . $this->xpathLiteral($partyKey) . ']'
            );
            if ($slots === false || $slots->length === 0) {
                return $html;
            }
            $isImg = is_string($imageDataUrl) && str_starts_with(trim($imageDataUrl), 'data:image');
            foreach ($slots as $slot) {
                if (! $slot instanceof \DOMElement) {
                    continue;
                }
                $cls = $slot->getAttribute('class');
                if (! str_contains(' ' . $cls . ' ', ' cir-filled ')) {
                    $slot->setAttribute('class', trim($cls . ' cir-filled'));
                }
                foreach (iterator_to_array($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " cir-ink ")]', $slot)) as $ink) {
                    if (! $ink instanceof \DOMElement) {
                        continue;
                    }
                    $ink->removeAttribute('data-empty');
                    while ($ink->firstChild) {
                        $ink->removeChild($ink->firstChild);
                    }
                    if ($isImg) {
                        // Render the party's REAL captured initial image (same ink the rest of the doc uses).
                        $img = $dom->createElement('img');
                        $img->setAttribute('src', (string) $imageDataUrl);
                        $img->setAttribute('class', 'cir-ink-img');
                        $img->setAttribute('alt', 'Initial of ' . $name);
                        $ink->appendChild($img);
                    } else {
                        $ink->appendChild($dom->createTextNode($this->initials($name)));
                    }
                }
            }
            return $this->innerHtml($dom, $xpath);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /** True when the change has a full-width row slot for this party. */
    public function hasRowSlot(string $html, string $changeId, string $partyKey): bool
    {
        if (trim($html) === '' || ! str_contains($html, 'data-change-id="' . $changeId . '"')) {
            return false;
        }
        return str_contains($html, 'change-initial-row')
            && preg_match('/data-party-key="' . preg_quote($partyKey, '/') . '"/', $html) === 1;
    }

    /** True when THIS party has already applied their initial to THIS change's row slot (cir-filled). */
    public function rowSlotFilled(string $html, string $changeId, string $partyKey): bool
    {
        if (! $this->hasRowSlot($html, $changeId, $partyKey)) {
            return false;
        }
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath = new \DOMXPath($dom);
            $slots = $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " change-initial-row ") and @data-change-id=' . $this->xpathLiteral($changeId) . ']'
                . '//*[contains(concat(" ", normalize-space(@class), " "), " cir-slot ") and @data-party-key=' . $this->xpathLiteral($partyKey) . ']'
            );
            if ($slots === false || $slots->length === 0) {
                return false;
            }
            foreach ($slots as $slot) {
                if ($slot instanceof \DOMElement
                    && str_contains(' ' . $slot->getAttribute('class') . ' ', ' cir-filled ')) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Public accessor for the ordered signing-party set (key + name) — the universal party list a change's row is built from. */
    public function partiesFor(SignatureTemplate $template): array
    {
        return $this->parties($template);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out = '';
        foreach ($parts as $p) {
            if ($p === '') continue;
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($out) >= 3) break;
        }
        return $out !== '' ? $out : '✓';
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (! str_contains($value, '"')) {
            return "\"{$value}\"";
        }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }

    private function innerHtml(\DOMDocument $dom, \DOMXPath $xpath): string
    {
        $root = $xpath->query('//*[@id="__root"]')->item(0);
        if (! $root instanceof \DOMElement) {
            return (string) $dom->saveHTML();
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    private function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
