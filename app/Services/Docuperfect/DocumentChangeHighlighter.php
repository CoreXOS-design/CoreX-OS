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
    public function highlight(string $currentHtml, string $baselineHtml): string
    {
        if (trim($currentHtml) === '' || trim($baselineHtml) === '') {
            return $currentHtml;
        }

        try {
            $detector = app(RoleBlockDetectionService::class);
            $curDom = $detector->loadFragment($currentHtml);
            $baseDom = $detector->loadFragment($baselineHtml);
            if ($curDom === null || $baseDom === null) {
                return $currentHtml;
            }
            $curX = new DOMXPath($curDom);
            $baseX = new DOMXPath($baseDom);

            $changed = false;

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
                $this->replaceChildrenWithWetInk($node, $old, $new);
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
                if ($this->renderBlockChange($curDom, $el, $oldText, $newText)) {
                    $changed = true;
                }
            }

            if (! $changed) {
                return $currentHtml;
            }

            $out = $detector->serializeFragment($curDom);
            return $out !== '' ? $this->styleBlock() . $out : $currentHtml;
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

    /** Replace a field span's children with wet-ink: struck old + inline new. */
    private function replaceChildrenWithWetInk(DOMElement $node, string $old, string $new): void
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
    }

    /**
     * Render a clause/body text change. SMALL → inline word-level strike+insert; BIG → strike the whole
     * clause + a cross-reference to Other Conditions. Returns true when it marked the block.
     */
    private function renderBlockChange(\DOMDocument $dom, DOMElement $el, string $oldText, string $newText): bool
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

    /** Wet-ink mark styles — inline so they travel into dompdf + browser identically. */
    private function styleBlock(): string
    {
        return '<style>'
            . '.change-del{text-decoration:line-through;color:#b91c1c;text-decoration-thickness:1.5px;}'
            . '.change-ins{background:#fef08a;color:#111827;text-decoration:none;padding:0 1px;border-radius:2px;}'
            . '.change-clause{display:inline;}'
            . '.change-xref{margin-left:4px;padding:0 5px;background:#dbeafe;color:#1e40af;border-radius:3px;'
            . 'font-size:0.72rem;font-weight:600;white-space:nowrap;}'
            . '@media print{.change-del,.change-ins,.change-xref{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}'
            . '</style>';
    }
}
