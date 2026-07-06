<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Rendering;

use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\BlockType;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Field;
use App\Support\Docuperfect\Cds\PartyExpr;

/**
 * AT-177 / WS2 — the render-only runtime (spec §6). ONE renderer, three delivery modes,
 * ZERO divergent paths.
 *
 * The renderer is a pure function of the canonical CDS: `render(CDS, party, mode) → surface`.
 * It reads ONLY the compiled structure — no `merged_html`, no request-time normalisation, no
 * compensators. The compiled tree already IS the normalised surface (spec §2), so rendering
 * is a straight, deterministic walk of declared blocks.
 *
 * Web and print share the SAME block-walk and emit the SAME semantic skeleton (block ids/types,
 * bound values, anchor party+kind); only presentation classes differ. That is what makes L6
 * render-parity a true guarantee rather than a tautology (see {@see RenderedSurface}).
 */
final class CdsRenderer
{
    /**
     * Render the FULL document for a party combination (used by PDF wet-ink, download, and the
     * L6 parity check). `$values` maps field_id → resolved value (empty for an unsigned template).
     *
     * @param list<string>          $activePartyKeys instances present, e.g. ['seller_1','buyer_1','agent']
     * @param array<string,string>  $values
     */
    public function renderDocument(Cds $cds, DeliveryMode $mode, array $activePartyKeys, array $values = []): RenderedSurface
    {
        $html = $this->renderBlocks($cds, $mode, $activePartyKeys, $values, viewerPartyKey: null);

        return new RenderedSurface($html, $mode, array_values($activePartyKeys));
    }

    /**
     * Render the web signing view PROJECTED for one signer (spec §6): only blocks visible to
     * the signer, fields marked editable per declared editability. The DOM is never the
     * authority — the server re-derives write permission from the CDS on save.
     *
     * @param list<string>         $activePartyKeys
     * @param array<string,string> $values
     */
    public function renderForSigner(Cds $cds, string $viewerPartyKey, array $activePartyKeys, array $values = []): RenderedSurface
    {
        $html = $this->renderBlocks($cds, DeliveryMode::WebEsign, $activePartyKeys, $values, viewerPartyKey: $viewerPartyKey);

        return new RenderedSurface($html, DeliveryMode::WebEsign, array_values($activePartyKeys), $viewerPartyKey);
    }

    /**
     * Render the interactive SIGNING VIEW for a signer (AT-177/WS6 compiled serving).
     *
     * Unlike {@see renderForSigner()}, this does NOT hide other parties' blocks — a signer must
     * SEE the whole document (all clauses + every signature line, others' shown pending). It
     * stamps `data-viewer-editable="1"` on the fields THIS signer may edit (the signing JS gates
     * input by that attribute) and emits the `data-marker-party`/`data-marker-type="signature"`
     * surfaces the JS activates for the signer's own role. This is the compiled replacement for
     * the legacy merged_html + RoleBlockExpansionService `data-viewer-editable` stamping.
     *
     * @param list<string>         $activePartyKeys all present recipient instances
     * @param array<string,string> $values
     */
    public function renderSigningView(Cds $cds, string $signerPartyKey, array $activePartyKeys, array $values = []): RenderedSurface
    {
        $html = $this->renderBlocks($cds, DeliveryMode::WebEsign, $activePartyKeys, $values, viewerPartyKey: $signerPartyKey, filterVisibility: false);

        return new RenderedSurface($html, DeliveryMode::WebEsign, array_values($activePartyKeys), $signerPartyKey);
    }

    /**
     * @param list<string>         $activePartyKeys
     * @param array<string,string> $values
     */
    private function renderBlocks(Cds $cds, DeliveryMode $mode, array $activePartyKeys, array $values, ?string $viewerPartyKey, bool $filterVisibility = true): string
    {
        $scenario = $this->scenario($activePartyKeys, $values);
        $isWeb = $mode === DeliveryMode::WebEsign;

        $out = [];
        foreach ($cds->blocks() as $block) {
            // Instance-presence: does this block exist in this party combination? (L4 axis.)
            if (! $block->condition->evaluate($scenario)) {
                continue;
            }
            // Per-signer projection: hide blocks this signer may not see (skipped for the signing
            // view, where the signer sees the whole document — WS6 renderSigningView).
            if ($viewerPartyKey !== null && $filterVisibility && ! $block->visibility->appliesTo($viewerPartyKey)) {
                continue;
            }

            $out[] = $this->renderBlock($block, $isWeb, $viewerPartyKey, $activePartyKeys, $values);
        }

        return implode("\n", array_filter($out, static fn (string $h): bool => $h !== ''));
    }

    /**
     * @param list<string>         $activePartyKeys
     * @param array<string,string> $values
     */
    private function renderBlock(Block $block, bool $isWeb, ?string $viewerPartyKey, array $activePartyKeys, array $values): string
    {
        $id = e($block->blockId);
        $type = $block->type->value;

        return match ($block->type) {
            BlockType::Prose, BlockType::Clause, BlockType::Conditional => sprintf(
                '<section data-block-id="%s" data-block-type="%s" class="cds-block cds-%s">%s</section>',
                $id, e($type), e($type), $block->html ?? '',
            ),
            BlockType::Letterhead => sprintf(
                '<header data-block-id="%s" data-block-type="letterhead" class="cds-letterhead">%s</header>',
                $id, $block->html ?? '',
            ),
            BlockType::PageBreak => sprintf(
                '<div data-block-id="%s" data-block-type="page_break" class="cds-page-break"></div>',
                $id,
            ),
            BlockType::FieldGroup => $this->renderFieldGroup($block, $isWeb, $viewerPartyKey, $values),
            BlockType::Signature, BlockType::Initial => $this->renderSignatureBlock($block, $isWeb, $activePartyKeys),
            BlockType::InsertableSlot => sprintf(
                '<div data-block-id="%s" data-block-type="insertable_slot" data-slot-id="%s" class="cds-slot"></div>',
                $id, e($block->slot?->slotId ?? $block->blockId),
            ),
        };
    }

