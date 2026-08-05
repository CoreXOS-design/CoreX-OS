<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use DOMElement;
use DOMText;
use DOMXPath;

/**
 * Returned-document CHANGE-HIGHLIGHT renderer (RENDER/versioning half — AT-368).
 *
 * WET-INK model (Johan, LOCKED 2026-08-04): when an agent edits a RETURNED document, the rendered
 * document marks every change so it JUMPS OUT — struck-through removals + inline write-ins, exactly like a
 * pen-marked paper contract. Marks STAY on the final document. Two change classes:
 *
 *   A) FIELD VALUES  — a `data-field` value differs from the last-authorised baseline →
 *                      <del>old</del> <ins>new</ins> (matched by the exact base/`__r{n}` key, so a
 *                      per-recipient edit marks only that recipient's instance).
 *   B) CLAUSE/BODY   — printed clause/body text edited → word-level LCS diff within aligned blocks:
 *                      SMALL change → inline strike+insert; BIG change (> threshold words, or whole-clause
 *                      replacement) → strike the clause + a visible cross-reference to the Other-Conditions
 *                      entry that carries the replacement (that OC entry is created by cc6's flow half).
 *
 * This is a DISPLAY overlay applied as the LAST pass of CanonicalDocumentRenderer::compose(), gated on
 * `web_template_data['amendment_render']` + the presence of a last-authorised sealed baseline. It never
 * bakes into the sealed content and never runs on a normal (never-returned) document. Fail-safe: any
 * error returns the un-highlighted HTML — the change-mark layer must never break a document.
 *
 * Spec: .ai/specs/esign-returned-doc-change-highlight.md. Boundary with cc6 (flow/status/OC-entry) is the
 * `amendment_render` flag + the current canonical; this class only READS them.
 */
class DocumentChangeHighlighter
{
    /** SMALL↔BIG classification: a clause changing more than this many words routes to Other Conditions. */
    private const BIG_CHANGE_WORDS = 8;

    /** Clause/paragraph-level blocks we align + word-diff for class B. */
    private const BLOCK_SELECTOR = '//*[contains(concat(" ", normalize-space(@class), " "), " corex-clause ")]';

