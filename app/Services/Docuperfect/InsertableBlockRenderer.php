<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Support\Collection;

/**
 * E-Sign V3 (ES-9 / Phase 1B.5) — replace `~~~~MARKER~~~~` placeholder
 * tokens in a document's HTML body with styled, contextual block partials.
 *
 * Contexts:
 *   agent_preparation     — agent sees conditions + edit affordance hint
 *   agent_review          — same as preparation + diff highlights
 *   recipient_signing     — recipient sees conditions + "+ Add condition" button
 *   recipient_initialing  — only changed regions (handled by initialing view,
 *                           not this renderer)
 *   pdf_render            — flatten to numbered list, no interactive elements
 *
 * The renderer is intentionally HTML-string-in / HTML-string-out so it can
 * be threaded into any existing pipeline (signing view, wizard preview,
 * PDF flatten) by a single line insertion before the view emits the body.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5
 */
final class InsertableBlockRenderer
{
    public const CONTEXT_AGENT_PREPARATION    = 'agent_preparation';
    public const CONTEXT_AGENT_REVIEW         = 'agent_review';
    public const CONTEXT_RECIPIENT_SIGNING    = 'recipient_signing';
    public const CONTEXT_RECIPIENT_INITIALING = 'recipient_initialing';
    public const CONTEXT_PDF_RENDER           = 'pdf_render';

    /**
     * ESIGN-WETINK BUG1 — make the other-conditions block RECIPIENT-FILLABLE on
     * the served canonical.
     *
     * The canonical artifact is composed viewer-agnostic (CONTEXT_AGENT_PREPARATION,
     * signingToken=null), so its "+ Add condition" button carries no
     * `data-signing-token` — the recipient's add-condition modal has no token to
     * POST to `sign/{token}/conditions`, so the block renders but is not fillable.
     * Rather than recompose the whole document per viewer (which would break the
     * one-artifact rule), this stamps the CURRENT signer's token onto every
     * add-condition button at display time — a per-viewer affordance overlay,
     * exactly like the editability overlay. The document body is otherwise
     * untouched, so the canonical stays byte-identical across surfaces.
     *
     * Idempotent and safe: only buttons that carry the btn-add-condition class
     * are touched; an existing token is replaced with the viewer's own.
     */
    public function stampConditionSigningToken(string $documentHtml, string $token): string
    {
        if ($documentHtml === '' || $token === '' || ! str_contains($documentHtml, 'btn-add-condition')) {
            return $documentHtml;
        }
        $safe = htmlspecialchars($token, ENT_QUOTES);
        // Drop any existing data-signing-token on add-condition buttons, then
        // inject the viewer's. Operates on the button's opening tag only.
        return (string) preg_replace_callback(
            '/<button\b[^>]*\bclass="[^"]*\bbtn-add-condition\b[^"]*"[^>]*>/i',
            function (array $m) use ($safe): string {
                $tag = preg_replace('/\s*data-signing-token="[^"]*"/i', '', $m[0]);
                return substr($tag, 0, -1) . ' data-signing-token="' . $safe . '">';
            },
            $documentHtml,
        );
    }

    /**
     * Step 2 (Johan) — inject the NON-PRINTABLE "one condition at a time"
     * guidance next to each "+ Add condition" control. This is SCREEN-ONLY
     * chrome (a soft discipline hint, not hard validation) and MUST NEVER
     * appear in the print-from-approved canonical / PDF.
     *
     * It is applied as a per-viewer DISPLAY OVERLAY at show()-time (exactly like
     * stampConditionSigningToken) — deliberately NOT baked into the canonical, so
     * the frozen legal artifact stays clean of UI chrome. As belt-and-braces the
     * element carries `no-print` (the corex-document.css `@media print` convention)
     * and `data-screen-only="1"` (which SignaturePdfService strips from the PDF DOM
     * before rendering), so it cannot survive to print by any path.
     *
     * Idempotent: any previously-injected guidance is stripped first, then one is
     * re-inserted immediately BEFORE every add-condition button.
     */
    public function injectAddConditionGuidance(string $html): string
    {
        if ($html === '' || ! str_contains($html, 'btn-add-condition')) {
            return $html;
        }
        // Idempotent — remove any prior guidance before re-inserting.
        $html = (string) preg_replace(
            '/<div class="condition-add-guidance[^"]*"[^>]*>.*?<\/div>/s',
            '',
            $html,
        );
        $guidance = '<div class="condition-add-guidance no-print" data-screen-only="1" '
            . 'style="margin-top:0.5rem; font-size:0.72rem; color:#92400e; font-style:italic;">'
            . 'Please add only one condition at a time.</div>';

        return (string) preg_replace(
            '/(<button\b[^>]*\bbtn-add-condition\b[^>]*>)/i',
            $guidance . '$1',
            $html,
        );
    }

