<?php

namespace App\Services\Docuperfect;

class CdsRendererService
{
    /** @var array Signing parties extracted from signature_section — used by renderPageInitials. */
    private array $signingParties = [];

    /**
     * Render CDS JSON to HTML using CoreX document classes.
     */
    public function render(array $cds): string
    {
        // Pre-pass: extract parties from signature_section so page_initials can use them
        $this->signingParties = [];
        foreach ($cds['sections'] ?? [] as $section) {
            if (($section['type'] ?? '') === 'signature_section' && !empty($section['parties'])) {
                $this->signingParties = $section['parties'];
                break;
            }
            if (($section['type'] ?? '') === 'inline_signature' && !empty($section['parties'])) {
                $this->signingParties = array_merge($this->signingParties, $section['parties']);
            }
        }

        $html = '';

        foreach ($cds['sections'] ?? [] as $section) {
            $html .= $this->renderSection($section);
        }

        return $html;
    }

    private function renderSection(array $section): string
    {
        return match ($section['type']) {
            'heading' => $this->renderHeading($section),
            'clause' => $this->renderClause($section),
            'paragraph' => $this->renderParagraph($section),
            'table' => $this->renderTable($section),
            'title' => $this->renderTitle($section),
            // AT-177 (on-site 2026-07-18) — STRIP the source doc's own letterhead and end
            // signature block. CoreX ALWAYS frames the generated template with its own
            // company-header and signature-block components (TemplateController::generateCdsBladeView
            // lines 953 / 980), so rendering the source's here produced a DOUBLE header and a
            // DOUBLE signature block. The source structural frame is replaced, never appended to.
            // (Mid-document inline_signature blocks and the D4 "__ / Signature" acknowledgement
            // placeholders are a DIFFERENT type and still render — only the whole-doc frame is stripped.)
            'company_header' => '',
            'signature_section' => '',
            'inline_signature' => $this->renderInlineSignature($section),
            'page_initials' => $this->renderPageInitials($section),
            'label_value_group' => $this->renderLabelValueGroup($section),
            'disclosure_checklist' => $this->renderDisclosureChecklist($section),
            default => '',
        };
    }

    private function renderHeading(array $section): string
    {
        $level = $section['level'] ?? 1;
        $class = match ($level) {
            1 => 'corex-h1',
            2 => 'corex-h2',
            default => 'corex-h3',
        };

        $number = isset($section['number']) ? "{$section['number']}. &nbsp;" : '';
        $text = e($section['text'] ?? $this->contentToText($section['content'] ?? []));
        $look = $this->sourceLookAttributes($section, true);

        return "<div class=\"{$class}\"{$look}>{$number}{$text}</div>\n";
    }

    /**
     * Document look — mark what the SOURCE document did to this block, as inert data
     * attributes. They change nothing on their own: only a template whose look is "Match
     * source document" (wrapper class `corex-look-source`, rules in corex-document.css)
     * acts on them, so the CoreX standard look renders exactly as before.
     *
     *   data-src-align="center|right|justify"  — the source paragraph's alignment
     *   data-src-band="1"                       — a section heading to draw as a grey band:
     *                                             the source shaded it, or it is a bold
     *                                             all-caps title.
     */
    private function sourceLookAttributes(array $section, bool $isHeading = false): string
    {
        $attrs = '';

        $align = $section['align'] ?? null;
        if (in_array($align, ['center', 'right', 'justify'], true)) {
            $attrs .= ' data-src-align="' . $align . '"';
        }

        if ($isHeading && (!empty($section['shading']) || $this->isBoldAllCaps($section))) {
            $attrs .= ' data-src-band="1"';
        }

        return $attrs;
    }

    /** A bold, all-caps heading ("DEFINITIONS", "LEASE AGREEMENT") — a section title. */
    private function isBoldAllCaps(array $section): bool
    {
        $text = (string) ($section['text'] ?? $this->contentToText($section['content'] ?? []));
        $letters = preg_replace('/[^A-Za-z]/', '', $text);
        if (strlen($letters) < 3 || $letters !== strtoupper($letters)) {
            return false;
        }

        $runs = array_filter($section['content'] ?? [], fn ($i) => ($i['type'] ?? '') === 'text' && trim((string) ($i['value'] ?? '')) !== '');

        return $runs !== [] && count(array_filter($runs, fn ($i) => !empty($i['bold']))) === count($runs);
    }