    /**
     * Overlay wet-ink change-marks onto $currentHtml, diffing against the last-authorised $baselineHtml.
     * Returns $currentHtml unchanged when there is nothing to diff or on any failure.
     */
    /**
     * @param  array<string,array{name?:string,at?:string}> $initials  per-change initial state keyed by
     *         data-change-id — the cc6 contract (`web_template_data['change_initials']`). When a change's
     *         id is present, the mark shows "✓ initialed by {name}". Absent ids read as still-pending.
     */
    /**
     * @param array<int,array{change_id?:string,select:string,nth?:int,insert?:string}> $bodyChanges
     *        SELECTION-driven edits (cc6 captures an arbitrary text selection anywhere in the rendered
     *        doc): `select` = the exact text struck, `nth` = 1-based occurrence (disambiguates duplicates),
     *        `insert` = the replacement written in after it (empty = deletion-only). Persisted in
     *        web_template_data['body_changes']; re-applied every compose (NOT baked) so marks survive
     *        re-render and persist to final.
     * @param array<int,array{key:string,label:string}> $parties  signing parties for the right-margin
     *        initial blocks (one slot per party per change).
     */
    public function highlight(string $currentHtml, string $baselineHtml, array $initials = [], array $bodyChanges = [], array $parties = []): string
    {
        if (trim($currentHtml) === '') {
            return $currentHtml;
        }

        try {
            $detector = app(RoleBlockDetectionService::class);
            $curDom = $detector->loadFragment($currentHtml);
            if ($curDom === null) {
                return $currentHtml;
            }
            $curX = new DOMXPath($curDom);

            $changed = false;
            $changes = [];   // collected for the appended Schedule of Amendments (decision #2)

            // ---- SELECTION-driven body changes (the primary edit path): strike an arbitrary selected
            // span in place + write the replacement after it, with right-margin initial blocks for all
            // parties. Runs first so the field/clause passes below see + skip the marks. ----
            if ($this->applyBodyChanges($curDom, $curX, $bodyChanges, $changes, $initials, $parties)) {
                $changed = true;
            }

            // Diff against the last-authorised baseline (field + clause) — ONLY when we have one. With an
            // empty baseline (e.g. the doc is already re-authorised: cc6 cleared amendment_render but its
            // baked clause strikes must STAY styled on the final document) we skip the diff and just absorb
            // + style the pre-authored marks in the pass further down.
            $baseDom = trim($baselineHtml) !== '' ? $detector->loadFragment($baselineHtml) : null;
            if ($baseDom !== null) {
            $baseX = new DOMXPath($baseDom);

            // ---- Class A: field-value changes (data-field / data-field-name) ----
            $baseFields = $this->indexFieldTexts($baseX);
            foreach ($curX->query('//*[@data-field] | //*[@data-field-name]') as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $key = $node->getAttribute('data-field') ?: $node->getAttribute('data-field-name');
                if ($key === '' || ! array_key_exists($key, $baseFields)) {
                    continue;
                }
                $old = $baseFields[$key];
                $new = trim($node->textContent);
                if ($this->norm($old) === $this->norm($new)) {
                    continue;
                }
                // A data-field span may itself carry marks elsewhere (§7.5 strikethrough) — skip those.
                if (str_contains($node->getAttribute('class'), 'change-') ) {
                    continue;
                }
                $changeId = $this->replaceChildrenWithWetInk($node, $old, $new, $initials, $parties);
                $changes[] = ['id' => $changeId, 'where' => $this->prettyFieldLabel($key), 'old' => $old, 'new' => $new, 'mode' => 'field', 'initialed' => $this->anyInitial($initials, $changeId)];
                $changed = true;
            }

            // ---- Class B: clause/body word-level changes ----
            // Align clause blocks by ordinal within the document (same template → same clause order).
            $curBlocks = $this->collectBlocks($curX);
            $baseBlocks = $this->collectBlocks($baseX);
            $baseByKey = [];
            foreach ($baseBlocks as $b) {
                $baseByKey[$b['key']] ??= $b['text'];
            }
            foreach ($curBlocks as $blk) {
                /** @var DOMElement $el */
                $el = $blk['node'];
                // Never touch a block that carries a field mark we just made, or an existing §7.5 mark.
                if ($this->containsChangeMark($el) || $this->containsExistingAmendment($el)) {
                    continue;
                }
                if (! array_key_exists($blk['key'], $baseByKey)) {
                    continue; // no confidently-aligned baseline block → skip (fail-safe, never guess)
                }
                $oldText = $baseByKey[$blk['key']];
                $newText = $blk['text'];
                if ($this->norm($oldText) === $this->norm($newText)) {
                    continue;
                }
                if ($this->renderBlockChange($curDom, $el, $oldText, $newText, $changes, $initials)) {
                    $changed = true;
                }
            }
            } // end if ($baseDom !== null)

            // ---- Absorb cc6's PRE-AUTHORED marks (ClauseEditService bakes struck clauses + OC cross-refs
            // straight into merged_html using OUR classes + data-change-id). They arrive already styled by
            // our CSS but WITHOUT an initialed tag / appendix row — and if they are the ONLY changes, our
            // own passes found nothing, so the style/appendix would not emit. Fold them into one render:
            // one CSS injection, one Schedule of Amendments, one per-change initial pass, both sources. ----
            if ($this->absorbPreAuthoredMarks($curX, $changes, $initials)) {
                $changed = true;
            }

            if (! $changed) {
                return $currentHtml;
            }

            $out = $detector->serializeFragment($curDom);
            if ($out === '') {
                return $currentHtml;
            }
            // Decision #2 — append the Schedule of Amendments so the change record persists on the final
            // document alongside the inline marks.
            return $this->styleBlock() . $out . $this->appendixHtml($changes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DocumentChangeHighlighter failed (non-fatal, marks skipped)', [
                'error' => $e->getMessage(),
            ]);
            return $currentHtml;
        }
    }

    /** Map every data-field/data-field-name key → its trimmed text in the baseline. */
    private function indexFieldTexts(DOMXPath $x): array
    {
        $map = [];
        foreach ($x->query('//*[@data-field] | //*[@data-field-name]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $key = $node->getAttribute('data-field') ?: $node->getAttribute('data-field-name');
            if ($key !== '') {
                $map[$key] = trim($node->textContent);
            }
        }
        return $map;
    }

    /** Clause blocks, keyed by role-token + ordinal so current↔baseline align deterministically. */
    private function collectBlocks(DOMXPath $x): array
    {
        $out = [];
        $ordinal = [];
        foreach ($x->query(self::BLOCK_SELECTOR) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            // A block that itself contains a data-field is a value line (class A owns it) — skip for B.
            if ($x->query('.//*[@data-field] | .//*[@data-field-name]', $node)->length > 0) {
                continue;
            }
            $token = $node->getAttribute('data-role-block') ?: 'clause';
            $ordinal[$token] = ($ordinal[$token] ?? 0) + 1;
            $out[] = [
                'node' => $node,
                'key' => $token . '#' . $ordinal[$token],
                'text' => trim(preg_replace('/\s+/', ' ', $node->textContent)),
            ];
        }
        return $out;
    }

    /** Replace a field span's children with wet-ink: struck old + inline new. Returns the change id. */
    private function replaceChildrenWithWetInk(DOMElement $node, string $old, string $new, array $initials = [], array $parties = []): string
    {
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        $dom = $node->ownerDocument;
        $changeId = substr(sha1(($node->getAttribute('data-field') ?: '') . '|' . $old . '|' . $new), 0, 12);
        if (trim($old) !== '') {
            $del = $dom->createElement('del');
            $del->setAttribute('class', 'change-del');
            $del->appendChild($dom->createTextNode($old));
            $node->appendChild($del);
            $node->appendChild($dom->createTextNode(' '));
        }
        if (trim($new) !== '') {
            $ins = $dom->createElement('ins');
            $ins->setAttribute('class', 'change-ins');
            $ins->setAttribute('data-change-id', $changeId);
            $ins->appendChild($dom->createTextNode($new));
            $node->appendChild($ins);
        }
        $margin = $this->buildMarginInitials($node->ownerDocument, $changeId, $parties, $initials);
        if ($margin !== null) {
            $this->attachMarginToBlock($margin, $node);   // per-party wet-ink margin block, in the right margin
        } else {
            $this->appendInitialedTag($node, $changeId, $initials);   // legacy inline tag (no parties)
        }
        return $changeId;
    }

    /** Place the margin initial block at the right margin: as the first child of the change's nearest
     *  block-level ancestor, so float:right lands it at the page/column right edge, level with the change. */
    private function attachMarginToBlock(DOMElement $margin, \DOMNode $at): void
    {
        $anc = $at->parentNode;
        while ($anc instanceof DOMElement) {
            if (in_array(strtolower($anc->nodeName), ['p', 'div', 'li', 'td', 'section', 'blockquote'], true)) {
                $anc->insertBefore($margin, $anc->firstChild);
                return;
            }
            $anc = $anc->parentNode;
        }
        // Fallback: right after the change.
        $at->parentNode?->insertBefore($margin, $at->nextSibling);
    }

    /**
     * SELECTION-driven span striking (the primary edit path). For each cc6-captured change, locate the
     * Nth occurrence of the selected text anywhere in the body and replace it in place with
     * `<del>selected</del> <ins>replacement</ins>` + a right-margin initial block for all parties. Marks
     * are a render overlay from persisted data (web_template_data['body_changes']) — never baked — so they
     * survive re-render and stay on the final. Fail-safe: an occurrence that can't be located is skipped.
     */
    private function applyBodyChanges(\DOMDocument $dom, DOMXPath $x, array $bodyChanges, array &$changes, array $initials, array $parties): bool
    {
        if (empty($bodyChanges)) {
            return false;
        }
        $any = false;
        foreach ($bodyChanges as $bc) {
            if (! is_array($bc)) {
                continue;
            }
            $select = trim((string) ($bc['select'] ?? ''));
            if ($select === '') {
                continue;
            }
            $nth      = max(1, (int) ($bc['nth'] ?? 1));
            $insert   = (string) ($bc['insert'] ?? '');
            $changeId = (string) ($bc['change_id'] ?? '');
            if ($changeId === '') {
                $changeId = substr(sha1($select . '|' . $nth . '|' . $insert), 0, 12);
            }
            if ($this->strikeSelection($dom, $x, $select, $nth, $insert, $changeId, $initials, $parties)) {
                $changes[] = [
                    'id'    => $changeId,
                    'where' => '“' . mb_strimwidth($select, 0, 40, '…') . '”',
                    'old'   => $select,
                    'new'   => $insert !== '' ? $insert : '(removed)',
                    'mode'  => $insert !== '' ? 'selection' : 'selection-del',
                    'initialed' => $this->anyInitial($initials, $changeId),
                ];
                $any = true;
            }
        }
        return $any;
    }

    /** Locate the Nth plain-text occurrence of $select (outside existing marks) and strike+replace it. */
    private function strikeSelection(\DOMDocument $dom, DOMXPath $x, string $select, int $nth, string $insert, string $changeId, array $initials, array $parties): bool
    {
        $nodes = $x->query('//text()[not(ancestor::del) and not(ancestor::ins) and not(ancestor::style) and not(ancestor::script) and not(ancestor::*[contains(concat(" ",@class," ")," change-margin-initials ")])]');
        if ($nodes === false) {
            return false;
        }
        $count = 0;
        foreach ($nodes as $tn) {
            $val = (string) $tn->nodeValue;
            $from = 0;
            while (($pos = mb_strpos($val, $select, $from)) !== false) {
                $count++;
                if ($count === $nth) {
                    $parent = $tn->parentNode;
                    if (! $parent instanceof \DOMNode) {
                        return false;
                    }
                    $before = mb_substr($val, 0, $pos);
                    $after  = mb_substr($val, $pos + mb_strlen($select));
                    $frag = $dom->createDocumentFragment();
                    if ($before !== '') {
                        $frag->appendChild($dom->createTextNode($before));
                    }
                    $anchor = $dom->createElement('span');
                    $anchor->setAttribute('class', 'change-anchor');
                    $anchor->setAttribute('data-change-id', $changeId);

                    $del = $dom->createElement('del');
                    $del->setAttribute('class', 'change-del');
                    $del->setAttribute('data-change-id', $changeId);
                    $del->appendChild($dom->createTextNode($select));
                    $anchor->appendChild($del);

                    if ($insert !== '') {
                        $anchor->appendChild($dom->createTextNode(' '));
                        $ins = $dom->createElement('ins');
                        $ins->setAttribute('class', 'change-ins');
                        $ins->setAttribute('data-change-id', $changeId);
                        $ins->appendChild($dom->createTextNode($insert));
                        $anchor->appendChild($ins);
                    }
                    $margin = $this->buildMarginInitials($dom, $changeId, $parties, $initials);
                    if ($margin === null) {
                        $this->appendInitialedTag($anchor, $changeId, $initials);
                    }
                    $frag->appendChild($anchor);
                    if ($after !== '') {
                        $frag->appendChild($dom->createTextNode($after));
                    }
                    $parent->replaceChild($frag, $tn);
                    // Float the margin block to the right edge of the change's paragraph (page right margin).
                    if ($margin !== null) {
                        $this->attachMarginToBlock($margin, $anchor);
                    }
                    return true;
                }
                $from = $pos + mb_strlen($select);
            }
        }
        return false;
    }

    /**
     * Right-hand margin initial block for a change — one slot per signing party (wet-ink). Each slot shows
     * the party label + its initials when that party has initialed the change
     * (change_initials[change_id][partyKey] = {name, at}), else a blank rule. Returns null when no party
     * list was supplied (e.g. a preview with no signing parties yet).
     */
    private function buildMarginInitials(\DOMDocument $dom, string $changeId, array $parties, array $initials): ?DOMElement
    {
        if (empty($parties)) {
            return null;
        }
        $box = $dom->createElement('span');
        $box->setAttribute('class', 'change-margin-initials');
        $box->setAttribute('data-change-id', $changeId);
        foreach ($parties as $p) {
            $key   = is_array($p) ? (string) ($p['key'] ?? '') : (string) $p;
            $label = is_array($p) ? (string) ($p['label'] ?? $key) : (string) $p;
            if ($key === '') {
                continue;
            }
            $info = $this->initialFor($initials, $changeId, $key);
            $slot = $dom->createElement('span');
            $slot->setAttribute('class', 'change-initial-slot');
            $slot->setAttribute('data-change-id', $changeId);
            $slot->setAttribute('data-party', $key);

            $lbl = $dom->createElement('span');
            $lbl->setAttribute('class', 'cis-label');
            $lbl->appendChild($dom->createTextNode($label));
            $slot->appendChild($lbl);

            $ink = $dom->createElement('span');
            $ink->setAttribute('class', 'cis-ink' . ($info !== null ? ' cis-done' : ''));
            $ink->appendChild($dom->createTextNode($info !== null ? $this->initialsOf((string) ($info['name'] ?? '')) : '____'));
            $slot->appendChild($ink);

            $box->appendChild($slot);
        }
        return $box;
    }

    /** Per-party initial for a change: change_initials[change_id][partyKey] = {name, at}. */
    private function initialFor(array $initials, string $changeId, string $partyKey): ?array
    {
        $entry = $initials[$changeId] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        // Per-party shape { partyKey: {name, at} }.
        if (isset($entry[$partyKey]) && is_array($entry[$partyKey])) {
            return $entry[$partyKey];
        }
        return null;
    }

    /** Whether ANY party has initialed a change (for the legacy inline tag + appendix column). */
    private function anyInitial(array $initials, string $changeId): ?array
    {
        $entry = $initials[$changeId] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        // Legacy single shape { name, at }.
        if (isset($entry['name'])) {
            return $entry;
        }
        // Per-party shape → summarise as "{n} of parties".
        $done = array_filter($entry, fn ($v) => is_array($v) && ! empty($v['name']));
        if ($done === []) {
            return null;
        }
        $names = array_map(fn ($v) => $v['name'], $done);
        return ['name' => implode(', ', $names)];
    }

    /** "Alice Brown" → "AB"; a short value (e.g. "AB") passes through. */
    private function initialsOf(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '—';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }

    /** Append a "✓ initialed by {name}" tag to a change mark when cc6 has recorded its initial. */
    private function appendInitialedTag(DOMElement $node, string $changeId, array $initials): void
    {
        $info = $this->anyInitial($initials, $changeId);
        if ($info === null) {
            return;
        }
        $dom = $node->ownerDocument;
        $node->appendChild($dom->createTextNode(' '));
        $tag = $dom->createElement('span');
        $tag->setAttribute('class', 'change-initialed');
        $tag->setAttribute('data-change-id', $changeId);
        $name = trim((string) ($info['name'] ?? ''));
        // Plain text (no ✓ glyph — dompdf's base font renders it as "?"); the green pill conveys "done".
        $tag->appendChild($dom->createTextNode('Initialed' . ($name !== '' ? ' by ' . $name : '')));
        $node->appendChild($tag);
    }

    /**
     * Render a clause/body text change. SMALL → inline word-level strike+insert; BIG → strike the whole
     * clause + a cross-reference to Other Conditions. Returns true when it marked the block.
     */
    private function renderBlockChange(\DOMDocument $dom, DOMElement $el, string $oldText, string $newText, array &$changes, array $initials = []): bool
    {
        $oldWords = $this->words($oldText);
        $newWords = $this->words($newText);
        $ops = $this->wordDiff($oldWords, $newWords);
        $changedWords = 0;
        foreach ($ops as $op) {
            if ($op[0] !== 'eq') {
                $changedWords += count($op[1]);
            }
        }
        if ($changedWords === 0) {
            return false;
        }

        $changeId = substr(sha1($el->getAttribute('data-role-block') . '|' . $oldText . '|' . $newText), 0, 12);

        // BIG change → strike the whole clause + Other-Conditions cross-reference.
        if ($changedWords > self::BIG_CHANGE_WORDS) {
            while ($el->firstChild) {
                $el->removeChild($el->firstChild);
            }
            $del = $dom->createElement('del');
            $del->setAttribute('class', 'change-del change-clause');
            $del->appendChild($dom->createTextNode($oldText));
            $el->appendChild($del);
            $el->appendChild($dom->createTextNode(' '));
            $xref = $dom->createElement('span');
            $xref->setAttribute('class', 'change-xref');
            $xref->setAttribute('data-change-id', $changeId);
            // The concrete OC clause number is filled by cc6's flow (the strikethrough→Other-Conditions
            // route). Until bound, reference the block generically; cc6 stamps data-oc-ref when it routes.
            $ocRef = $el->getAttribute('data-oc-ref');
            $xref->appendChild($dom->createTextNode('See Other Conditions' . ($ocRef !== '' ? ' — clause ' . $ocRef : '')));
            $el->appendChild($xref);
            $this->appendInitialedTag($el, $changeId, $initials);
            $changes[] = ['id' => $changeId, 'where' => $this->prettyBlockLabel($el, $newText), 'old' => $oldText, 'new' => $newText, 'mode' => 'clause-big', 'initialed' => $initials[$changeId] ?? null];
            return true;
        }

        // SMALL change → inline word-level strike+insert.
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        foreach ($ops as $op) {
            [$kind, $wordsRun] = $op;
            $text = implode(' ', $wordsRun);
            if ($text === '') {
                continue;
            }
            if ($kind === 'eq') {
                $el->appendChild($dom->createTextNode($text . ' '));
            } elseif ($kind === 'del') {
                $d = $dom->createElement('del');
                $d->setAttribute('class', 'change-del');
                $d->appendChild($dom->createTextNode($text));
                $el->appendChild($d);
                $el->appendChild($dom->createTextNode(' '));
            } else { // ins
                $i = $dom->createElement('ins');
                $i->setAttribute('class', 'change-ins');
                $i->setAttribute('data-change-id', $changeId);
                $i->appendChild($dom->createTextNode($text));
                $el->appendChild($i);
                $el->appendChild($dom->createTextNode(' '));
            }
        }
        $this->appendInitialedTag($el, $changeId, $initials);
        $changes[] = ['id' => $changeId, 'where' => $this->prettyBlockLabel($el, $newText), 'old' => $oldText, 'new' => $newText, 'mode' => 'clause-small', 'initialed' => $initials[$changeId] ?? null];
        return true;
    }

    /** Standard LCS word diff → ordered runs of ['eq'|'del'|'ins', [words]]. */
    private function wordDiff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        // LCS length table.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $ops = [];
        $push = function (string $kind, string $w) use (&$ops) {
            if (! empty($ops) && $ops[count($ops) - 1][0] === $kind) {
                $ops[count($ops) - 1][1][] = $w;
            } else {
                $ops[] = [$kind, [$w]];
            }
        };
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $push('eq', $a[$i]);
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $push('del', $a[$i]);
                $i++;
            } else {
                $push('ins', $b[$j]);
                $j++;
            }
        }
        while ($i < $n) {
            $push('del', $a[$i++]);
        }
        while ($j < $m) {
            $push('ins', $b[$j++]);
        }
        return $ops;
    }

    private function words(string $s): array
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return $s === '' ? [] : explode(' ', $s);
    }

    private function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function containsChangeMark(DOMElement $el): bool
    {
        $x = new DOMXPath($el->ownerDocument);
        return $x->query('.//del[contains(@class,"change-")] | .//ins[contains(@class,"change-")] | .//*[contains(@class,"change-xref")]', $el)->length > 0;
    }

    private function containsExistingAmendment(DOMElement $el): bool
    {
        if (str_contains($el->getAttribute('class'), 'amendment') || $el->hasAttribute('data-amendment-id')) {
            return true;
        }
        $x = new DOMXPath($el->ownerDocument);
        return $x->query('.//*[@data-strikethrough-applied] | .//*[@data-amendment-id]', $el)->length > 0;
    }

    /**
     * Fold cc6's pre-authored change marks (baked into merged_html by ClauseEditService — struck clauses,
     * inline write-ins, OC cross-refs; all carrying our `change-*` classes + `data-change-id`) into this
     * render: add each to the Schedule of Amendments and stamp its "Initialed by" tag from the shared
     * `change_initials` map. Skips any change-id our own passes already handled. Returns true if it folded
     * in at least one mark (so the caller injects the CSS + appendix even when we made no edits ourselves).
     */
    private function absorbPreAuthoredMarks(DOMXPath $x, array &$changes, array $initials): bool
    {
        $seen = [];
        foreach ($changes as $c) {
            $seen[$c['id']] = true;
        }
        $folded = false;
        // Container elements that carry a change-id (the clause the mark lives on) — not the del/ins leaves.
        foreach ($x->query('//*[@data-change-id][.//del[contains(@class,"change-del")] or .//ins[contains(@class,"change-ins")] or .//span[contains(@class,"change-xref")]]') as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            $id = $el->getAttribute('data-change-id');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $old = '';
            $new = '';
            $mode = 'clause-small';
            foreach ($x->query('.//del[@data-change-id] | .//ins[@data-change-id] | .//span[@data-change-id]', $el) as $mark) {
                if (! $mark instanceof DOMElement || $mark->getAttribute('data-change-id') !== $id) {
                    continue;
                }
                $cls = $mark->getAttribute('class');
                if (str_contains($cls, 'change-del')) {
                    $old = trim($mark->textContent);
                } elseif (str_contains($cls, 'change-xref')) {
                    $new = 'Other Conditions' . ($el->getAttribute('data-oc-ref') !== '' ? ' — clause ' . $el->getAttribute('data-oc-ref') : '');
                    $mode = 'clause-big';
                } elseif (str_contains($cls, 'change-ins')) {
                    $new = trim($mark->textContent);
                }
            }
            $ref = $el->getAttribute('data-clause-ref');
            $where = $ref !== '' ? 'Clause ' . $ref : $this->prettyBlockLabel($el, $new);
            $changes[] = ['id' => $id, 'where' => $where, 'old' => $old, 'new' => $new, 'mode' => $mode, 'initialed' => $initials[$id] ?? null];
            // Stamp the initialed tag when present and not already there.
            if (isset($initials[$id]) && $x->query('.//*[contains(@class,"change-initialed")]', $el)->length === 0) {
                $this->appendInitialedTag($el, $id, $initials);
            }
            $folded = true;
        }
        return $folded;
    }

    /** Human-friendly label for a field key: "seller_phone__r2" → "Seller phone (party 2)". */
    private function prettyFieldLabel(string $key): string
    {
        $party = '';
        if (preg_match('/^(.*)__r(\d+)$/', $key, $m)) {
            $key = $m[1];
            $party = ' (party ' . $m[2] . ')';
        }
        return ucfirst(trim(str_replace('_', ' ', $key))) . $party;
    }

    /** Human-friendly label for a clause block: role token or a short text preview (of the NEW text). */
    private function prettyBlockLabel(DOMElement $el, string $preview = ''): string
    {
        $role = $el->getAttribute('data-role-block');
        if ($role !== '') {
            return ucfirst($role) . ' clause';
        }
        $preview = trim(preg_replace('/\s+/', ' ', $preview !== '' ? $preview : $el->textContent));
        return 'Clause: "' . mb_strimwidth($preview, 0, 40, '…') . '"';
    }

    /** Decision #2 — the appended "Schedule of Amendments" listing each change (old → new). */
    private function appendixHtml(array $changes): string
    {
        if (empty($changes)) {
            return '';
        }
        $rows = '';
        foreach ($changes as $i => $c) {
            $modeLabel = $c['mode'] === 'clause-big' ? 'Clause replaced → Other Conditions'
                : ($c['mode'] === 'clause-small' ? 'Clause amended' : 'Field amended');
            $init = is_array($c['initialed'] ?? null)
                ? ('<span style="color:#166534;font-weight:600;">Initialed by ' . e(trim((string) ($c['initialed']['name'] ?? ''))) . '</span>')
                : '<span style="color:#b45309;">pending</span>';
            $rows .= '<tr>'
                . '<td style="padding:4px 8px;border:1px solid #cbd5e1;text-align:center;">' . ($i + 1) . '</td>'
                . '<td style="padding:4px 8px;border:1px solid #cbd5e1;">' . e($c['where']) . '</td>'
                . '<td style="padding:4px 8px;border:1px solid #cbd5e1;"><span style="text-decoration:line-through;color:#b91c1c;">' . e($c['old'] !== '' ? $c['old'] : '—') . '</span></td>'
                . '<td style="padding:4px 8px;border:1px solid #cbd5e1;"><span style="background:#fef08a;">' . e($c['new'] !== '' ? $c['new'] : '—') . '</span></td>'
                . '<td style="padding:4px 8px;border:1px solid #cbd5e1;color:#64748b;font-size:0.75rem;">' . e($modeLabel) . '</td>'
                . '<td style="padding:4px 8px;border:1px solid #cbd5e1;font-size:0.75rem;">' . $init . '</td>'
                . '</tr>';
        }
        return '<div class="change-history-page" style="page-break-before:always;margin-top:24px;font-family:sans-serif;">'
            . '<h3 style="border-bottom:2px solid #111827;padding-bottom:4px;">Schedule of Amendments</h3>'
            . '<p style="font-size:0.8rem;color:#475569;">The following changes were made after the document was last authorised. '
            . 'Each change is marked in the body of the document (struck-through removals and highlighted insertions) and remains on the final document.</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:0.82rem;">'
            . '<thead><tr style="background:#f1f5f9;">'
            . '<th style="padding:4px 8px;border:1px solid #cbd5e1;">#</th>'
            . '<th style="padding:4px 8px;border:1px solid #cbd5e1;text-align:left;">Where</th>'
            . '<th style="padding:4px 8px;border:1px solid #cbd5e1;text-align:left;">Removed</th>'
            . '<th style="padding:4px 8px;border:1px solid #cbd5e1;text-align:left;">Inserted</th>'
            . '<th style="padding:4px 8px;border:1px solid #cbd5e1;text-align:left;">Type</th>'
            . '<th style="padding:4px 8px;border:1px solid #cbd5e1;text-align:left;">Initialed</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** Wet-ink mark styles — inline so they travel into dompdf + browser identically. */
    private function styleBlock(): string
    {
        return '<style>'
            . '.change-del{text-decoration:line-through;color:#b91c1c;text-decoration-thickness:1.5px;}'
            . '.change-ins{background:#fef08a;color:#111827;text-decoration:none;padding:0 1px;border-radius:2px;}'
            . '.change-clause{display:inline;}'
            . '.change-xref{margin-left:4px;padding:0 5px;background:#dbeafe;color:#1e40af;border-radius:3px;'
            . 'font-size:0.72rem;font-weight:600;white-space:nowrap;}'
            . '.change-initialed{margin-left:4px;padding:0 5px;background:#dcfce7;color:#166534;border-radius:3px;'
            . 'font-size:0.68rem;font-weight:600;white-space:nowrap;}'
            . '.change-anchor{position:relative;}'
            // Right-margin initial block per change (wet-ink): floats to the right edge so it sits in the margin.
            . '.change-margin-initials{float:right;clear:right;margin:0 0 3px 10px;padding:2px 4px;border:1px solid #94a3b8;'
            . 'border-radius:4px;background:#f8fafc;white-space:nowrap;line-height:1.1;}'
            . '.change-initial-slot{display:inline-block;margin:0 3px;text-align:center;vertical-align:top;}'
            . '.cis-label{display:block;font-size:0.5rem;color:#64748b;text-transform:uppercase;letter-spacing:.02em;}'
            . '.cis-ink{display:block;min-width:26px;font-weight:700;font-size:0.72rem;color:#334155;border-bottom:1px solid #94a3b8;}'
            . '.cis-ink.cis-done{color:#166534;border-bottom-color:#166534;}'
            . '@media print{.change-del,.change-ins,.change-xref,.change-initialed,.change-margin-initials,.cis-ink{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}'
            . '</style>';
    }
}
