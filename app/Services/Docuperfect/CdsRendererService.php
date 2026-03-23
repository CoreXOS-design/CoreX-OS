<?php

namespace App\Services\Docuperfect;

class CdsRendererService
{
    /**
     * Render CDS JSON to HTML using CoreX document classes.
     */
    public function render(array $cds): string
    {
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
            'company_header' => $this->renderTable($section), // Render as table — no separate @include needed
            'signature_section' => $this->renderSignatureSection($section),
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

        return "<div class=\"{$class}\">{$number}{$text}</div>\n";
    }

    private function renderClause(array $section): string
    {
        $level = $section['level'] ?? 1;
        $indent = min($level, 3);
        $number = $section['number'] ?? '';
        $content = $this->renderContent($section['content'] ?? []);

        return "<div class=\"corex-clause corex-clause-indent-{$indent}\">"
            . "<span class=\"corex-clause-number\">{$number}</span> "
            . "<span class=\"corex-clause-text\">{$content}</span>"
            . "</div>\n";
    }

    private function renderParagraph(array $section): string
    {
        $content = $this->renderContent($section['content'] ?? []);
        return "<div class=\"corex-clause corex-clause-indent-1\">"
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
        $html = '<table class="corex-table">';

        if (!empty($section['headers'])) {
            $html .= '<thead><tr>';
            foreach ($section['headers'] as $header) {
                $html .= '<th>' . e($header) . '</th>';
            }
            $html .= '</tr></thead>';
        }

        $html .= '<tbody>';
        foreach ($section['rows'] ?? [] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . e($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
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
                $html .= '<span class="corex-field" '
                    . 'data-marker-type="signature" '
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

        $html .= '<table class="corex-disclosure-table">';
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