    /**
     * Replace every `~~~~MARKER~~~~` in $documentHtml with a styled block
     * rendered for the requested context.
     *
     * @param array<int, array<string, mixed>> $blocks
     *   The template's insertable_blocks metadata (id, purpose, label,
     *   position_marker, max_conditions, auto_number, locked, ...).
     */
    public function renderInDocument(
        string $documentHtml,
        SignatureTemplate $doc,
        array $blocks,
        string $context,
        ?string $signingToken = null,
        ?string $currentPartyKey = null
    ): string {
        if ($documentHtml === '' || empty($blocks)) {
            return $this->renderUnboundMarkers($documentHtml, $doc, $context, $signingToken, $currentPartyKey);
        }

        // Phase 1B.7 (FIX B) — eager-load initials on every condition once
        // so the renderer can paint per-party initial slots without N+1.
        $conditionsByBlock = DocumentCondition::query()
            ->where('signature_template_id', $doc->id)
            ->whereNull('superseded_at')
            ->whereNull('deleted_at')
            ->with('initials')
            ->orderBy('block_id')
            ->orderBy('condition_number')
            ->get()
            ->groupBy('block_id');

        $strikesByClauseRef = DocumentClauseStrikethrough::query()
            ->where('signature_template_id', $doc->id)
            ->whereIn('status', [
                DocumentClauseStrikethrough::STATUS_PROPOSED,
                DocumentClauseStrikethrough::STATUS_APPROVED,
            ])
            ->get()
            ->keyBy('clause_ref');

        $html = $documentHtml;
        foreach ($blocks as $block) {
            $marker = $block['position_marker'] ?? null;
            if (! is_string($marker) || $marker === '') {
                continue;
            }
            $rendered = $this->renderBlockPartial(
                $block,
                $conditionsByBlock->get($block['id'] ?? '', collect()),
                $doc,
                $context,
                $signingToken,
                $currentPartyKey
            );
            $html = str_replace($marker, $rendered, $html);
        }

        // Catch markers in the body that aren't declared in the template's
        // insertable_blocks metadata (e.g. older templates pre-migration).
        $html = $this->renderUnboundMarkers($html, $doc, $context, $signingToken, $currentPartyKey);

        // Apply strikethrough rendering pass for already-proposed overrides.
        $html = $this->applyStrikethroughs($html, $strikesByClauseRef->values());

        return $html;
    }

    /**
     * Apply struck-through CSS + inline annotation to clauses that have a
     * proposed/approved DocumentClauseStrikethrough. Idempotent — re-running
     * over an already-decorated string is a no-op.
     *
     * Match strategy: look for span/div elements carrying
     * `data-clause-ref="X.Y"` (set by the recipient-signing JS that wraps
     * numbered clauses). Skip elements already marked `data-strikethrough-applied`.
     */
    public function applyStrikethroughs(string $documentHtml, Collection $strikethroughs): string
    {
        if ($documentHtml === '' || $strikethroughs->isEmpty()) {
            return $documentHtml;
        }

        foreach ($strikethroughs as $strike) {
            $ref = (string) $strike->clause_ref;
            $replacementNumber = $strike->replacementCondition?->condition_number;
            $annotation = $replacementNumber
                ? sprintf(' <em class="override-annotation">[See Other Conditions #%d]</em>', $replacementNumber)
                : ' <em class="override-annotation">[See Other Conditions]</em>';

            $pattern = '/<(span|div|p|li)\b([^>]*\bdata-clause-ref="' . preg_quote($ref, '/') . '"[^>]*)>(.*?)<\/\1>/si';
            $documentHtml = preg_replace_callback($pattern, function ($m) use ($annotation) {
                $tag   = $m[1];
                $attrs = $m[2];
                $inner = $m[3];
                if (str_contains($attrs, 'data-strikethrough-applied')) {
                    return $m[0];
                }
                $attrs .= ' data-strikethrough-applied="1"';
                $styledInner = '<span style="text-decoration: line-through; color: #6b7280;">' . $inner . '</span>' . $annotation;
                return "<{$tag}{$attrs}>{$styledInner}</{$tag}>";
            }, $documentHtml);
        }

        return $documentHtml;
    }