    private function renderClause(array $section): string
    {
        $level = $section['level'] ?? 1;
        $indent = min($level, 3);
        $number = $section['number'] ?? '';
        $content = $this->renderContent($section['content'] ?? []);

        $look = $this->sourceLookAttributes($section);

        return "<div class=\"corex-clause corex-clause-indent-{$indent}\"{$look}>"
            . "<span class=\"corex-clause-number\">{$number}</span> "
            . "<span class=\"corex-clause-text\">{$content}</span>"
            . "</div>\n";
    }

    private function renderParagraph(array $section): string
    {
        $content = $this->renderContent($section['content'] ?? []);
        $look = $this->sourceLookAttributes($section);

        return "<div class=\"corex-clause corex-clause-indent-1\"{$look}>"
            . "<span class=\"corex-clause-text\">{$content}</span>"
            . "</div>\n";
    }

    private function renderTitle(array $section): string
    {
        $text = e($section['text'] ?? $this->contentToText($section['content'] ?? []));
        return ""; // Title handled by the Blade component, not inline
    }

    private function renderTable(array $section): string
    {
        $colWidths = $this->tableColumnWidths($section);
        $html = '<table class="corex-table"' . $this->tableGeometryAttributes($section, $colWidths) . '>';
        $html .= $this->tableColgroup($colWidths);

        // The first rendered row also carries the column widths on its cells: dompdf (the print-and-sign
        // PDF) honours cell widths more reliably than <col>.
        $widthsOnCells = $colWidths;

        if (!empty($section['headers'])) {
            $html .= '<thead><tr>';
            $html .= $this->tableCells('th', $section['headers'], $section['header_meta'] ?? [], $widthsOnCells);
            $html .= '</tr></thead>';
            $widthsOnCells = [];
        }

        $html .= '<tbody>';
        foreach ($section['rows'] ?? [] as $i => $row) {
            $html .= '<tr>';
            $html .= $this->tableCells('td', $row, $section['cell_meta'][$i] ?? [], $widthsOnCells);
            $html .= '</tr>';
            $widthsOnCells = [];
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * The imported table's column widths as percentages of the table, or [] when the source stated
     * none (a table stored before widths were read renders exactly as it always did).
     */
    private function tableColumnWidths(array $section, ?int $expectedColumns = null): array
    {
        $widths = array_values(array_filter((array) ($section['col_widths'] ?? []), fn ($w) => is_numeric($w) && $w > 0));
        if (count($widths) < 2 || count($widths) !== count((array) ($section['col_widths'] ?? []))) {
            return [];
        }
        if ($expectedColumns !== null && count($widths) !== $expectedColumns) {
            return [];
        }

        return array_map('floatval', $widths);
    }

    /** ` style="…" data-src-geometry="1"` for a table with stored geometry; '' when it has none. */
    private function tableGeometryAttributes(array $section, array $colWidths): string
    {
        $style = [];
        $widthPct = isset($section['width_pct']) && is_numeric($section['width_pct']) ? (float) $section['width_pct'] : null;
        if ($widthPct !== null) {
            $style[] = 'width:' . $this->pct($widthPct);
        }
        if ($colWidths !== []) {
            $style[] = 'table-layout:fixed';
        }
        if (($section['align'] ?? '') === 'center') {
            $style[] = 'margin-left:auto;margin-right:auto';
        } elseif (($section['align'] ?? '') === 'right') {
            $style[] = 'margin-left:auto';
        }

        return $style === []
            ? ''
            : ' style="' . implode(';', $style) . '" data-src-geometry="1"';
    }

    private function tableColgroup(array $colWidths): string
    {
        if ($colWidths === []) {
            return '';
        }
        $html = '<colgroup>';
        foreach ($colWidths as $w) {
            $html .= '<col style="width:' . $this->pct($w) . '">';
        }

        return $html . '</colgroup>';
    }

    /**
     * One row of cells. $meta[i] may carry a merged-cell span and the source's alignment for the cell;
     * $colWidths (first row only) puts each cell's width on the cell itself.
     */
    private function tableCells(string $tag, array $cells, array $meta, array $colWidths): string
    {
        $html = '';
        $col = 0;
        foreach ($cells as $i => $cell) {
            $span = max(1, (int) ($meta[$i]['span'] ?? 1));
            $attrs = $span > 1 ? ' colspan="' . $span . '"' : '';
            $style = [];
            if ($colWidths !== []) {
                $style[] = 'width:' . $this->pct(array_sum(array_slice($colWidths, $col, $span)));
            }
            $align = $meta[$i]['align'] ?? null;
            if (in_array($align, ['center', 'right', 'justify'], true)) {
                $style[] = 'text-align:' . $align;
            }
            if ($style !== []) {
                $attrs .= ' style="' . implode(';', $style) . '"';
            }
            $html .= "<{$tag}{$attrs}>" . e($cell) . "</{$tag}>";
            $col += $span;
        }

        return $html;
    }

    private function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * Render content array (text runs with formatting) to HTML.
     */
    private function renderContent(array $content): string
    {
        $html = '';
        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'field_placeholder') {
                $label = $item['label'] ?? 'FIELD';
                $fieldName = $item['field_name'] ?? '';
                $fieldType = $item['field_type'] ?? 'text';
                $confidence = $item['confidence'] ?? 'low';

                $borderColor = match($confidence) {
                    'high' => '#22c55e',
                    'medium' => '#f97316',
                    default => '#ef4444',
                };

                $html .= '<span class="corex-field" '
                    . 'data-field-name="' . e($fieldName) . '" '
                    . 'data-field-type="' . e($fieldType) . '" '
                    . 'data-field-label="' . e($label) . '" '
                    . 'data-confidence="' . e($confidence) . '" '
                    . 'style="border-color:' . $borderColor . ';">'
                    . '<span class="corex-field-label">' . e($label) . '</span>'
                    . '</span>';
                continue;
            }

            // signature_placeholder from markers (%%%%)
            if (($item['type'] ?? '') === 'signature_placeholder') {
                // AT-177 D4 — carry a server-suggested party roster / variant (from the
                // "____ / Signature" acknowledgement detector) so the builder pre-binds the
                // sig tag to Seller + Agent, sig_only, out of the box.
                $sigExtra = '';
                if (! empty($item['suggested_parties']) && is_array($item['suggested_parties'])) {
                    $labels = collect($item['suggested_parties'])
                        ->map(fn ($p) => $p['label'] ?? $p['role'] ?? '')
                        ->filter()
                        ->implode(',');
                    if ($labels !== '') {
                        $sigExtra .= ' data-sig-parties="' . e($labels) . '"';
                    }
                }
                if (! empty($item['suggested_variant'])) {
                    $sigExtra .= ' data-sig-variant="' . e($item['suggested_variant']) . '"';
                }
                $html .= '<span class="corex-field" '
                    . 'data-marker-type="signature"' . $sigExtra . ' '
                    . 'style="background:#fffbeb;border-color:#f59e0b;">'
                    . '<span class="corex-field-label">SIGNATURE</span>'
                    . '</span>';
                continue;
            }

            // initial_placeholder from markers (####)
            if (($item['type'] ?? '') === 'initial_placeholder') {
                $html .= '<span class="corex-field" '
                    . 'data-marker-type="initial" '
                    . 'style="background:#ecfdf5;border-color:#22c55e;">'
                    . '<span class="corex-field-label">INITIAL</span>'
                    . '</span>';
                continue;
            }

            // AT-262 — a CUSTOM-NAMED insertable marker (~~~~Seller - Full name~~~~) is
            // a bindable FIELD with a human label — that is the entire point of the
            // named-marker syntax. Render it as a field chip so it appears on the
            // canvas and can be bound, exactly like a @@@@ field_placeholder. Built-in
            // insertable blocks (OTHER_CONDITIONS / INCLUDED_ITEMS / EXCLUDED_ITEMS)
            // are agent-editable AREAS, not fields — their compile-time behaviour is
            // left untouched (they are not rendered as a chip here).
            if (($item['type'] ?? '') === 'insertable_block_placeholder') {
                if (($item['purpose'] ?? '') === 'custom_named') {
                    $label = $item['custom_label'] ?? $item['raw_token'] ?? 'FIELD';
                    $fieldName = $item['block_id'] ?? '';
                    $html .= '<span class="corex-field" '
                        . 'data-field-name="' . e($fieldName) . '" '
                        . 'data-field-type="text" '
                        . 'data-field-label="' . e($label) . '" '
                        . 'data-confidence="high" '
                        . 'style="border-color:#22c55e;">'
                        . '<span class="corex-field-label">' . e($label) . '</span>'
                        . '</span>';
                }
                continue;
            }

            if (($item['type'] ?? '') !== 'text') continue;

            $text = e($item['value'] ?? '');

            if (!empty($item['bold'])) $text = "<strong>{$text}</strong>";
            if (!empty($item['italic'])) $text = "<em>{$text}</em>";
            if (!empty($item['underline'])) $text = "<u>{$text}</u>";

            $html .= $text;
        }
        return $html;
    }