    /**
     * @param array<string,string> $values
     */
    private function renderFieldGroup(Block $block, bool $isWeb, ?string $viewerPartyKey, array $values): string
    {
        $editable = $isWeb && $viewerPartyKey !== null && $block->editability->appliesTo($viewerPartyKey);

        $fields = [];
        foreach ($block->fields as $field) {
            /** @var Field $field */
            $value = $values[$field->fieldId] ?? '';
            $fields[] = $this->renderField($field, $isWeb, $editable, $value);
        }

        return sprintf(
            '<section data-block-id="%s" data-block-type="field_group" class="cds-block cds-field-group">%s</section>',
            e($block->blockId), implode('', $fields),
        );
    }

    private function renderField(Field $field, bool $isWeb, bool $editable, string $value): string
    {
        $label = sprintf('<span class="cds-field-label">%s</span>', e($field->label));

        if ($isWeb) {
            $control = $editable
                ? sprintf(
                    // data-viewer-editable="1" = the contract the signing-view JS gates input on
                    // (compiled replacement for RoleBlockExpansionService's serve-time stamping).
                    '<input type="text" data-field-id="%s" data-binding="%s" data-viewer-editable="1" class="cds-field-input" value="%s"%s>',
                    e($field->fieldId), e($field->binding), e($value), $field->required ? ' required' : '',
                )
                : sprintf(
                    '<span data-field-id="%s" data-binding="%s" class="cds-field-value">%s</span>',
                    e($field->fieldId), e($field->binding), e($value),
                );
        } else {
            // Print: the bound value on a rule, or a blank fill-line.
            $control = sprintf(
                '<span data-field-id="%s" data-binding="%s" class="cds-field-print">%s</span>',
                e($field->fieldId), e($field->binding), $value !== '' ? e($value) : '<span class="cds-fill-line"></span>',
            );
        }

        return sprintf('<label class="cds-field">%s%s</label>', $label, $control);
    }

    /**
     * @param list<string> $activePartyKeys
     */
    private function renderSignatureBlock(Block $block, bool $isWeb, array $activePartyKeys): string
    {
        $surfaceClass = $isWeb ? 'cds-sign-surface cds-sign-web' : 'cds-sign-surface cds-sign-print';

        $anchors = [];
        foreach ($block->anchors as $anchor) {
            $role = PartyExpr::roleBase($anchor->partyKey);
            $roleLabel = ucwords(str_replace(['_', '-'], ' ', $role));
            $kindLabel = ucfirst($anchor->kind->value);

            // COMPILED role-loop expansion (obsoletes RoleBlockExpansionService's LCA guessing):
            // one signable surface per PRESENT INSTANCE of the anchor's role. Instances are the
            // active-combination keys whose role base matches (e.g. seller_1, seller_2). A
            // template preview with no specific instance falls back to the declared role once.
            $instances = array_values(array_filter(
                $activePartyKeys,
                static fn (string $key): bool => PartyExpr::roleBase($key) === $role,
            ));
            if ($instances === []) {
                $instances = [$anchor->partyKey];
            }

            foreach ($instances as $idx => $instanceKey) {
                // Live marker convention (recon §2): first present recipient = role base, then
                // "{role}_2", "{role}_3" … — kept for backward-compatible engine selectors.
                $markerKey = $idx === 0 ? $role : $role . '_' . ($idx + 1);

                // Compiled anchor identity is the unambiguous instance key.
                $anchors[] = sprintf(
                    '<div data-anchor-id="%s" data-anchor-party="%s" data-anchor-kind="%s" '
                    . 'data-marker-party="%s" data-marker-type="%s" data-marker-index="%d" class="%s">'
                    . '<span class="cds-sign-line"></span>'
                    . '<span class="cds-sign-caption">%s — %s</span>'
                    . '</div>',
                    e($anchor->anchorId . '__' . $instanceKey), e($instanceKey), e($anchor->kind->value),
                    e($markerKey), e($anchor->kind->value), $idx, $surfaceClass,
                    e($roleLabel), e($kindLabel),
                );
            }
        }

        return sprintf(
            '<section data-block-id="%s" data-block-type="%s" class="cds-block cds-signature">%s</section>',
            e($block->blockId), e($block->type->value), implode('', $anchors),
        );
    }

    /**
     * Build the L4 scenario context for condition evaluation.
     *
     * @param list<string>         $activePartyKeys
     * @param array<string,string> $values
     * @return array{present_parties:list<string>,party_counts:array<string,int>,field_values:array<string,mixed>}
     */
    private function scenario(array $activePartyKeys, array $values): array
    {
        $counts = [];
        foreach ($activePartyKeys as $key) {
            $base = PartyExpr::roleBase($key);
            $counts[$base] = ($counts[$base] ?? 0) + 1;
        }

        return [
            'present_parties' => array_values($activePartyKeys),
            'party_counts' => $counts,
            'field_values' => $values,
        ];
    }
}
