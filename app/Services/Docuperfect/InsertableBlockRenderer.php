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
        // Skip slot rendering for PDF flatten (no interactive UI).
        if ($context === self::CONTEXT_PDF_RENDER) {
            return '';
        }

        $parties = $doc->parties_json ?? [];
        if (! is_array($parties) || empty($parties)) {
            return '';
        }

        // Index the already-captured initials by party_key for O(1) lookup.
        $byParty = [];
        foreach ($c->initials as $initial) {
            $byParty[$initial->party_key] = $initial;
        }

        $slots = '<div class="condition-initials" '
            . 'style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.4rem; padding-top:0.4rem; '
            . 'border-top:1px dashed #d1d5db;">';

        foreach ($parties as $party) {
            $partyKey   = (string) ($party['role'] ?? '');
            $partyLabel = (string) ($party['name'] ?? $party['role_label'] ?? ucfirst(str_replace('_', ' ', $partyKey)));
            if ($partyKey === '') continue;

            $existing = $byParty[$partyKey] ?? null;
            $initials = $this->makeInitialsToken($partyLabel);

            if ($existing) {
                $slots .= '<div class="initial-slot initial-filled" '
                    . 'data-party-key="' . e($partyKey) . '" data-condition-id="' . $c->id . '" '
                    . 'style="display:inline-flex; flex-direction:column; align-items:center; padding:0.35rem 0.6rem; '
                    . 'background:#ecfdf5; border:1px solid #047857; border-radius:4px; font-size:0.75rem;">'
                    . '<strong style="color:#047857; letter-spacing:0.05em;">' . e($initials) . '</strong>'
                    . '<small style="color:#065f46; font-size:0.65rem; margin-top:1px;">'
                    . e($partyLabel) . ' &middot; ' . e($existing->initialed_at?->format('d M H:i') ?? '')
                    . '</small></div>';
                continue;
            }

            // Determine whether THIS party is the current signer + the
            // context permits a click (recipient signing only).
            $isMine = $currentPartyKey !== null
                && strcasecmp($currentPartyKey, $partyKey) === 0
                && $context === self::CONTEXT_RECIPIENT_SIGNING
                && $signingToken !== null;

            if ($isMine) {
                $slots .= '<button type="button" class="btn-add-initial initial-slot initial-active" '
                    . 'data-party-key="' . e($partyKey) . '" data-condition-id="' . $c->id . '" '
                    . 'data-signing-token="' . e($signingToken) . '" '
                    . 'style="display:inline-flex; flex-direction:column; align-items:center; padding:0.35rem 0.6rem; '
                    . 'background:#fff; border:1px dashed #0ea5e9; border-radius:4px; cursor:pointer; font-size:0.75rem;">'
                    . '<strong style="color:#0ea5e9; letter-spacing:0.05em;">' . e($initials) . '</strong>'
                    . '<small style="color:#0369a1; font-size:0.65rem; margin-top:1px;">Click to initial</small>'
                    . '</button>';
            } else {
                $slots .= '<div class="initial-slot initial-pending" '
                    . 'data-party-key="' . e($partyKey) . '" data-condition-id="' . $c->id . '" '
                    . 'style="display:inline-flex; flex-direction:column; align-items:center; padding:0.35rem 0.6rem; '
                    . 'background:#f9fafb; border:1px solid #e5e7eb; border-radius:4px; font-size:0.75rem; opacity:0.85;">'
                    . '<strong style="color:#9ca3af; letter-spacing:0.05em;">' . e($initials) . '</strong>'
                    . '<small style="color:#6b7280; font-size:0.65rem; margin-top:1px;">' . e($partyLabel) . ' &middot; pending</small>'
                    . '</div>';
            }
        }

        $slots .= '</div>';
        return $slots;
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
     */
    private function renderUnboundMarkers(
        string $html,
        SignatureTemplate $doc,
        string $context,
        ?string $signingToken,
        ?string $currentPartyKey = null
    ): string {
        return preg_replace_callback(
            '/~{4,}([A-Z_]+(?::[^~]+)?)~{4,}/',
            function ($m) use ($doc, $context, $signingToken, $currentPartyKey) {
                $token = $m[1];
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