    /**
     * Render a single block at its marker position in the document.
     *
     * @param array<string, mixed> $block
     * @param Collection<int, DocumentCondition> $conditions
     */
    private function renderBlockPartial(
        array $block,
        Collection $conditions,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken = null,
        ?string $currentPartyKey = null
    ): string {
        return $this->renderBlockPartialInner($block, $conditions, $doc, $context, $signingToken, $currentPartyKey);
    }

    /**
     * Inner block-render body. Phase 1B.7 split so the additional
     * currentPartyKey parameter doesn't break the legacy 5-arg call shape
     * in places that pre-date this phase.
     */
    private function renderBlockPartialInner(
        array $block,
        Collection $conditions,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken,
        ?string $currentPartyKey = null
    ): string {
        $blockId    = (string) ($block['id'] ?? '');
        $purpose    = (string) ($block['purpose'] ?? 'other_conditions');
        $label      = (string) ($block['label'] ?? $this->defaultLabelFor($purpose));
        $isLocked   = (bool)   ($block['locked'] ?? false);
        $autoNumber = (bool)   ($block['auto_number'] ?? ($purpose === 'other_conditions'));

        // Fallback: when there are no structured rows but
        // signature_templates.other_conditions_text is populated, render
        // that legacy text inline so pre-Phase-1B.5 documents still surface.
        $useLegacyTextFallback = $conditions->isEmpty()
            && $purpose === 'other_conditions'
            && trim((string) $doc->other_conditions_text) !== '';

        $itemsHtml = '';
        if ($conditions->isNotEmpty()) {
            // Phase 1B.6 (FIX 3) — auto-numbered blocks use <ol> with
            // explicit list-style so the surrounding document CSS (which
            // sometimes resets list-style) doesn't suppress the digits.
            // Non-auto-number blocks use <ul> with list-style:none.
            if ($autoNumber) {
                $itemsHtml .= '<ol class="conditions-list conditions-list-numbered" '
                    . 'style="list-style: decimal outside; padding-left: 1.5em; margin: 0.4rem 0;">';
            } else {
                $itemsHtml .= '<ul class="conditions-list conditions-list-unnumbered" '
                    . 'style="list-style: none; padding-left: 0; margin: 0.4rem 0;">';
            }
            foreach ($conditions as $c) {
                $itemsHtml .= $this->renderConditionRow($c, $context, $doc, $signingToken, $currentPartyKey);
            }
            $itemsHtml .= $autoNumber ? '</ol>' : '</ul>';
        } elseif ($useLegacyTextFallback) {
            $legacyLines = preg_split("/\r?\n/", (string) $doc->other_conditions_text);
            $itemsHtml .= '<div class="conditions-legacy-text" style="white-space:pre-wrap;">'
                . e(implode("\n", $legacyLines)) . '</div>';
        } else {
            $itemsHtml .= '<p class="no-conditions-yet" style="color:#6b7280; font-style:italic;">No conditions yet.</p>';
        }

        $purposeColors = [
            'other_conditions' => '#92400e',
            'included_items'   => '#047857',
            'excluded_items'   => '#be123c',
            'custom_named'     => '#475569',
        ];
        $color = $purposeColors[$purpose] ?? '#475569';

        $addButton = '';
        $canAdd = ! $isLocked && in_array($context, [
            self::CONTEXT_AGENT_PREPARATION,
            self::CONTEXT_AGENT_REVIEW,
            self::CONTEXT_RECIPIENT_SIGNING,
        ], true);
        if ($canAdd) {
            $tokenAttr = $signingToken ? ' data-signing-token="' . e($signingToken) . '"' : '';
            $addButton = '<button type="button" class="btn-add-condition" '
                . 'data-block-id="' . e($blockId) . '" '
                . 'data-block-purpose="' . e($purpose) . '" '
                . 'data-block-label="' . e($label) . '"'
                . $tokenAttr
                . ' style="margin-top:0.6rem; padding:0.35rem 0.75rem; border:1px dashed ' . $color . '; '
                . 'background:transparent; color:' . $color . '; border-radius:4px; cursor:pointer; font-size:0.85rem;">'
                . '+ Add condition</button>';
        }

        if ($context === self::CONTEXT_PDF_RENDER) {
            $addButton = '';
        }

        return '<div class="insertable-block" data-block-id="' . e($blockId) . '" '
            . 'data-purpose="' . e($purpose) . '" data-auto-number="' . ($autoNumber ? '1' : '0') . '" '
            . 'style="margin: 1rem 0; padding: 0.9rem 1rem; '
            . 'border-left: 3px solid ' . $color . '; '
            . 'background: color-mix(in srgb, ' . $color . ' 5%, transparent);">'
            . '<div class="block-header" style="margin-bottom: 0.6rem;">'
            . '<strong style="color:' . $color . '; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.78rem;">'
            . e($label)
            . '</strong>'
            . '</div>'
            . $itemsHtml
            . $addButton
            . '</div>';
    }