    private function renderLabelValueGroup(array $section): string
    {
        $html = '<table class="corex-table" style="margin: 8px 0;">';

        foreach ($section['pairs'] ?? [] as $pair) {
            $html .= '<tr>';
            $html .= '<td style="white-space: nowrap; vertical-align: top; padding-right: 16px; width: 40%;">'
                . e($pair['label']) . '</td>';
            $html .= '<td>';
            foreach ($pair['fields'] ?? [] as $field) {
                $label = $field['label'] ?? 'FIELD';
                $name = $field['field_name'] ?? '';
                $type = $field['field_type'] ?? 'text';
                $html .= '<span class="corex-field" data-field-name="' . e($name) . '" data-field-type="' . e($type) . '">'
                    . '<span class="corex-field-label">' . e($label) . '</span>'
                    . '</span> ';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    private function renderSignatureSection(array $section): string
    {
        $html = '<div class="corex-signature-section">';
        $html .= '<div class="corex-signature-section-title">THUS DONE AND SIGNED</div>';

        if (!empty($section['preamble'])) {
            $html .= '<div class="corex-clause"><span class="corex-clause-text">'
                . e($section['preamble'])
                . '</span></div>';
        }

        $parties = $section['parties'] ?? [];
        if (!empty($parties)) {
            $html .= '<div class="corex-signature-grid">';
            foreach ($parties as $party) {
                $role = $party['role'] ?? 'party';
                $label = strtoupper($party['label'] ?? $role);
                $isWitness = $role === 'witness';
                $blockClass = $isWitness
                    ? 'corex-signature-block corex-signature-block-witness'
                    : 'corex-signature-block';

                $html .= '<div class="' . $blockClass . '">';
                $html .= '<div class="corex-signature-role">' . e($label) . '</div>';
                $html .= '<div class="corex-signature-name">&nbsp;</div>';
                $html .= '<div class="corex-signature-line" data-marker-party="' . e($role) . '" data-marker-type="signature"><span class="corex-signature-prompt">Sign here</span></div>';
                $html .= '<div class="corex-signature-date">Date: _______________</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function renderInlineSignature(array $section): string
    {
        $parties = $section['parties'] ?? [];
        if (empty($parties)) return '';

        $html = '<div class="corex-signature-section" '
            . 'style="margin: 16px 0; padding-top: 12px; '
            . 'border-top: 1px solid #e2e8f0;">';

        $html .= '<div class="corex-signature-grid">';
        foreach ($parties as $party) {
            $role = strtoupper($party['label'] ?? $party['role'] ?? 'Party');
            $roleKey = strtolower($party['role'] ?? $party['label'] ?? 'party');
            $html .= '<div class="corex-signature-block">';
            $html .= '<div class="corex-signature-role">' . e($role) . '</div>';
            $html .= '<div class="corex-signature-line" data-marker-party="' . e($roleKey) . '" data-marker-type="signature">'
                . '<span class="corex-signature-prompt">Sign here</span></div>';
            $html .= '<div class="corex-signature-date">Date: _______________</div>';
            $html .= '</div>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private function renderPageInitials(array $section): string
    {
        $pageNum = $section['page_number'] ?? '';
        $parties = $this->signingParties;

        // If parties are known, generate per-party initials blocks with data-marker attributes
        // matching ESignWizardController::injectInitialsBlocks format
        if (!empty($parties)) {
            $blocks = '';
            foreach ($parties as $n => $party) {
                $role = strtolower($party['role'] ?? $party['label'] ?? 'party');
                $label = ucfirst(str_replace('_', ' ', $role));
                $blocks .= '<div class="corex-page-initials" '
                    . 'data-marker-party="' . e($role) . '" '
                    . 'data-marker-type="initial" '
                    . 'data-marker-index="' . $n . '" '
                    . 'style="display:inline-block;text-align:center;margin:0 6pt;width:60px;height:30px;'
                    . 'border:1px solid #94a3b8;font-size:9px;color:#64748b;cursor:pointer;'
                    . 'line-height:30px;">'
                    . '<span class="initial-placeholder">' . e($label) . '</span>'
                    . '</div>';
            }

            return '<div class="corex-page-break" style="margin:16px 0;">'
                . '<div class="corex-page-initials-row" style="display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:12px 0 4px 0;">'
                . $blocks
                . '</div>'
                . '<div style="border-top:2px dashed #cbd5e1;margin:8px 0;position:relative;">'
                . '<span style="position:absolute;right:0;top:-10px;font-size:10px;color:#94a3b8;font-style:italic;background:white;padding:0 4px;">Page ' . e($pageNum) . '</span>'
                . '</div>'
                . '</div>';
        }

        // Fallback: no parties known — generic placeholder (template preview/builder)
        $html = '<div class="corex-page-initials" '
            . 'style="display: flex; justify-content: flex-end; '
            . 'align-items: center; gap: 6px; padding: 8px 0; '
            . 'margin: 12px 0; border-top: 0.5px solid #cbd5e1;">';

        $html .= '<span style="font-size: 8pt; color: #94a3b8; '
            . 'margin-right: auto;">Page ' . e($pageNum) . '</span>';

        $html .= '<span class="corex-page-initials-placeholder" '
            . 'style="display: flex; gap: 4px;" '
            . 'data-initials-page="' . e($pageNum) . '">';

        for ($i = 0; $i < 3; $i++) {
            $html .= '<span style="width: 28px; height: 20px; '
                . 'border: 0.5px solid #cbd5e1; border-radius: 2px; '
                . 'display: flex; align-items: center; '
                . 'justify-content: center; font-size: 7px; '
                . 'color: #94a3b8;">___</span>';
        }

        $html .= '</span></div>';

        return $html;
    }

    private function renderDisclosureChecklist(array $section): string
    {
        $hasNa = $section['has_na'] ?? false;
        $items = $section['items'] ?? [];
        $cols = $hasNa ? 4 : 3;

        $html = '<div class="corex-disclosure-checklist" '
            . 'data-section-type="disclosure_checklist">';

        // Keep the source's column widths when the Word grid has exactly the columns drawn here
        // (a grid with an extra Notes column cannot be mapped one-to-one, so it keeps the stylesheet widths).
        $colWidths = $this->tableColumnWidths($section, $cols);
        $html .= '<table class="corex-disclosure-table"' . $this->tableGeometryAttributes($section, $colWidths) . '>';
        $html .= $this->tableColgroup($colWidths);
        $html .= '<thead><tr>';
        $html .= '<th class="corex-disclosure-statement">Statement</th>';
        $html .= '<th class="corex-disclosure-option">YES</th>';
        $html .= '<th class="corex-disclosure-option">NO</th>';
        if ($hasNa) {
            $html .= '<th class="corex-disclosure-option">N/A</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($items as $idx => $item) {
            if ($item['type'] === 'sub_header') {
                $html .= '<tr class="corex-disclosure-subheader">';
                $html .= '<td colspan="' . $cols . '">'
                    . '<strong>' . e($item['text']) . '</strong></td>';
                $html .= '</tr>';
                continue;
            }

            $html .= '<tr class="corex-disclosure-row" '
                . 'data-item-index="' . $idx . '">';

            // Statement cell
            $html .= '<td class="corex-disclosure-statement">'
                . e($item['statement']);

            // Conditional date field
            if ($item['has_conditional_date'] ?? false) {
                $html .= '<br><span class="corex-disclosure-date-field" '
                    . 'data-conditional="yes">'
                    . '<span class="text-xs text-gray-400">'
                    . 'Date: ________________</span></span>';
            }

            $html .= '</td>';

            // YES/NO/N/A cells
            $html .= '<td class="corex-disclosure-option">'
                . '<span class="corex-radio-placeholder" '
                . 'data-item="' . $idx . '" data-value="yes">'
                . '&#9675;</span></td>';

            $html .= '<td class="corex-disclosure-option">'
                . '<span class="corex-radio-placeholder" '
                . 'data-item="' . $idx . '" data-value="no">'
                . '&#9675;</span></td>';

            if ($hasNa) {
                $html .= '<td class="corex-disclosure-option">'
                    . '<span class="corex-radio-placeholder" '
                    . 'data-item="' . $idx . '" data-value="na">'
                    . '&#9675;</span></td>';
            }

            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private function contentToText(array $content): string
    {
        return collect($content)
            ->filter(fn($c) => ($c['type'] ?? '') === 'text')
            ->pluck('value')
            ->join('');
    }
}
