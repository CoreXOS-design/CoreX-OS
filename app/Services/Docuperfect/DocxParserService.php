<?php

namespace App\Services\Docuperfect;

use ZipArchive;

class DocxParserService
{
    /**
     * Auto-label rules: context pattern => [suggested_label, suggested_key, pillar, assigned_to]
     * Order matters — first match wins.
     */
    protected array $labelRules = [
        // Contact — Lessor/Landlord
        ['pattern' => '/\b(owner|lessor|landlord)\b.*\b(surname|last\s*name)\b/i', 'label' => 'Lessor Surname', 'key' => 'contact.lessor_surname', 'pillar' => 'contact', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\b(owner|lessor|landlord)\b.*\bname\b/i', 'label' => 'Lessor Name', 'key' => 'contact.lessor_name', 'pillar' => 'contact', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\b(lessee|tenant|occupant)\b.*\b(surname|last\s*name)\b/i', 'label' => 'Lessee Surname', 'key' => 'contact.lessee_surname', 'pillar' => 'contact', 'party' => 'lessee', 'confidence' => 'high'],
        ['pattern' => '/\b(lessee|tenant|occupant)\b.*\bname\b/i', 'label' => 'Lessee Name', 'key' => 'contact.lessee_name', 'pillar' => 'contact', 'party' => 'lessee', 'confidence' => 'high'],

        // ID / Passport
        ['pattern' => '/\b(id|identity|passport|registration)\s*(no|number)\b/i', 'label' => 'ID Number', 'key' => 'contact.id_number', 'pillar' => 'contact', 'party' => 'lessor', 'confidence' => 'high'],

        // Contact details
        ['pattern' => '/\b(telephone|cell|phone|tel)\b/i', 'label' => 'Telephone', 'key' => 'contact.telephone', 'pillar' => 'contact', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\bemail\b/i', 'label' => 'Email', 'key' => 'contact.email', 'pillar' => 'contact', 'party' => 'lessor', 'confidence' => 'high'],

        // Property
        ['pattern' => '/\b(property\s*(known\s*as|described|situated)|premises)\b/i', 'label' => 'Property Address', 'key' => 'property.address', 'pillar' => 'property', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\b(erf|stand)\s*(no|number)?\b/i', 'label' => 'Erf Number', 'key' => 'property.erf_number', 'pillar' => 'property', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\bunit\s*(no|number)?\b/i', 'label' => 'Unit Number', 'key' => 'property.unit_number', 'pillar' => 'property', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\bcomplex\b/i', 'label' => 'Complex Name', 'key' => 'property.complex_name', 'pillar' => 'property', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\bsuburb\b/i', 'label' => 'Suburb', 'key' => 'property.suburb', 'pillar' => 'property', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\baddress\b/i', 'label' => 'Address', 'key' => 'property.address', 'pillar' => 'property', 'party' => 'agent', 'confidence' => 'medium'],

        // Deal — financial
        ['pattern' => '/\b(rental|monthly\s*rental|rent)\b/i', 'label' => 'Monthly Rental', 'key' => 'deal.monthly_rental', 'pillar' => 'deal', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\bdeposit\b/i', 'label' => 'Deposit', 'key' => 'deal.deposit', 'pillar' => 'deal', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\bcommission\b/i', 'label' => 'Commission', 'key' => 'deal.commission', 'pillar' => 'deal', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\bvat\b/i', 'label' => 'VAT Amount', 'key' => 'deal.vat_amount', 'pillar' => 'deal', 'party' => 'agent', 'confidence' => 'high'],

        // Deal — dates
        ['pattern' => '/\b(commence|start\s*date|commencement)\b/i', 'label' => 'Lease Start Date', 'key' => 'deal.lease_start', 'pillar' => 'deal', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\b(expir|end\s*date|termination)\b/i', 'label' => 'Lease End Date', 'key' => 'deal.lease_end', 'pillar' => 'deal', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\bday\s*of\b/i', 'label' => 'Signed Day', 'key' => 'deal.signed_day', 'pillar' => 'deal', 'party' => 'agent', 'confidence' => 'high'],
        ['pattern' => '/\bdate\b/i', 'label' => 'Date', 'key' => 'deal.date', 'pillar' => 'deal', 'party' => 'agent', 'confidence' => 'medium'],

        // Banking
        ['pattern' => '/\baccount\s*holder\b/i', 'label' => 'Account Holder', 'key' => 'deal.account_holder', 'pillar' => 'deal', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\bbank\s*name\b/i', 'label' => 'Bank Name', 'key' => 'deal.bank_name', 'pillar' => 'deal', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\baccount\s*(no|number)\b/i', 'label' => 'Account Number', 'key' => 'deal.account_number', 'pillar' => 'deal', 'party' => 'lessor', 'confidence' => 'high'],
        ['pattern' => '/\bbranch\s*code\b/i', 'label' => 'Branch Code', 'key' => 'deal.branch_code', 'pillar' => 'deal', 'party' => 'lessor', 'confidence' => 'high'],

        // Agent
        ['pattern' => '/\bagent\b/i', 'label' => 'Agent Name', 'key' => 'agent.agent_name', 'pillar' => 'agent', 'party' => 'agent', 'confidence' => 'high'],
    ];

    /**
     * Parse a .docx file and return structured data.
     */
    public function parse(string $filePath): array
    {
        \Log::info('DocxParser: opening ZIP');
        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Unable to open .docx file as ZIP archive.');
        }

        \Log::info('DocxParser: ZIP opened, reading XML');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('Could not find word/document.xml in the .docx file.');
        }

        \Log::info('DocxParser: XML length ' . strlen($xml));
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        \Log::info('DocxParser: DOM loaded, extracting paragraphs');
        $paragraphs = $this->extractParagraphs($dom);
        \Log::info('DocxParser: paragraphs extracted ' . count($paragraphs));
        $html = $this->buildHtml($paragraphs);
        \Log::info('DocxParser: HTML built');
        $rawText = $this->buildPlainText($paragraphs);
        \Log::info('DocxParser: plain text built');
        $fields = $this->detectFields($paragraphs);
        \Log::info('DocxParser: fields detected ' . count($fields));

        return [
            'html' => $html,
            'fields' => $fields,
            'raw_text' => $rawText,
        ];
    }

    /**
     * Extract paragraphs with their runs from the XML DOM.
     */
    protected function extractParagraphs(\DOMDocument $dom): array
    {
        $paragraphs = [];
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $pNodes = $xpath->query('//w:p');

        foreach ($pNodes as $pNode) {
            $runs = [];
            $alignment = 'left';

            // Check paragraph alignment
            $jcNodes = $xpath->query('w:pPr/w:jc', $pNode);
            if ($jcNodes->length > 0) {
                $alignment = $jcNodes->item(0)->getAttribute('w:val') ?: 'left';
            }

            // Get font size from paragraph properties
            $pFontSize = null;
            $pSzNodes = $xpath->query('w:pPr/w:rPr/w:sz', $pNode);
            if ($pSzNodes->length > 0) {
                $halfPt = (int) $pSzNodes->item(0)->getAttribute('w:val');
                if ($halfPt > 0) {
                    $pFontSize = $halfPt / 2; // Convert half-points to points
                }
            }

            $rNodes = $xpath->query('w:r', $pNode);

            foreach ($rNodes as $rNode) {
                $text = '';
                $tNodes = $xpath->query('w:t', $rNode);
                foreach ($tNodes as $tNode) {
                    $text .= $tNode->textContent;
                }

                // Check bold
                $isBold = false;
                $bNodes = $xpath->query('w:rPr/w:b', $rNode);
                if ($bNodes->length > 0) {
                    $val = $bNodes->item(0)->getAttribute('w:val');
                    $isBold = ($val === '' || $val === '1' || $val === 'true');
                }

                // Check italic
                $isItalic = false;
                $iNodes = $xpath->query('w:rPr/w:i', $rNode);
                if ($iNodes->length > 0) {
                    $val = $iNodes->item(0)->getAttribute('w:val');
                    $isItalic = ($val === '' || $val === '1' || $val === 'true');
                }

                // Check underline
                $isUnderline = false;
                $uNodes = $xpath->query('w:rPr/w:u', $rNode);
                if ($uNodes->length > 0) {
                    $val = $uNodes->item(0)->getAttribute('w:val');
                    $isUnderline = ($val !== '' && $val !== 'none');
                }

                // Font size per run
                $fontSize = $pFontSize;
                $szNodes = $xpath->query('w:rPr/w:sz', $rNode);
                if ($szNodes->length > 0) {
                    $halfPt = (int) $szNodes->item(0)->getAttribute('w:val');
                    if ($halfPt > 0) {
                        $fontSize = $halfPt / 2;
                    }
                }

                // Font family per run
                $fontFamily = null;
                $fontNodes = $xpath->query('w:rPr/w:rFonts', $rNode);
                if ($fontNodes->length > 0) {
                    $fontFamily = $fontNodes->item(0)->getAttribute('w:ascii')
                        ?: $fontNodes->item(0)->getAttribute('w:hAnsi');
                }

                if ($text !== '') {
                    $runs[] = [
                        'text' => $text,
                        'bold' => $isBold,
                        'italic' => $isItalic,
                        'underline' => $isUnderline,
                        'fontSize' => $fontSize,
                        'fontFamily' => $fontFamily,
                    ];
                }
            }

            $paragraphs[] = [
                'runs' => $runs,
                'alignment' => $alignment,
            ];
        }

        return $paragraphs;
    }

    /**
     * Build HTML from parsed paragraphs.
     */
    protected function buildHtml(array $paragraphs): string
    {
        $html = '';

        foreach ($paragraphs as $para) {
            $style = '';
            if ($para['alignment'] !== 'left') {
                $align = $para['alignment'] === 'both' ? 'justify' : $para['alignment'];
                $style .= "text-align:{$align};";
            }

            $pAttr = $style ? " style=\"{$style}\"" : '';
            $inner = '';

            foreach ($para['runs'] as $run) {
                $text = htmlspecialchars($run['text'], ENT_QUOTES, 'UTF-8');

                // Check if this is a field blank (3+ underscores)
                if (preg_match('/^_{3,}$/', trim($run['text']))) {
                    $text = '<span class="field-blank" data-raw="' . htmlspecialchars($run['text'], ENT_QUOTES) . '">' . $text . '</span>';
                } else {
                    $runStyle = '';
                    if ($run['fontSize']) {
                        $runStyle .= "font-size:{$run['fontSize']}pt;";
                    }
                    if ($run['fontFamily']) {
                        $runStyle .= "font-family:'{$run['fontFamily']}',sans-serif;";
                    }

                    $spanAttr = $runStyle ? " style=\"{$runStyle}\"" : '';

                    if ($run['bold']) {
                        $text = "<strong>{$text}</strong>";
                    }
                    if ($run['italic']) {
                        $text = "<em>{$text}</em>";
                    }
                    if ($run['underline']) {
                        $text = "<u>{$text}</u>";
                    }
                    if ($spanAttr) {
                        $text = "<span{$spanAttr}>{$text}</span>";
                    }
                }

                $inner .= $text;
            }

            // Skip completely empty paragraphs (but keep ones with just a space for spacing)
            if ($inner === '' && count($para['runs']) === 0) {
                $html .= "<p{$pAttr}>&nbsp;</p>\n";
            } else {
                $html .= "<p{$pAttr}>{$inner}</p>\n";
            }
        }

        return $html;
    }

    /**
     * Build plain text from paragraphs.
     */
    protected function buildPlainText(array $paragraphs): string
    {
        $lines = [];
        foreach ($paragraphs as $para) {
            $line = '';
            foreach ($para['runs'] as $run) {
                $line .= $run['text'];
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Detect field blanks from paragraphs and auto-label them.
     */
    protected function detectFields(array $paragraphs): array
    {
        $fields = [];
        $position = 0;
        $customIndex = 1;

        foreach ($paragraphs as $para) {
            $lineText = '';
            foreach ($para['runs'] as $run) {
                $lineText .= $run['text'];
            }

            // Track character offset within the full document
            $runOffset = $position;

            foreach ($para['runs'] as $run) {
                $text = $run['text'];
                $isBlank = preg_match('/_{3,}/', $text);

                if ($isBlank) {
                    // Gather context: 50 chars before and after from the line
                    $beforeText = mb_substr($lineText, 0, max(0, mb_strpos($lineText, $text)));
                    $contextBefore = mb_substr($beforeText, -50);
                    $afterPos = mb_strpos($lineText, $text) + mb_strlen($text);
                    $contextAfter = mb_substr($lineText, $afterPos, 50);
                    $context = trim($contextBefore . ' [___] ' . $contextAfter);

                    // Auto-label
                    $match = $this->autoLabel($contextBefore, $contextAfter);

                    $fields[] = [
                        'raw' => $text,
                        'context' => $context,
                        'suggested_label' => $match['label'],
                        'suggested_key' => $match['key'],
                        'pillar' => $match['pillar'],
                        'assigned_to' => $match['party'],
                        'confidence' => $match['confidence'],
                        'position' => $runOffset,
                    ];

                    if ($match['pillar'] === 'custom') {
                        $customIndex++;
                    }
                }

                $runOffset += mb_strlen($text);
            }

            $position += mb_strlen($lineText) + 1; // +1 for newline
        }

        // Deduplicate keys — append numeric suffix for repeated keys
        $keyCounts = [];
        foreach ($fields as &$field) {
            $key = $field['suggested_key'];
            if (!isset($keyCounts[$key])) {
                $keyCounts[$key] = 0;
            }
            $keyCounts[$key]++;
            if ($keyCounts[$key] > 1) {
                $field['suggested_key'] = $key . '_' . $keyCounts[$key];
                $field['suggested_label'] = $field['suggested_label'] . ' ' . $keyCounts[$key];
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * Auto-label a field based on surrounding context.
     */
    protected function autoLabel(string $contextBefore, string $contextAfter): array
    {
        $fullContext = $contextBefore . ' ' . $contextAfter;

        foreach ($this->labelRules as $rule) {
            if (preg_match($rule['pattern'], $fullContext)) {
                return [
                    'label' => $rule['label'],
                    'key' => $rule['key'],
                    'pillar' => $rule['pillar'],
                    'party' => $rule['party'],
                    'confidence' => $rule['confidence'],
                ];
            }
        }

        // No match — custom field
        static $customCounter = 0;
        $customCounter++;

        return [
            'label' => 'Custom Field ' . $customCounter,
            'key' => 'custom.field_' . $customCounter,
            'pillar' => 'custom',
            'party' => 'agent',
            'confidence' => 'low',
        ];
    }
}