    /**
     * Phase 1B.9 (FIX 3) — public so the SigningController can render a
     * single new condition row HTML and return it as JSON for the
     * recipient's add-condition modal to append in place. This avoids
     * the full-page reload that wiped Alpine signature state.
     */
    public function renderConditionRowPublic(
        DocumentCondition $c,
        string $context,
        ?SignatureTemplate $doc = null,
        ?string $signingToken = null,
        ?string $currentPartyKey = null
    ): string {
        return $this->renderConditionRow($c, $context, $doc, $signingToken, $currentPartyKey);
    }

    /**
     * Render ONE insertable block's full container HTML
     * (`<div class="insertable-block" data-block-id="…">…</div>`) with its
     * CURRENT conditions + captured per-condition initials. Public so
     * CanonicalDocumentRenderer::refreshInsertableBlocks can surgically swap the
     * block region in an already-baked canonical (preserving signature/initial
     * ink elsewhere) when a condition is added/initialed during signing.
     */
    public function renderBlockContainer(
        array $block,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken = null,
        ?string $currentPartyKey = null
    ): string {
        $blockId = (string) ($block['id'] ?? '');
        $conditions = DocumentCondition::query()
            ->where('signature_template_id', $doc->id)
            ->where('block_id', $blockId)
            ->whereNull('superseded_at')
            ->whereNull('deleted_at')
            ->with('initials')
            ->orderBy('condition_number')
            ->get();

        return $this->renderBlockPartialInner($block, $conditions, $doc, $context, $signingToken, $currentPartyKey);
    }

