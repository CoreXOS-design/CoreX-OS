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

    /**
     * The stable change-id for a body strike — sha1(normalisedPrefix | selected | replacement)[:12], exactly as
     * authorStrikeRange stamps onto the rendered mark (data-change-id). Editing an amendment recomputes it, so a
     * removed/edited amendment is matched by the SAME derivation the render uses. Used by the wizard edit/remove
     * endpoints to find the stored strike a clicked mark refers to.
     */
    public static function changeId(string $prefix, string $selected, string $replacement): string
    {
        $normPrefix = trim((string) preg_replace('/\s+/', ' ', $prefix));
        return substr(sha1($normPrefix . '|' . $selected . '|' . $replacement), 0, 12);
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
        $hit = $this->locateRange($dom, $selected, $prefix, $suffix);
        if ($hit === null) {
            return ['ok' => false, 'error' => 'Could not locate the highlighted text — try selecting it again.'];
        }
        [$startNode, $startOff, $endNode, $endOff] = $hit;

        $changeId = substr(sha1($this->norm($prefix) . '|' . $selected . '|' . $replacement), 0, 12);

        $baked = $canvas['baked'];
        // Agency context for the audit rows — from the acting user, else the document's owning agency. A
        // RECIPIENT amending at their turn (AT-373 increment 2) has no CoreX user account, so fall back to
        // the template's agency rather than writing a null agency_id (which the AgencyScope would then hide).
        $agencyId = $actor?->effectiveAgencyId() ?? $this->resolveTemplateAgencyId($template);
        $result = DB::transaction(function () use ($template, $document, $dom, $startNode, $startOff, $endNode, $endOff, $selected, $replacement, $changeId, $actor, $agencyId, $wtd, $mode, $baked) {
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
                    'agency_id'             => $agencyId,
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

            $this->authorStrikeRange($dom, $startNode, $startOff, $endNode, $endOff, $selected, $replacement, $changeId, $this->parties($template), $mode, $ocNumber);

            DocumentClauseStrikethrough::create([
                'signature_template_id'    => $template->id,
                'agency_id'                => $agencyId,
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

    /**
     * AT-373 increment 6 — REVERT-ON-REJECT render. Strip ONE amendment (by data-change-id) back out of the
     * document: remove the change-mark overlay + its per-party initial row, RESTORE the original struck text
     * in place, and RETAIN the change in the audit trail (no hard delete — the proposal + its rejection stay
     * recorded). Used when a chain approver / recipient rejects a proposed amendment: the document returns to
     * the agreed text WITHOUT that change, every existing signature/initial preserved (the revert overlays
     * onto the SIGNED canonical via amendSource/writeAmend, version >= 1).
     *
     * - HTML: each `.change-inline[data-change-id=CID]` wrapper is replaced by its `<del>`'s ORIGINAL text
     *   (the struck words), dropping the reword `<ins>` / Other-Conditions xref; the `.change-initial-row`
     *   and any `.change-margin` for that CID are removed.
     * - `pending_body_changes[CID]` is marked `reverted` (kept for audit); the matching
     *   `document_clause_strikethroughs` row → `rejected` (kept); a reference-mode Other Condition it created
     *   is `superseded` (kept). `change_initials[CID]` is dropped from the render map (the row is gone) but
     *   the event is audited.
     *
     * @return array{ok:bool, change_id?:string, reverted?:bool, error?:string}
     */
    public function revertChange(SignatureTemplate $template, string $changeId, ?User $actor = null): array
    {
        $changeId = trim($changeId);
        if ($changeId === '') {
            return ['ok' => false, 'error' => 'No change id supplied.'];
        }
        $document = $template->document;
        if (! $document) {
            return ['ok' => false, 'error' => 'Document not found.'];
        }

        $wtd    = is_array($document->web_template_data) ? $document->web_template_data : [];
        $canvas = CanonicalDocumentRenderer::amendSource($wtd);   // SIGNED canonical when baked; else merged_html
        $html   = $canvas['html'];
        $baked  = $canvas['baked'];

        [$newHtml, $strippedFromHtml] = $this->stripChangeMarks($html, $changeId);

        // The original text of this change (for matching the strikethrough audit row) comes from
        // pending_body_changes, recorded at authoring time.
        $originalText = null;
        foreach (($wtd['pending_body_changes'] ?? []) as $c) {
            if (is_array($c) && (string) ($c['change_id'] ?? '') === $changeId) {
                $originalText = (string) ($c['old'] ?? '');
                break;
            }
        }

        $result = DB::transaction(function () use ($template, $document, $changeId, $newHtml, $strippedFromHtml, $originalText, $actor, $wtd, $baked) {
            // 1) pending_body_changes — mark reverted, RETAIN (audit record of the proposal + its rejection).
            $changes = is_array($wtd['pending_body_changes'] ?? null) ? $wtd['pending_body_changes'] : [];
            foreach ($changes as &$c) {
                if (is_array($c) && (string) ($c['change_id'] ?? '') === $changeId) {
                    $c['reverted']    = true;
                    $c['reverted_by'] = $actor?->id;
                    $c['reverted_at'] = now()->toIso8601String();
                }
            }
            unset($c);
            $wtd['pending_body_changes'] = $changes;

            // 2) change_initials — drop this change's initial map (its row is gone from the render); the
            //    approvals that WERE applied stay in pending_body_changes/audit, never hard-deleted here.
            if (isset($wtd['change_initials'][$changeId])) {
                unset($wtd['change_initials'][$changeId]);
            }

            // 3) strikethrough audit row → rejected (RETAIN); supersede a reference-mode Other Condition.
            if ($originalText !== null && $originalText !== '') {
                $rows = DocumentClauseStrikethrough::query()
                    ->where('signature_template_id', $template->id)
                    ->where('clause_original_text', $originalText)
                    ->where('status', DocumentClauseStrikethrough::STATUS_PROPOSED)
                    ->get();
                foreach ($rows as $row) {
                    $row->update(['status' => DocumentClauseStrikethrough::STATUS_REJECTED]);
                    if ($row->replacement_condition_id) {
                        DocumentCondition::where('id', $row->replacement_condition_id)
                            ->whereNull('superseded_at')
                            ->update(['superseded_at' => now()]);
                    }
                }
            }

            // 4) Overlay the reverted body onto the SIGNED canonical (baked) — signatures + "signed at"
            //    location preserved; else write merged_html + recompose. Never drops ink.
            $wtd = CanonicalDocumentRenderer::writeAmend($wtd, $newHtml, $baked);
            $document->update(['web_template_data' => $wtd]);

            return ['ok' => true, 'change_id' => $changeId, 'reverted' => $strippedFromHtml];
        });

        return $result;
    }

    /**
     * Strip every change-mark wrapper + initial row + margin for one change-id from a composed HTML body,
     * restoring the original (struck) text in place. Returns [newHtml, foundAny]. Fail-safe: on a parse
     * error or when nothing matches, returns the input unchanged with foundAny=false.
     *
     * @return array{0:string, 1:bool}
     */
    private function stripChangeMarks(string $html, string $changeId): array
    {
        if (trim($html) === '' || ! str_contains($html, 'data-change-id="' . $changeId . '"')) {
            return [$html, false];
        }
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath = new \DOMXPath($dom);
            $lit   = $this->xpathLiteral($changeId);
            $found = false;

            // Remove the per-party initial row(s) + any right-margin block for this change.
            foreach (['change-initial-row', 'change-margin'] as $cls) {
                $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $cls . ' ") and @data-change-id=' . $lit . ']');
                foreach (iterator_to_array($nodes ?: []) as $node) {
                    if ($node instanceof \DOMNode && $node->parentNode) {
                        $node->parentNode->removeChild($node);
                        $found = true;
                    }
                }
            }

            // Replace each change-mark wrapper with its original struck text (the <del> content).
            $wraps = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " change-inline ") and @data-change-id=' . $lit . ']');
            foreach (iterator_to_array($wraps ?: []) as $wrap) {
                if (! $wrap instanceof \DOMElement || ! $wrap->parentNode) {
                    continue;
                }
                $dels = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " change-del ")]', $wrap);
                $original = '';
                foreach (iterator_to_array($dels ?: []) as $del) {
                    $original .= $del->textContent;
                }
                $wrap->parentNode->replaceChild($dom->createTextNode($original), $wrap);
                $found = true;
            }

            return [$this->innerHtml($dom, $xpath), $found];
        } catch (\Throwable $e) {
            return [$html, false];
        }
    }

    /**
     * UNIVERSAL ENGINE (Johan 2026-08-05) — author a wet-ink strike onto an ARBITRARY composed HTML string,
     * with the full-width per-party initial row, and return the new HTML. This is the SAME authoring the
     * sign-screen amend uses (locate → authorStrike → row), decoupled from a persisted Document so the
     * Fill & Review wizard can strike an unwanted clause AT CREATION time and replay the strikes at every
     * compose. Modes: 'inline' (reword) and 'strike' (pure removal). 'reference' (Other Conditions) needs a
     * persisted template and is not offered pre-send. Returns null when the selection can't be located (idempotent
     * on an already-struck body — locate skips text inside an existing change mark).
     *
     * @param array<int, array{key:string, name:string}> $parties
     * @return array{html:string, change_id:string, mode:string}|null
     */
    public function applyStrikeToHtml(string $html, string $selected, string $prefix, string $suffix, string $replacement, string $mode, array $parties): ?array
    {
        $mode = $mode === 'strike' ? 'strike' : 'inline';   // creation-time supports inline + pure strike
        $selected = trim($selected);
        if ($selected === '' || trim($html) === '') {
            return null;
        }
        if ($mode !== 'strike' && trim($replacement) === '') {
            return null;
        }
        if ($mode === 'strike') {
            $replacement = '';
        }

        $dom = new \DOMDocument();
        if (! @$dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        )) {
            return null;
        }
        $hit = $this->locateRange($dom, $selected, $prefix, $suffix);
        if ($hit === null) {
            return null; // not found (or already struck) — caller keeps the html unchanged
        }
        [$startNode, $startOff, $endNode, $endOff] = $hit;
        $changeId = substr(sha1($this->norm($prefix) . '|' . $selected . '|' . $replacement), 0, 12);
        $this->authorStrikeRange($dom, $startNode, $startOff, $endNode, $endOff, $selected, $replacement, $changeId, $parties, $mode, null);

        return ['html' => $this->innerHtml($dom, new \DOMXPath($dom)), 'change_id' => $changeId, 'mode' => $mode];
    }

    /**
     * Locate the highlighted selection ACROSS the document — robust to selections that span multiple text
     * nodes and cross inline markup (a field-value / clause-number / id span, an underline, a link, bold, …).
     * The frontend sends the visible text whitespace-collapsed; we match it against a whitespace-collapsed
     * index of the whole document body and map the match back to the exact start/end DOM text-node positions.
     * Text already inside an authored change mark (and inside <style>/<script>) is excluded so a re-apply can
     * never match a struck span (idempotency) and CSS/JS text is never selectable.
     *
     * @return array{0: \DOMText, 1: int, 2: \DOMText, 3: int}|null  [startNode, startOffset, endNode, endOffsetExclusive]
     */
    private function locateRange(\DOMDocument $dom, string $selected, string $prefix, string $suffix): ?array
    {
        // Match WHITESPACE-INSENSITIVELY. The browser's Selection.toString() inserts unpredictable whitespace at
        // block boundaries (a line break here, a blank line there) which the frontend collapses differently from
        // how the DOM text is spaced — so an exact whitespace match is fragile for whole-section selections. We
        // strip ALL whitespace from both the selection and the document index and match the dense strings, then
        // map back to the exact (text node, offset) of the first/last visible char. Immune to any block/line
        // spacing difference, while the struck RANGE still spans the real DOM (whitespace included) in between.
        $sel = $this->stripWs($selected);
        if ($sel === '') {
            return null;
        }
        $index = $this->buildTextIndex($dom);
        $hay = $index['dense'];
        if ($hay === '') {
            return null;
        }

        $prefixDense = $this->stripWs($prefix);
        $suffixDense = $this->stripWs($suffix);
        $selLen = mb_strlen($sel);

        $best = null;
        $bestScore = -1;
        $from = 0;
        while (($pos = mb_strpos($hay, $sel, $from)) !== false) {
            // Score by the surrounding CONTEXT (also whitespace-stripped) so the right occurrence is chosen when
            // a phrase repeats.
            $before = mb_substr($hay, max(0, $pos - 30), min(30, $pos));
            $after  = mb_substr($hay, $pos + $selLen, 30);
            $score = 0;
            if ($prefixDense !== '' && str_ends_with($before, mb_substr($prefixDense, -20))) $score += 2;
            if ($suffixDense !== '' && str_starts_with($after, mb_substr($suffixDense, 0, 20))) $score += 2;
            if ($prefixDense === '' && $suffixDense === '') $score += 1; // no context → first match
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $pos;
            }
            $from = $pos + 1;
        }
        if ($best === null) {
            return null;
        }

        [$startNode, $startOff] = $index['map'][$best];
        [$endNode, $endOff]     = $index['map'][$best + $selLen - 1];
        return [$startNode, $startOff, $endNode, $endOff + 1];
    }

    /** Strip ALL whitespace (incl. nbsp) — the frontend's JS \s and this matcher agree, so spacing never blocks a match. */
    private function stripWs(string $s): string
    {
        return (string) preg_replace('/\s+/u', '', $s);
    }

    /**
     * Flatten the document body to a whitespace-collapsed string, with a per-character map back to the exact
     * (text node, char offset) that produced it. ASCII-whitespace runs collapse to one space (matching the
     * frontend's `sel.toString().replace(/\s+/g,' ')` and this class's norm()); leading whitespace is dropped.
     * Nodes inside an authored change mark or a <style>/<script> are skipped.
     *
     * @return array{dense: string, map: array<int, array{0: \DOMText, 1: int}>}
     */
    private function buildTextIndex(\DOMDocument $dom): array
    {
        $xpath = new \DOMXPath($dom);
        $textNodes = $xpath->query('//text()');
        $dense = '';
        $map   = [];
        if ($textNodes !== false) {
            foreach ($textNodes as $tn) {
                if (! $tn instanceof \DOMText || $this->insideChangeMark($tn) || $this->insideSkippable($tn)) {
                    continue;
                }
                $chars = mb_str_split($tn->nodeValue ?? '');
                foreach ($chars as $o => $ch) {
                    // Skip ALL whitespace (space/tab/newline/CR/FF/VT/nbsp) — matching is whitespace-insensitive,
                    // so block-boundary / line-break spacing can never block a whole-section selection.
                    if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f" || $ch === "\x0B" || $ch === "\u{A0}") {
                        continue;
                    }
                    $dense .= $ch;
                    $map[]  = [$tn, $o];
                }
            }
        }
        return ['dense' => $dense, 'map' => $map];
    }

    /** True when $node sits inside a <style>/<script> (its text is not document prose and must never be struck). */
    private function insideSkippable(\DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof \DOMElement && in_array(strtolower($p->nodeName), ['style', 'script'], true)) {
                return true;
            }
        }
        return false;
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

    /**
     * Author the inline strike + full-width per-party initial row over the located RANGE — works whether the
     * selection sits in one text node or SPANS MANY (crossing inline markup: a field-value / clause-number /
     * id span, an underline, a link, bold, …). The selected run (which may cross elements) is removed and
     * replaced, at its start position, by ONE change-mark wrapper: <del>{selected}</del> + optional <ins>/xref.
     * The struck text is authored as the exact visible selection (whitespace-collapsed) — its former internal
     * markup is being struck out anyway, so it is flattened; empty inline wrappers left behind are pruned.
     */
    private function authorStrikeRange(\DOMDocument $dom, \DOMText $startNode, int $startOff, \DOMText $endNode, int $endOff, string $selected, string $replacement, string $changeId, array $parties, string $mode = 'inline', ?int $ocNumber = null): void
    {
        // Split the boundary text nodes so the selected run occupies whole text nodes we can lift out.
        if ($startNode === $endNode) {
            $selNode = $startOff > 0 ? $this->splitTextAt($startNode, $startOff) : $startNode;
            $selLen  = $endOff - $startOff;
            if ($selLen < mb_strlen($selNode->nodeValue ?? '')) {
                $this->splitTextAt($selNode, $selLen); // trim the trailing (unselected) remainder off
            }
            $collected = [$selNode];
        } else {
            $startSel = $startOff > 0 ? $this->splitTextAt($startNode, $startOff) : $startNode;
            if ($endOff < mb_strlen($endNode->nodeValue ?? '')) {
                $this->splitTextAt($endNode, $endOff); // $endNode keeps the selected head
            }
            $collected = $this->collectTextNodesInOrder($startSel, $endNode);
        }
        if ($collected === []) {
            return;
        }

        // Group the selected text nodes by their block element. A WHOLE-SECTION selection (a heading + body,
        // several <p>, list items, a complete multi-paragraph clause) spans >1 block — we strike each block's
        // portion in place (so the section keeps its layout, struck through) under the SAME change-id, and add
        // ONE "Initial this change" block for the whole amendment. A within-block selection is one group and
        // behaves exactly as before.
        $groups = $this->groupByBlock($collected);
        $lastIdx = count($groups) - 1;
        $lastWrap = null;
        foreach ($groups as $gi => [, $nodes]) {
            $anchor = $nodes[0];
            if ($anchor->parentNode === null) {
                continue;
            }
            $isLast = ($gi === $lastIdx);
            // The struck text of this block = its own selected portion; the replacement / OC-xref is authored
            // once, on the LAST block, so a multi-block reword shows one insert at the end of the section.
            $delText = ($lastIdx === 0) ? $selected : $this->norm(implode('', array_map(fn ($n) => $n->nodeValue ?? '', $nodes)));
            $wrap = $this->buildChangeWrapper(
                $dom,
                $delText,
                $isLast ? $replacement : '',
                $changeId,
                $isLast ? $mode : 'strike',
                $isLast ? $ocNumber : null,
            );
            $anchor->parentNode->insertBefore($wrap, $anchor);
            $this->removeAndPrune($nodes, $wrap);
            $lastWrap = $wrap;
        }

        // ONE full-width initial row for the whole amendment, after the LAST block it touched.
        if ($lastWrap !== null) {
            $this->appendInitialRowAfterBlock($dom, $lastWrap, $changeId, $parties);
        }
    }

    /** Group consecutive selected text nodes by their closest block element (document order preserved). */
    private function groupByBlock(array $nodes): array
    {
        $groups = [];
        $curBlock = false;
        $cur = [];
        foreach ($nodes as $n) {
            $blk = $this->closestBlock($n);
            if ($blk !== $curBlock) {
                if ($cur !== []) {
                    $groups[] = [$curBlock, $cur];
                }
                $curBlock = $blk;
                $cur = [];
            }
            $cur[] = $n;
        }
        if ($cur !== []) {
            $groups[] = [$curBlock, $cur];
        }
        return $groups;
    }

    /** Remove the selected text nodes and prune any inline wrappers they emptied out (never the change wrap). */
    private function removeAndPrune(array $nodes, \DOMElement $wrap): void
    {
        $touchedInline = [];
        foreach ($nodes as $c) {
            $p = $c->parentNode;
            if ($p instanceof \DOMElement) {
                $touchedInline[spl_object_id($p)] = $p;
            }
            $p?->removeChild($c);
        }
        $inlineTags = ['span', 'u', 'a', 'b', 'strong', 'em', 'i', 'small', 'sub', 'sup', 'font'];
        foreach ($touchedInline as $el) {
            while ($el instanceof \DOMElement
                && $el !== $wrap
                && in_array(strtolower($el->nodeName), $inlineTags, true)
                && $el->childNodes->length === 0) {
                $up = $el->parentNode;
                $el->parentNode?->removeChild($el);
                $el = $up;
            }
        }
    }

    /**
     * FULL-WIDTH INITIAL ROW — a block right after the clause/paragraph (or the LAST block of a whole-section
     * amendment) the change sits in. One labeled slot per signing party; each party applies their REAL initial
     * in their own slot at signing.
     */
    private function appendInitialRowAfterBlock(\DOMDocument $dom, \DOMElement $wrap, string $changeId, array $parties): void
    {
        $row = $this->buildInitialRow($dom, $changeId, $parties);
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

    /** Build the change-mark wrapper: <span change-inline><del>{selected}</del> + optional <ins>/xref</span>. */
    private function buildChangeWrapper(\DOMDocument $dom, string $selected, string $replacement, string $changeId, string $mode, ?int $ocNumber): \DOMElement
    {
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

        return $wrap;
    }

    /**
     * Split a text node at a CHARACTER offset (mb-safe — never libxml's byte-offset splitText). $node keeps
     * [0, $offset); a NEW text node carrying [$offset, end) is inserted right after it and returned.
     */
    private function splitTextAt(\DOMText $node, int $offset): \DOMText
    {
        $full = $node->nodeValue ?? '';
        $node->nodeValue = mb_substr($full, 0, $offset);
        $tail = $node->ownerDocument->createTextNode(mb_substr($full, $offset));
        if ($node->nextSibling) {
            $node->parentNode?->insertBefore($tail, $node->nextSibling);
        } else {
            $node->parentNode?->appendChild($tail);
        }
        return $tail;
    }

    /** Every text node from $start to $end inclusive, in document order (the selected run across nodes). */
    private function collectTextNodesInOrder(\DOMNode $start, \DOMNode $end): array
    {
        $out  = [];
        $node = $start;
        while ($node !== null) {
            if ($node instanceof \DOMText) {
                $out[] = $node;
            }
            if ($node === $end) {
                break;
            }
            $node = $this->nextInDocOrder($node);
        }
        return $out;
    }

    /** Next node in document order (depth-first): first child, else next sibling, else up-and-over. */
    private function nextInDocOrder(\DOMNode $node): ?\DOMNode
    {
        if ($node->firstChild) {
            return $node->firstChild;
        }
        while ($node !== null) {
            if ($node->nextSibling) {
                return $node->nextSibling;
            }
            $node = $node->parentNode;
        }
        return null;
    }

    /**
     * Build the full-width per-party initial row for a change. cc1 styling class family: change-initial-row.
     * @param array<int, array{key:string, name:string}> $parties the universal signing-party set (from a
     *        template's requests on the sign screen, or the flow's recipients at Fill & Review).
     */
    private function buildInitialRow(\DOMDocument $dom, string $changeId, array $parties): \DOMElement
    {
        $row = $dom->createElement('div');
        $row->setAttribute('class', 'change-initial-row');
        $row->setAttribute('data-change-id', $changeId);
        $row->setAttribute('contenteditable', 'false');
        $label = $dom->createElement('span');
        $label->setAttribute('class', 'cir-label');
        $label->appendChild($dom->createTextNode('Initial this change:'));
        $row->appendChild($label);
        foreach ($parties as $party) {
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

    /** The owning agency for the template's audit rows — the document owner's agency, else the creator's. */
    private function resolveTemplateAgencyId(SignatureTemplate $template): ?int
    {
        foreach ([$template->document?->owner_id ?? null, $template->created_by ?? null] as $uid) {
            if ($uid) {
                $u = User::find($uid);
                if ($u && method_exists($u, 'effectiveAgencyId')) {
                    $aid = $u->effectiveAgencyId();
                    if ($aid) {
                        return (int) $aid;
                    }
                }
            }
        }
        return null;
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