    /**
     * DISPLAY-TIME re-render of every already-rendered insertable-block region so
     * the SIGNING surface shows the INTERACTIVE block (the "+ Add condition"
     * button + the current party's clickable initial slots), regardless of what
     * the STORED canonical holds.
     *
     * The stored canonical is baked STATIC (CONTEXT_PDF_RENDER via
     * refreshInsertableBlocks — no chrome, so the PDF prints clean). That static
     * form is served to every surface, including signing — which is why the add
     * button + initial affordances vanished on the recipient/agent signing view.
     * This swaps each `<div class="insertable-block" data-block-id>` in the SERVED
     * html for a fresh render in the viewer's context (block_id + purpose +
     * auto_number read off the div), leaving the stored canonical untouched.
     * Filled initials still render as adopted ink; the print path stays static.
     */
    public function reRenderBlocksForViewer(
        string $html,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken = null,
        ?string $currentPartyKey = null
    ): string {
        if ($html === '' || ! str_contains($html, 'insertable-block')) {
            return $html;
        }
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath   = new \DOMXPath($dom);
            $nodes   = iterator_to_array($xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " insertable-block ")][@data-block-id]'
            ));
            $changed = false;
            foreach ($nodes as $old) {
                if (! $old instanceof \DOMElement) {
                    continue;
                }
                $blockId = $old->getAttribute('data-block-id');
                if ($blockId === '') {
                    continue;
                }
                $block = [
                    'id'          => $blockId,
                    'purpose'     => $old->getAttribute('data-purpose') ?: 'other_conditions',
                    'auto_number' => $old->getAttribute('data-auto-number') === '1',
                ];
                $freshHtml = $this->renderBlockContainer($block, $doc, $context, $signingToken, $currentPartyKey);
                if (trim($freshHtml) === '') {
                    continue;
                }
                $frag = new \DOMDocument();
                @$frag->loadHTML(
                    '<?xml encoding="utf-8"?><div id="__w">' . $freshHtml . '</div>',
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
                );
                $new = null;
                foreach ($frag->getElementsByTagName('div') as $d) {
                    if ($d->getAttribute('data-block-id') === $blockId) {
                        $new = $d;
                        break;
                    }
                }
                if ($new === null) {
                    continue;
                }
                $imported = $dom->importNode($new, true);
                $old->parentNode?->replaceChild($imported, $old);
                $changed = true;
            }
            if (! $changed) {
                return $html;
            }
            $out = $dom->saveHTML();
            $out = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', (string) $out);
            return trim((string) $out);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private function renderConditionRow(
        DocumentCondition $c,
        string $context,
        ?SignatureTemplate $doc = null,
        ?string $signingToken = null,
        ?string $currentPartyKey = null
    ): string {
        $overrideBadge = '';
        if ($c->is_override && $c->overrides_clause_ref) {
            $overrideBadge = ' <span class="override-badge" '
                . 'style="display:inline-block; margin-left:0.4rem; padding:1px 6px; '
                . 'background:#fef3c7; color:#92400e; border-radius:3px; font-size:0.7rem;">'
                . 'Overrides clause ' . e((string) $c->overrides_clause_ref) . '</span>';
        }

        $relatesBadge = '';
        if (! $c->is_override && ! empty($c->relates_to_clause_ref)) {
            $relatesBadge = ' <a href="#" class="relates-badge" '
                . 'data-clause-ref="' . e((string) $c->relates_to_clause_ref) . '" '
                . 'onclick="(function(ref){var el=document.querySelector(\'[data-clause-ref=\"\'+ref+\'\"]\');if(el){el.scrollIntoView({behavior:\'smooth\',block:\'center\'});el.style.background=\'#fef3c7\';setTimeout(function(){el.style.background=\'\';},2000);}return false;})(\'' . e((string) $c->relates_to_clause_ref) . '\'); return false;" '
                . 'style="display:inline-block; margin-left:0.4rem; padding:1px 6px; '
                . 'background:#dbeafe; color:#1e40af; border-radius:3px; font-size:0.7rem; text-decoration:none;">'
                . 'Relates to clause ' . e((string) $c->relates_to_clause_ref) . '</a>';
        }

        // Phase 1B.7 (Part 4) — amendment-vs-original visual distinction.
        // Conditions whose amendment_id is set AND whose amendment is still
        // pending agent review render with a "Pending agent review" pill so
        // recipients see the amendment isn't yet authoritative.
        $amendmentBadge = '';
        if ($c->amendment_id) {
            $amendStatus = $c->amendment?->status ?? 'pending';
            if ($amendStatus === 'pending') {
                $amendmentBadge = ' <span class="amendment-badge" '
                    . 'style="display:inline-block; margin-left:0.4rem; padding:1px 6px; '
                    . 'background:#fef3c7; color:#92400e; border-radius:3px; font-size:0.7rem;">'
                    . 'Amendment pending agent review</span>';
            }
        }

        $lockedHint = $c->is_locked
            ? ' <span style="color:#6b7280; font-size:0.7rem; font-style:italic;">[locked]</span>'
            : '';

        // Phase 1B.7 (FIX B) — per-party initial slots.
        $initialsHtml = $doc
            ? $this->renderInitialSlotsForCondition($c, $doc, $context, $signingToken, $currentPartyKey)
            : '';

        return '<li class="condition-row" data-condition-id="' . $c->id . '" '
            . 'data-amendment-id="' . ($c->amendment_id ?? '') . '" '
            . 'style="margin: 0.5rem 0; padding-left: 0.2rem; display: list-item;">'
            . '<div class="condition-content">'
            . nl2br(e($c->content))
            . $overrideBadge
            . $relatesBadge
            . $amendmentBadge
            . $lockedHint
            . '</div>'
            . $initialsHtml
            . '</li>';
    }

    /**
     * Phase 1B.7 (FIX B + C) — render per-party initial slots beneath a
     * condition row. Each signing party on the document gets a slot showing
     * either the captured initial or an interactive placeholder.
     *
     * Slot states:
     *   filled   — ConditionInitial row exists for this (condition, party)
     *   active   — current signer + recipient_signing context (clickable)
     *   pending  — other party hasn't initialed yet (placeholder)
     *
     * The active slot triggers POST .../conditions/{id}/initial via
     * client-side handler (see add-condition-modal partial bootstrap).
     */
    private function renderInitialSlotsForCondition(
        DocumentCondition $c,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken,
        ?string $currentPartyKey
    ): string {
        $parties = $doc->parties_json ?? [];
        if (! is_array($parties) || empty($parties)) {
            return '';
        }

        // The interactive add-initial affordance belongs ONLY to the live
        // recipient-signing surface. Every other surface — agent prep/review,
        // the stored canonical, and the flattened PDF (print-from-approved) — is
        // STATIC: it renders each party's CAPTURED initial as their real adopted
        // ink and nothing else. No "click to initial" / "pending" signing chrome
        // ever bakes into the printed legal document. (Previously this returned
        // '' for CONTEXT_PDF_RENDER, so per-condition initials never printed.)
        $interactive = $context === self::CONTEXT_RECIPIENT_SIGNING;

        // Captured initials for THIS condition, keyed by party_key (stable —
        // condition_id + party_key; no positional collapse).
        $byParty = [];
        foreach ($c->initials as $initial) {
            $byParty[$initial->party_key] = $initial;
        }

        $slots = '';
        foreach ($parties as $party) {
            $partyKey   = (string) ($party['role'] ?? '');
            $partyLabel = (string) ($party['name'] ?? $party['role_label'] ?? ucfirst(str_replace('_', ' ', $partyKey)));
            if ($partyKey === '') continue;

            $existing = $byParty[$partyKey] ?? null;

            if ($existing) {
                // FILLED — render the party's REAL adopted initial ink (the same
                // drawn initial they use for page initials), resolved from
                // signed_initials. Falls back to a typed token only when the
                // party has no adopted initial on file.
                $ink   = $this->resolveAdoptedInitial($doc, $partyKey);
                $inner = $ink !== null
                    ? '<img src="' . $ink . '" class="corex-ink corex-ink--initial condition-initial-ink" alt="Initial" '
                        . 'style="height:26px; max-height:26px; width:auto; object-fit:contain; vertical-align:middle;">'
                    : '<strong style="letter-spacing:0.05em;">' . e($this->makeInitialsToken($partyLabel)) . '</strong>';
                $slots .= '<span class="condition-initial initial-filled" '
                    . 'data-condition-id="' . $c->id . '" data-party-key="' . e($partyKey) . '" data-signed="true" '
                    . 'style="display:inline-flex; align-items:center; gap:0.3rem; margin-right:0.7rem;">'
                    . $inner
                    . '<small style="color:#6b7280; font-size:0.6rem;">' . e($partyLabel) . '</small>'
                    . '</span>';
                continue;
            }

            if (! $interactive) {
                // Static surfaces: an un-initialed party contributes nothing to
                // the printed document for this condition (no pending chrome).
                continue;
            }

            $token  = $this->makeInitialsToken($partyLabel);
            $isMine = $currentPartyKey !== null
                && strcasecmp($currentPartyKey, $partyKey) === 0
                && $signingToken !== null;

            if ($isMine) {
                $slots .= '<button type="button" class="btn-add-initial initial-slot initial-active" '
                    . 'data-party-key="' . e($partyKey) . '" data-condition-id="' . $c->id . '" '
                    . 'data-signing-token="' . e($signingToken) . '" '
                    . 'style="display:inline-flex; flex-direction:column; align-items:center; padding:0.35rem 0.6rem; '
                    . 'background:#fff; border:1px dashed #0ea5e9; border-radius:4px; cursor:pointer; font-size:0.75rem;">'
                    . '<strong style="color:#0ea5e9; letter-spacing:0.05em;">' . e($token) . '</strong>'
                    . '<small style="color:#0369a1; font-size:0.65rem; margin-top:1px;">Click to initial</small>'
                    . '</button>';
            } else {
                $slots .= '<div class="initial-slot initial-pending" '
                    . 'data-party-key="' . e($partyKey) . '" data-condition-id="' . $c->id . '" '
                    . 'style="display:inline-flex; flex-direction:column; align-items:center; padding:0.35rem 0.6rem; '
                    . 'background:#f9fafb; border:1px solid #e5e7eb; border-radius:4px; font-size:0.75rem; opacity:0.85;">'
                    . '<strong style="color:#9ca3af; letter-spacing:0.05em;">' . e($token) . '</strong>'
                    . '<small style="color:#6b7280; font-size:0.65rem; margin-top:1px;">' . e($partyLabel) . ' &middot; pending</small>'
                    . '</div>';
            }
        }

        if ($slots === '') {
            return '';
        }

        return '<div class="condition-initials" '
            . 'style="display:flex; flex-wrap:wrap; align-items:center; gap:0.4rem; margin-top:0.35rem; '
            . 'padding-top:0.35rem; border-top:1px dashed #d1d5db;">' . $slots . '</div>';
    }

    /**
     * The party's REAL adopted initial ink (data-URI), resolved from the
     * document's captured `signed_initials` — the SAME drawn initial the party
     * uses for page initials. This is how a per-condition initial prints as ink:
     * the ConditionInitial record proves party X initialed condition Y; the
     * render shows X's adopted initial against Y. Returns null when the party
     * has no captured initial (caller falls back to a typed token).
     */
    private function resolveAdoptedInitial(SignatureTemplate $doc, string $partyKey): ?string
    {
        $wtd    = $doc->document?->web_template_data ?? [];
        $signed = $wtd['signed_initials'] ?? [];
        if (! is_array($signed)) {
            return null;
        }
        $wanted = strtolower($partyKey);

        // Group may be keyed by party ("seller" / "seller_2" / "agent"); match
        // case-insensitively.
        $group = null;
        foreach ($signed as $k => $v) {
            if (is_array($v) && strtolower((string) $k) === $wanted) {
                $group = $v;
                break;
            }
        }
        if (! is_array($group)) {
            return null;
        }
        // A party's initial is one mark used everywhere — take their first
        // captured image (init-0), the representative adopted initial.
        foreach ($group as $img) {
            if (is_string($img) && str_starts_with(trim($img), 'data:image')) {
                return $img;
            }
        }
        return null;
    }

    /**
     * Naive initials token derivation: first letter of each whitespace-
     * separated word, up to 3 letters. "John Smith" -> "JS", "Home
     * Finders Coastal" -> "HFC".
     */
    private function makeInitialsToken(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $p) {
            if ($p === '') continue;
            $letters .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($letters) >= 3) break;
        }
        return $letters !== '' ? $letters : '—';
    }

    /**
     * Render any `~~~~MARKER~~~~` tokens that aren't bound to a block
     * record in template metadata. Best-effort fallback so a literal marker
     * never reaches the recipient. Catches OTHER_CONDITIONS, INCLUDED_ITEMS,
     * EXCLUDED_ITEMS, and CUSTOM:<label> forms.
     *
     * E-sign reset Q3 — tolerance: the regex now allows ANY non-tilde
     * content between tildes (up to 200 chars to avoid runaway matching
     * across unrelated `~~~~` runs). The captured text is passed through
     * `normalisePurposeToken()` which strips HTML, normalises case +
     * whitespace, and fuzzy-matches against the known purpose tokens so
     * malformed markers like `~~~~<span>Other Contitions</span>~~~~`
     * (embedded HTML + misspelling — both observed in live template 111)
     * resolve to OTHER_CONDITIONS rather than rendering literally.
     */
    private function renderUnboundMarkers(
        string $html,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken,
        ?string $currentPartyKey = null
    ): string {
        return preg_replace_callback(
            '/~{4,}([^~]{1,200}?)~{4,}/s',
            function ($m) use ($doc, $context, $signingToken, $currentPartyKey) {
                $token = $this->normalisePurposeToken($m[1]);
                if ($token === null) {
                    // Unrecognisable marker text — leave the tildes in
                    // place rather than emitting an empty insertable
                    // block that confuses recipients.
                    return $m[0];
                }
                $synthBlock = $this->synthBlockFromToken($token);
                $conds = DocumentCondition::query()
                    ->where('signature_template_id', $doc->id)
                    ->where('block_id', $synthBlock['id'])
                    ->whereNull('superseded_at')
                    ->whereNull('deleted_at')
                    ->with('initials')
                    ->orderBy('condition_number')
                    ->get();
                return $this->renderBlockPartial($synthBlock, $conds, $doc, $context, $signingToken, $currentPartyKey);
            },
            $html
        );
    }

    /**
     * Canonical purpose tokens that drive synthBlockFromToken() + the
     * default-label map. Kept central so the tolerance logic + the
     * synth map can't drift.
     */
    private const CANONICAL_PURPOSE_TOKENS = [
        'OTHER_CONDITIONS',
        'INCLUDED_ITEMS',
        'EXCLUDED_ITEMS',
    ];

    /**
     * Normalise a raw marker capture into a canonical purpose token.
     *
     * Pipeline:
     *   1. Strip HTML tags + decode entities.
     *   2. Trim, uppercase, collapse whitespace → underscore.
     *   3. If the result starts with CUSTOM:, keep the label verbatim.
     *   4. Exact match against CANONICAL_PURPOSE_TOKENS → use it.
     *   5. Fuzzy match (Levenshtein ≤ 2 from a canonical) → assume that
     *      token (covers misspellings like "OTHER_CONTITIONS").
     *   6. If still no match, return the normalised token so the
     *      synth-block fallback can render it as `custom_named` — the
     *      recipient sees a labelled block (better than literal tildes).
     *   7. Return null only when the input is empty after stripping —
     *      that's the signal to keep the tildes untouched.
     */
    private function normalisePurposeToken(string $raw): ?string
    {
        $stripped = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned  = trim($stripped);
        if ($cleaned === '') {
            return null;
        }

        // CUSTOM:<label> preserves the original label after the colon —
        // strip HTML there too but keep mixed-case label.
        if (preg_match('/^\s*custom\s*:\s*(.+)$/i', $cleaned, $cm)) {
            $label = trim($cm[1]);
            return $label === '' ? 'CUSTOM:Unnamed' : 'CUSTOM:' . $label;
        }

        $candidate = strtoupper(preg_replace('/\s+/', '_', $cleaned));
        $candidate = preg_replace('/[^A-Z0-9_:]/', '', $candidate) ?? '';
        if ($candidate === '') {
            return null;
        }

        if (in_array($candidate, self::CANONICAL_PURPOSE_TOKENS, true)) {
            return $candidate;
        }

        // Per-document scope suffix (PACKS): `OTHER_CONDITIONS__<docKey>` keeps each
        // pack SEGMENT's other-conditions block independent (its own block_id, its
        // own frames + per-frame initials — a condition on doc A never bleeds to
        // doc B). Preserve the scoped token verbatim so synthBlockFromToken derives
        // a per-segment block_id. Must run BEFORE the fuzzy pass, which would
        // otherwise collapse a short-docKey token back onto the bare canonical.
        if (preg_match('/^(OTHER_CONDITIONS|INCLUDED_ITEMS|EXCLUDED_ITEMS)__[A-Z0-9_]+$/', $candidate)) {
            return $candidate;
        }

        // Fuzzy match — catch common misspellings (typo'd one char,
        // dropped underscore, etc.) without inviting unrelated tokens
        // to collapse onto canonical ones.
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach (self::CANONICAL_PURPOSE_TOKENS as $canonical) {
            $d = levenshtein($candidate, $canonical);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $canonical;
            }
        }
        if ($best !== null && $bestDistance <= 2) {
            return $best;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function synthBlockFromToken(string $token): array
    {
        if (str_starts_with($token, 'CUSTOM:')) {
            $label = trim(substr($token, 7));
            return [
                'id'              => 'custom_' . \Illuminate\Support\Str::slug($label !== '' ? $label : 'unnamed', '_'),
                'purpose'         => 'custom_named',
                'label'           => $label !== '' ? $label : 'Custom block',
                'custom_label'    => $label,
                'position_marker' => "~~~~CUSTOM:{$label}~~~~",
                'auto_number'     => false,
                'locked'          => false,
            ];
        }
        $purposeMap = [
            'OTHER_CONDITIONS' => 'other_conditions',
            'INCLUDED_ITEMS'   => 'included_items',
            'EXCLUDED_ITEMS'   => 'excluded_items',
        ];

        // Per-segment scoped token (packs): `OTHER_CONDITIONS__<docKey>` → a
        // block_id scoped to that pack document, so each segment's conditions +
        // per-frame initials are independent. Bare (single-doc) tokens keep
        // block_id `other_conditions` unchanged (backward-compatible).
        if (preg_match('/^(OTHER_CONDITIONS|INCLUDED_ITEMS|EXCLUDED_ITEMS)__(.+)$/', $token, $sm)) {
            $canonical = $sm[1];
            $purpose   = $purposeMap[$canonical];
            $scope     = \Illuminate\Support\Str::slug($sm[2], '_');
            return [
                'id'              => strtolower($canonical) . '__' . $scope,
                'purpose'         => $purpose,
                'label'           => $this->defaultLabelFor($purpose),
                'position_marker' => "~~~~{$token}~~~~",
                'auto_number'     => $purpose === 'other_conditions',
                'locked'          => false,
            ];
        }

        $purpose = $purposeMap[$token] ?? 'custom_named';
        return [
            'id'              => strtolower($token),
            'purpose'         => $purpose,
            'label'           => $this->defaultLabelFor($purpose),
            'position_marker' => "~~~~{$token}~~~~",
            'auto_number'     => $purpose === 'other_conditions',
            'locked'          => false,
        ];
    }

    private function defaultLabelFor(string $purpose): string
    {
        return match ($purpose) {
            'other_conditions' => 'Other Conditions',
            'included_items'   => 'Included Items',
            'excluded_items'   => 'Excluded Items',
            default            => 'Insertable Block',
        };
    }
}
