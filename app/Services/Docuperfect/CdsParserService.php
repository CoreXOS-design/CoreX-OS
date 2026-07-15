<?php

namespace App\Services\Docuperfect;

use ZipArchive;
use DOMDocument;
use DOMXPath;

class CdsParserService
{
    /**
     * AT-262-marker — the ONE TRUTH for marker syntax.
     *
     * This list is the only place the accepted markers are defined. `detectMarkers()`
     * COMPOSES its split regex from it, and the import screen RENDERS its guide from
     * it. Neither can drift from the other, because there is nothing to drift from —
     * they read the same array.
     *
     * The ticket suspected the UI taught a syntax the parser did not read. It does
     * not (verified across every view, service and spec: the only marker guide in the
     * product is the import screen, and it already matched the regex exactly). But the
     * two WERE independently maintained — a hardcoded Blade list next to a hardcoded
     * regex — which is the same two-copies-of-one-fact shape that has bitten us
     * elsewhere. Structure it once so the drift is impossible rather than merely
     * absent today.
     *
     * `order` matters: the tilde form is matched FIRST because it is the longest and
     * must not be shredded by the shorter alternations.
     */
    public const ACCEPTED_MARKERS = [
        [
            'token'   => '~~~~NAME~~~~',
            'delim'   => '~~~~',   // what the import guide must show for this family
            // AT-262-charset — the NAME between the tildes may be any human-natural
            // field name: letters, digits, spaces, and - / ( ) & ' . , : _
            // (everything except the ~ delimiter). The old class was [A-Z_]+, which
            // rejected the way agents actually name fields — "Seller - Full name",
            // "Property - Erf / Scheme", "Asking price (Rand)" — at the FIRST lowercase
            // letter. Teach the real syntax; don't force SHOUTING_SNAKE_CASE.
            'pattern' => '~{4,}[A-Za-z0-9 _\/()&\'.,:-]+~{4,}',
            'label'   => 'Insertable block',
            'hint'    => 'A live, agent-editable area named however you like — e.g. ~~~~Seller - Full name~~~~, ~~~~Property - Erf / Scheme~~~~, ~~~~Asking price (Rand)~~~~, or the built-in ~~~~OTHER_CONDITIONS~~~~',
            'example' => '~~~~Seller - Full name~~~~',
        ],
        [
            'token'   => '@@@@',
            'delim'   => '@@@@',
            'pattern' => '@{4,}',
            'label'   => 'Input field',
            'hint'    => 'Where data is filled in (four or more @)',
            'example' => '@@@@',
        ],
        [
            'token'   => '%%%%',
            'delim'   => '%%%%',
            'pattern' => '%{4,}',
            'label'   => 'Signature',
            'hint'    => 'Where a party signs (four or more %)',
            'example' => '%%%%',
        ],
        [
            'token'   => '####',
            'delim'   => '####',
            'pattern' => '#{4,}',
            'label'   => 'Initial',
            'hint'    => 'Where a party initials (four or more #)',
            'example' => '####',
        ],
    ];

    /**
     * Human-readable description of the characters an insertable-block NAME may
     * contain. ONE truth — the on-screen teaching and the near-miss guard both read
     * this, so what we accept and what we tell the agent can never drift.
     */
    public const INSERTABLE_NAME_HUMAN = "letters, digits, spaces, and - / ( ) & ' . , : _";

    /** The accepted markers, for any surface that needs to TEACH them. */
    public static function acceptedMarkers(): array
    {
        return self::ACCEPTED_MARKERS;
    }

    /** The split regex, composed from the one truth above — never hand-maintained. */
    public static function markerSplitPattern(): string
    {
        return '/(' . implode('|', array_column(self::ACCEPTED_MARKERS, 'pattern')) . ')/';
    }

    /**
     * Parse a .docx file into CoreX Document Structure JSON.
     *
     * @param string $filePath Path to the .docx file
     * @return array The CDS structure
     */
    public function parse(string $filePath): array
    {
        // 1. Open the .docx as a ZIP
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException("Cannot open .docx file: {$filePath}");
        }

        // 2. Extract the key XML files
        $documentXml = $zip->getFromName('word/document.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $numberingXml = $zip->getFromName('word/numbering.xml');
        $zip->close();

        if (!$documentXml) {
            throw new \RuntimeException("Invalid .docx — no word/document.xml found");
        }

        // 3. Parse styles to build a style map (styleId → heading level, etc)
        $styleMap = $this->parseStyles($stylesXml);

        // 4. Parse numbering definitions
        $numberingMap = $this->parseNumbering($numberingXml);

        // 5. Parse the document body into CDS sections
        $sections = $this->parseDocument($documentXml, $styleMap, $numberingMap);

        // 6. Extract original plain text for validation comparison
        $plainText = $this->extractFullPlainText($sections);

        // 7. Return the CDS structure
        return [
            'version' => '1.0',
            'title' => $this->detectTitle($sections),
            'extracted_at' => now()->toIso8601String(),
            'original_text' => $plainText,
            'sections' => $sections,
        ];
    }

    /**
     * Parse styles.xml to map style IDs to their types.
     * We care about: which styles are headings, and what level.
     */
    private function parseStyles(?string $xml): array
    {
        $map = [];
        if (!$xml) return $map;

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Find all w:style elements
        $styles = $xpath->query('//w:style');
        foreach ($styles as $style) {
            $styleId = $style->getAttribute('w:styleId');
            $type = $style->getAttribute('w:type');

            // Check for heading styles
            $nameNode = $xpath->query('w:name', $style)->item(0);
            $name = $nameNode ? $nameNode->getAttribute('w:val') : '';

            // Detect heading level from style name
            if (preg_match('/^heading\s*(\d)/i', $name, $m)) {
                $map[$styleId] = [
                    'type' => 'heading',
                    'level' => (int) $m[1],
                    'name' => $name,
                ];
            } elseif (stripos($name, 'Title') === 0) {
                $map[$styleId] = [
                    'type' => 'title',
                    'name' => $name,
                ];
            } elseif (stripos($name, 'List') !== false) {
                $map[$styleId] = [
                    'type' => 'list',
                    'name' => $name,
                ];
            } else {
                $map[$styleId] = [
                    'type' => 'paragraph',
                    'name' => $name,
                ];
            }
        }

        return $map;
    }

    /**
     * Parse numbering.xml for list/clause numbering definitions.
     */
    private function parseNumbering(?string $xml): array
    {
        $map = [];
        if (!$xml) return $map;

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Map abstractNumId → level formats
        $abstractNums = $xpath->query('//w:abstractNum');
        foreach ($abstractNums as $abstractNum) {
            $id = $abstractNum->getAttribute('w:abstractNumId');
            $levels = [];
            $lvlNodes = $xpath->query('w:lvl', $abstractNum);
            foreach ($lvlNodes as $lvl) {
                $ilvl = $lvl->getAttribute('w:ilvl');
                $numFmt = $xpath->query('w:numFmt', $lvl)->item(0);
                $lvlText = $xpath->query('w:lvlText', $lvl)->item(0);
                $startNode = $xpath->query('w:start', $lvl)->item(0);
                $levels[(int)$ilvl] = [
                    'format' => $numFmt ? $numFmt->getAttribute('w:val') : 'decimal',
                    'text' => $lvlText ? $lvlText->getAttribute('w:val') : '%1.',
                    'start' => $startNode ? (int) $startNode->getAttribute('w:val') : 1,
                ];
            }
            $map['abstract'][$id] = $levels;
        }

        // Map numId → abstractNumId
        $nums = $xpath->query('//w:num');
        foreach ($nums as $num) {
            $numId = $num->getAttribute('w:numId');
            $abstractRef = $xpath->query('w:abstractNumId', $num)->item(0);
            if ($abstractRef) {
                $map['num'][$numId] = $abstractRef->getAttribute('w:val');
            }
        }

        return $map;
    }

    /**
     * Parse the main document body into CDS sections.
     */
    private function parseDocument(string $xml, array $styleMap, array $numberingMap): array
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $sections = [];
        $body = $xpath->query('//w:body')->item(0);
        if (!$body) return $sections;

        foreach ($body->childNodes as $node) {
            if ($node->nodeName === 'w:p') {
                $section = $this->parseParagraph($node, $xpath, $styleMap, $numberingMap);
                if ($section) {
                    $sections[] = $section;
                }
            } elseif ($node->nodeName === 'w:tbl') {
                $sections[] = $this->parseTable($node, $xpath);
            }
        }

        // Post-process in order:
        $sections = $this->resolveNumbering($sections, $numberingMap);
        $sections = $this->detectClauseNumbering($sections);
        $sections = $this->detectCapsHeadings($sections);
        $sections = $this->detectBoldHeadings($sections);
        $sections = $this->detectCompanyHeader($sections);
        $sections = $this->detectDisclosureTables($sections);

        // Legacy: replaced by marker-based detection
        // $sections = $this->detectFieldPlaceholders($sections);
        // $sections = $this->labelFieldPlaceholders($sections);
        // $sections = $this->detectLabelValuePairs($sections);
        // $sections = $this->detectInlineSignatures($sections);
        // $sections = $this->insertPageInitials($sections);

        // Marker-based field detection (NEW — @@@@ / %%%% / ####)
        $sections = $this->detectMarkers($sections);
        $sections = $this->identifyFieldsFromContext($sections);

        // Structural detection — signature sections stay (strips raw sig text at end of doc)
        $sections = $this->detectSignatureSections($sections);

        return $sections;
    }

    /**
     * Parse a single paragraph (w:p) element.
     */
    private function parseParagraph($node, DOMXPath $xpath, array $styleMap, array $numberingMap): ?array
    {
        // Get paragraph style
        $pStyle = $xpath->query('w:pPr/w:pStyle', $node)->item(0);
        $styleId = $pStyle ? $pStyle->getAttribute('w:val') : null;
        $styleInfo = $styleId ? ($styleMap[$styleId] ?? null) : null;

        // Get numbering info
        $numPr = $xpath->query('w:pPr/w:numPr', $node)->item(0);
        $numId = null;
        $numLevel = 0;
        if ($numPr) {
            $numIdNode = $xpath->query('w:numId', $numPr)->item(0);
            $ilvlNode = $xpath->query('w:ilvl', $numPr)->item(0);
            $numId = $numIdNode ? $numIdNode->getAttribute('w:val') : null;
            $numLevel = $ilvlNode ? (int) $ilvlNode->getAttribute('w:val') : 0;
        }

        // Extract text runs
        $content = $this->extractRuns($node, $xpath);

        // Skip empty paragraphs
        $plainText = $this->contentToPlainText($content);
        if (trim($plainText) === '') {
            return null;
        }

        // Determine section type
        if ($styleInfo && $styleInfo['type'] === 'heading') {
            return [
                'type' => 'heading',
                'level' => $styleInfo['level'],
                'text' => $plainText,
                'content' => $content,
            ];
        }

        if ($styleInfo && $styleInfo['type'] === 'title') {
            return [
                'type' => 'title',
                'text' => $plainText,
                'content' => $content,
            ];
        }

        // Regular paragraph (may be a numbered clause — detected in post-processing)
        $result = [
            'type' => 'paragraph',
            'content' => $content,
        ];

        if ($numId) {
            $result['numbering'] = [
                'numId' => $numId,
                'level' => $numLevel,
            ];
        }

        if ($styleId) {
            $result['styleId'] = $styleId;
        }

        return $result;
    }

    /**
     * Extract text runs (w:r) from a paragraph, preserving formatting.
     */
    private function extractRuns($paragraph, DOMXPath $xpath): array
    {
        $content = [];
        $runs = $xpath->query('w:r', $paragraph);

        foreach ($runs as $run) {
            $text = '';
            $textNodes = $xpath->query('w:t', $run);
            foreach ($textNodes as $t) {
                $text .= $t->textContent;
            }

            // Also check for tab, break, etc.
            $tabs = $xpath->query('w:tab', $run);
            if ($tabs->length > 0) {
                $text .= "\t";
            }

            if ($text === '') continue;

            // Get run formatting
            $rPr = $xpath->query('w:rPr', $run)->item(0);
            $bold = $rPr && $xpath->query('w:b', $rPr)->length > 0;
            $italic = $rPr && $xpath->query('w:i', $rPr)->length > 0;
            $underline = $rPr && $xpath->query('w:u', $rPr)->length > 0;

            $item = ['type' => 'text', 'value' => $text];

            if ($bold) $item['bold'] = true;
            if ($italic) $item['italic'] = true;
            if ($underline) $item['underline'] = true;

            $content[] = $item;
        }

        return $content;
    }

    /**
     * Parse a table (w:tbl) element.
     */
    private function parseTable($tableNode, DOMXPath $xpath): array
    {
        $rows = [];
        $rowNodes = $xpath->query('w:tr', $tableNode);

        foreach ($rowNodes as $rowNode) {
            $cells = [];
            $cellNodes = $xpath->query('w:tc', $rowNode);

            foreach ($cellNodes as $cellNode) {
                // Get all paragraph text from the cell
                $cellText = '';
                $cellParagraphs = $xpath->query('w:p', $cellNode);
                foreach ($cellParagraphs as $cp) {
                    $runs = $this->extractRuns($cp, $xpath);
                    $cellText .= $this->contentToPlainText($runs);
                }
                $cells[] = trim($cellText);
            }

            $rows[] = $cells;
        }

        // Detect if first row is a header
        $hasHeader = false;
        if (count($rows) > 1) {
            // Simple heuristic: if first row has different formatting
            // or all cells are short/label-like, treat as header
            $firstRow = $rows[0] ?? [];
            $allShort = collect($firstRow)->every(fn($c) => mb_strlen($c) < 50);
            $hasHeader = $allShort;
        }

        $result = [
            'type' => 'table',
            'rows' => $rows,
        ];

        if ($hasHeader && count($rows) > 0) {
            $result['headers'] = array_shift($result['rows']);
        }

        return $result;
    }

    /**
     * Resolve Word automatic numbering (numId + ilvl) into clause numbers.
     * Word stores numbered lists via numbering.xml definitions, not as literal text.
     */
    private function resolveNumbering(array $sections, array $numberingMap): array
    {
        // Track current number counters: [numId][level] => current count
        $numberingState = [];

        foreach ($sections as &$section) {
            if ($section['type'] !== 'paragraph' || !isset($section['numbering'])) {
                continue;
            }

            $numId = $section['numbering']['numId'];
            $ilvl = $section['numbering']['level'];

            // Skip numId 0 — Word uses this for "no numbering"
            if ($numId === '0' || $numId === null) {
                continue;
            }

            // Look up the abstract numbering definition
            $abstractId = $numberingMap['num'][$numId] ?? null;
            $levelDef = null;
            if ($abstractId !== null) {
                $levelDef = $numberingMap['abstract'][$abstractId][$ilvl] ?? null;
            }

            // Initialize counter for this numId if needed
            if (!isset($numberingState[$numId])) {
                $numberingState[$numId] = [];
            }

            // Initialize this level if needed (use start value from definition)
            $startVal = $levelDef['start'] ?? 1;
            if (!isset($numberingState[$numId][$ilvl])) {
                $numberingState[$numId][$ilvl] = $startVal;
            } else {
                $numberingState[$numId][$ilvl]++;
            }

            // Reset all deeper levels when a higher level increments
            foreach ($numberingState[$numId] as $lvl => $val) {
                if ($lvl > $ilvl) {
                    unset($numberingState[$numId][$lvl]);
                }
            }

            // Build the resolved number string
            $resolvedNumber = $this->formatNumbering(
                $numberingState[$numId],
                $ilvl,
                $numberingMap,
                $abstractId
            );

            // Mark as clause
            $section['type'] = 'clause';
            $section['number'] = $resolvedNumber;
            $section['level'] = $ilvl + 1;
        }

        return $sections;
    }

    /**
     * Format a numbering value based on the numbering definition pattern.
     */
    private function formatNumbering(array $counters, int $currentLevel, array $numberingMap, ?string $abstractId): string
    {
        if ($abstractId === null) {
            // Fallback: just use the counter value
            return (string) ($counters[$currentLevel] ?? 1);
        }

        $levelDef = $numberingMap['abstract'][$abstractId][$currentLevel] ?? null;
        $pattern = $levelDef['text'] ?? '%1.';

        // Replace %1, %2, %3 etc. with the counter values for each level
        $result = $pattern;
        for ($lvl = 0; $lvl <= $currentLevel; $lvl++) {
            $val = $counters[$lvl] ?? 1;
            $format = $numberingMap['abstract'][$abstractId][$lvl]['format'] ?? 'decimal';
            $formatted = $this->formatNumber($val, $format);
            $result = str_replace('%' . ($lvl + 1), $formatted, $result);
        }

        // Clean up trailing period if present (we add it in the renderer)
        $result = rtrim($result, '.');

        return $result;
    }

    /**
     * Format a number according to the Word numFmt type.
     */
    private function formatNumber(int $value, string $format): string
    {
        return match ($format) {
            'decimal' => (string) $value,
            'lowerLetter' => chr(96 + min($value, 26)),
            'upperLetter' => chr(64 + min($value, 26)),
            'lowerRoman' => $this->toRoman($value, true),
            'upperRoman' => $this->toRoman($value, false),
            'bullet' => '•',
            default => (string) $value,
        };
    }

    /**
     * Convert integer to Roman numeral.
     */
    private function toRoman(int $num, bool $lower): string
    {
        $map = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $result = '';
        foreach ($map as $val => $char) {
            while ($num >= $val) {
                $result .= $char;
                $num -= $val;
            }
        }
        return $lower ? strtolower($result) : $result;
    }

    /**
     * Post-process: detect numbered clauses from text patterns.
     * Matches: "1.", "1.1", "1.1.1", "12.3.4" etc at start of text.
     */
    private function detectClauseNumbering(array $sections): array
    {
        foreach ($sections as &$section) {
            if ($section['type'] !== 'paragraph') continue;

            $text = $this->contentToPlainText($section['content']);

            // Match clause numbers: 1. or 1.1 or 1.1.1 etc.
            if (preg_match('/^(\d+(?:\.\d+)*)\s+(.*)$/s', trim($text), $m)) {
                $number = $m[1];
                $dots = substr_count($number, '.');

                $section['type'] = 'clause';
                $section['number'] = $number;
                $section['level'] = $dots + 1; // 1. = level 1, 1.1 = level 2

                // Remove the number from the content
                // Find and strip the number from the first text element
                if (!empty($section['content'])) {
                    $first = &$section['content'][0];
                    if ($first['type'] === 'text') {
                        $first['value'] = preg_replace(
                            '/^\d+(?:\.\d+)*\s+/',
                            '',
                            $first['value']
                        );
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * Post-process: detect ALL CAPS paragraphs as section headings.
     */
    private function detectCapsHeadings(array $sections): array
    {
        foreach ($sections as &$section) {
            if ($section['type'] === 'clause' && $section['level'] === 1) {
                $text = $this->contentToPlainText($section['content']);
                // If the clause text (after number) is ALL CAPS, it's a heading
                $stripped = preg_replace('/[^a-zA-Z]/', '', $text);
                if ($stripped !== '' && $stripped === strtoupper($stripped) && strlen($stripped) > 2) {
                    $section['type'] = 'heading';
                    $section['level'] = 1;
                    $section['text'] = $this->contentToPlainText($section['content']);
                }
            }

            // Also check regular paragraphs that are ALL CAPS
            if ($section['type'] === 'paragraph') {
                $text = $this->contentToPlainText($section['content']);
                $stripped = preg_replace('/[^a-zA-Z]/', '', $text);
                if ($stripped !== '' && $stripped === strtoupper($stripped) && strlen($stripped) > 2) {
                    // Check if it looks like a heading (short, no period at end)
                    if (strlen(trim($text)) < 80 && !str_ends_with(trim($text), '.')) {
                        $section['type'] = 'heading';
                        $section['level'] = 1;
                        $section['text'] = trim($text);
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * Post-process: detect bold paragraphs as headings.
     * A paragraph is a heading if all runs are bold, text is short,
     * doesn't end with a period, and has no numbering.
     */
    private function detectBoldHeadings(array $sections): array
    {
        foreach ($sections as &$section) {
            if ($section['type'] !== 'paragraph') continue;

            $content = $section['content'] ?? [];
            if (empty($content)) continue;

            // Check if ALL runs are bold
            $textRuns = collect($content)->where('type', 'text');
            if ($textRuns->isEmpty()) continue;

            $allBold = $textRuns->every(fn($c) => !empty($c['bold']));

            if (!$allBold) continue;

            $text = $this->contentToPlainText($content);

            // Short, no period at end, no numbering
            if (strlen(trim($text)) < 60
                && !str_ends_with(trim($text), '.')
                && !isset($section['number'])) {

                $section['type'] = 'heading';
                $section['level'] = 2; // Bold headings = level 2
                $section['text'] = trim($text);
            }
        }

        return $sections;
    }

    /**
     * Post-process: detect company header table.
     * If the first section is a table containing agency info patterns,
     * mark it as company_header so the renderer can skip it.
     */
    private function detectCompanyHeader(array $sections): array
    {
        if (empty($sections)) return $sections;

        // Check the first section (and possibly second if first is not a table)
        foreach (array_slice($sections, 0, 2, true) as $idx => $section) {
            if ($section['type'] !== 'table') continue;

            // Flatten all cell text to check for company header patterns
            $allText = '';
            foreach ($section['rows'] ?? [] as $row) {
                $allText .= implode(' ', $row) . ' ';
            }
            if (!empty($section['headers'])) {
                $allText .= implode(' ', $section['headers']) . ' ';
            }

            $allTextLower = strtolower($allText);

            // Check for patterns that indicate a company/agency header
            $patterns = ['reg no', 'ffc', 'email address', 'vat no', 'tel:', 'fax:', 'registration'];
            $matchCount = 0;
            foreach ($patterns as $pattern) {
                if (str_contains($allTextLower, $pattern)) {
                    $matchCount++;
                }
            }

            // If 2+ patterns match, it's a company header
            if ($matchCount >= 2) {
                $sections[$idx]['type'] = 'company_header';
                break;
            }
        }

        return $sections;
    }

    /**
     * Marker-based field detection.
     * Users place @@@@ (input), %%%% (signature), #### (initial) markers
     * Post-process: detect YES/NO/N/A disclosure tables.
     * Documents like the Mandatory Disclosure (V7) have tables with
     * YES/NO/N/A checkboxes per row. This converts them to structured
     * disclosure_checklist sections.
     */
    private function detectDisclosureTables(array $sections): array
    {
        foreach ($sections as &$section) {
            if (($section['type'] ?? '') !== 'table') continue;

            $rows = $section['rows'] ?? [];
            if (count($rows) < 3) continue;

            // Check if header row has YES/NO/N/A pattern
            $header = array_map('strtoupper', array_map('trim', $rows[0]));

            $hasYes = in_array('YES', $header);
            $hasNo = in_array('NO', $header);
            $hasNa = in_array('N/A', $header) || in_array('NA', $header);

            if (!$hasYes || !$hasNo) continue;

            // This is a disclosure table
            $section['type'] = 'disclosure_checklist';
            $section['header'] = $header;
            $section['has_na'] = $hasNa;
            $cols = count($header);

            // Parse each data row into structured items
            $items = [];
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $statement = trim($row[0] ?? '');

                if (empty($statement)) continue;

                // Check for sub-headers (rows with text only in first cell, typically uppercase)
                $otherCells = array_slice($row, 1);
                $otherContent = implode('', array_map('trim', $otherCells));
                $isSubHeader = !empty($statement)
                    && empty($otherContent)
                    && strlen($statement) < 80
                    && strtoupper($statement) === $statement;

                if ($isSubHeader) {
                    $items[] = [
                        'type' => 'sub_header',
                        'text' => $statement,
                    ];
                    continue;
                }

                // Check for conditional date fields
                $hasConditionalDate = (bool) preg_match(
                    '/if\s*yes.*when|when.*issued|date.*issued/i',
                    $statement
                );

                $items[] = [
                    'type' => 'checklist_item',
                    'statement' => $statement,
                    'has_conditional_date' => $hasConditionalDate,
                    'value' => null,
                    'date_value' => null,
                ];
            }

            $section['items'] = $items;

            // Remove the raw rows — replaced by structured items
            unset($section['rows']);
        }

        return $sections;
    }

    /**
     * Post-process: detect marker characters that users place
     * in their Word documents before importing. These are split out of
     * text runs into typed placeholder items.
     *
     * Recognised markers:
     *   @@@@  (≥4 @)  — field_placeholder       (input value)
     *   %%%%  (≥4 %)  — signature_placeholder
     *   ####  (≥4 #)  — initial_placeholder
     *   ~~~~<PURPOSE>~~~~ (ES-9) — insertable_block_placeholder
     *     Purpose tokens: OTHER_CONDITIONS, INCLUDED_ITEMS, EXCLUDED_ITEMS,
     *     or CUSTOM:<label>.
     */
    private function detectMarkers(array $sections): array
    {
        // Composed from ACCEPTED_MARKERS — the same array the import screen teaches
        // from, so the syntax we read and the syntax we advertise cannot diverge.
        // (The tilde form is first in that array precisely because it is the longest.)
        $splitPattern = self::markerSplitPattern();

        foreach ($sections as &$section) {
            if (!isset($section['content'])) continue;

            $newContent = [];
            foreach ($section['content'] as $item) {
                if ($item['type'] !== 'text') {
                    $newContent[] = $item;
                    continue;
                }

                $parts = preg_split($splitPattern, $item['value'], -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($parts as $part) {
                    // Reuse the ONE insertable pattern from ACCEPTED_MARKERS (no second
                    // charset copy to drift). A part that IS a full insertable token has
                    // its tilde fences stripped to recover the human name.
                    if (preg_match('/^' . self::ACCEPTED_MARKERS[0]['pattern'] . '$/', $part)) {
                        $name = preg_replace('/^~{4,}|~{4,}$/', '', $part);
                        $newContent[] = $this->parseInsertableBlockMarker($name);
                    } elseif (preg_match('/^@{4,}$/', $part)) {
                        $newContent[] = [
                            'type' => 'field_placeholder',
                            'marker' => 'input',
                            'length' => strlen($part),
                        ];
                    } elseif (preg_match('/^%{4,}$/', $part)) {
                        $newContent[] = [
                            'type' => 'signature_placeholder',
                            'marker' => 'signature',
                        ];
                    } elseif (preg_match('/^#{4,}$/', $part)) {
                        $newContent[] = [
                            'type' => 'initial_placeholder',
                            'marker' => 'initial',
                        ];
                    } elseif ($part !== '') {
                        // Preserve formatting from original item
                        $newItem = ['type' => 'text', 'value' => $part];
                        if (!empty($item['bold'])) $newItem['bold'] = true;
                        if (!empty($item['italic'])) $newItem['italic'] = true;
                        if (!empty($item['underline'])) $newItem['underline'] = true;
                        $newContent[] = $newItem;
                    }
                }
            }

            $section['content'] = $newContent;
        }

        return $sections;
    }

    /**
     * ES-9 — parse an insertable-block marker token like 'OTHER_CONDITIONS'
     * or 'CUSTOM:Outstanding Repairs' into a structured placeholder item.
     */
    private function parseInsertableBlockMarker(string $token): array
    {
        if (str_starts_with($token, 'CUSTOM:')) {
            $label   = trim(substr($token, 7));
            $purpose = 'custom_named';
            $blockId = 'custom_' . \Illuminate\Support\Str::slug($label !== '' ? $label : 'unnamed', '_');
            return [
                'type'         => 'insertable_block_placeholder',
                'marker'       => 'insertable_block',
                'block_id'     => $blockId,
                'purpose'      => $purpose,
                'custom_label' => $label,
                'raw_token'    => $token,
            ];
        }

        $purposeMap = [
            'OTHER_CONDITIONS' => 'other_conditions',
            'INCLUDED_ITEMS'   => 'included_items',
            'EXCLUDED_ITEMS'   => 'excluded_items',
        ];

        // A built-in purpose token (all-caps snake) keeps its mapped purpose and a
        // lower-cased block id. Any other name is a human-natural custom field
        // ("Seller - Full name", "Asking price (Rand)"): carry the name verbatim as
        // the label the builder shows, and derive a SAFE slug for the block id — never
        // a raw id full of spaces and slashes.
        $token = trim($token);
        if (isset($purposeMap[$token])) {
            return [
                'type'         => 'insertable_block_placeholder',
                'marker'       => 'insertable_block',
                'block_id'     => strtolower($token),
                'purpose'      => $purposeMap[$token],
                'custom_label' => null,
                'raw_token'    => $token,
            ];
        }

        $blockId = \Illuminate\Support\Str::slug($token !== '' ? $token : 'unnamed', '_');

        return [
            'type'         => 'insertable_block_placeholder',
            'marker'       => 'insertable_block',
            'block_id'     => $blockId !== '' ? $blockId : 'unnamed',
            'purpose'      => 'custom_named',
            'custom_label' => $token,
            'raw_token'    => $token,
        ];
    }

    /**
     * AT-262 near-miss detection. Scans raw text for marker-LIKE sequences that did
     * NOT parse — an agent's first real doc almost always has a few — and says WHY,
     * so the low/zero-field guard can teach instead of silently dropping them.
     *
     * A near-miss is any run bounded by 2+ tildes that is not a valid insertable
     * token. Two failure classes are named:
     *   - wrong tilde count (~~Name~~ or ~~~Name~~~ — needs FOUR each side);
     *   - a disallowed character in the name (only the ~ delimiter is disallowed now,
     *     so this catches e.g. a stray ~ or a truly empty ~~~~~~~~).
     *
     * @return list<array{raw:string, name:string, reason:string}>
     */
    public function detectNearMissMarkers(string $text): array
    {
        $valid = '/^' . self::ACCEPTED_MARKERS[0]['pattern'] . '$/';
        $nameClass = '[A-Za-z0-9 _\/()&\'.,:-]';

        // Candidate: 2+ tildes, a (possibly empty) non-tilde run, 2+ tildes.
        preg_match_all('/~{2,}[^~]{0,120}~{2,}/', $text, $cands);

        $misses = [];
        $seen = [];
        foreach ($cands[0] as $raw) {
            if (preg_match($valid, $raw)) {
                continue; // a real, accepted marker
            }
            if (isset($seen[$raw])) {
                continue;
            }
            $seen[$raw] = true;

            $name = trim(preg_replace('/^~+|~+$/', '', $raw));
            $lead = strlen($raw) - strlen(ltrim($raw, '~'));
            $tail = strlen($raw) - strlen(rtrim($raw, '~'));

            if ($lead < 4 || $tail < 4) {
                $reason = "uses {$lead}/{$tail} tildes — a marker needs FOUR tildes on each side: ~~~~{$name}~~~~";
            } elseif ($name === '') {
                $reason = 'is empty — put a field name between the tildes, e.g. ~~~~Seller - Full name~~~~';
            } else {
                preg_match_all('/' . $nameClass . '/u', $name, $ok);
                $bad = preg_replace('/' . $nameClass . '/u', '', $name);
                $badChars = implode(' ', array_unique(preg_split('//u', $bad, -1, PREG_SPLIT_NO_EMPTY) ?: []));
                $reason = "the name contains a character that isn't allowed ({$badChars}); allowed: " . self::INSERTABLE_NAME_HUMAN;
            }

            $misses[] = ['raw' => $raw, 'name' => $name, 'reason' => $reason];
        }

        return $misses;
    }

    /**
     * ES-9 — collect detected insertable blocks across all sections so the
     * CDS draft / template can persist the metadata alongside the parsed body.
     * Call after detectMarkers().
     *
     * @return array<int, array{block_id:string, purpose:string, label:string, position_marker:string}>
     */
    public function collectInsertableBlocks(array $sections): array
    {
        $blocks = [];
        $seen = [];
        foreach ($sections as $section) {
            foreach (($section['content'] ?? []) as $item) {
                if (($item['type'] ?? '') !== 'insertable_block_placeholder') continue;
                $id = $item['block_id'];
                if (isset($seen[$id])) continue;
                $seen[$id] = true;

                $defaultLabels = [
                    'other_conditions' => 'Other Conditions',
                    'included_items'   => 'Included Items',
                    'excluded_items'   => 'Excluded Items',
                ];
                $label = $item['custom_label']
                    ?? $defaultLabels[$item['purpose']]
                    ?? ucwords(str_replace('_', ' ', $item['block_id']));

                $blocks[] = [
                    'id'              => $id,
                    'purpose'         => $item['purpose'],
                    'label'           => $label,
                    'position_marker' => '~~~~' . $item['raw_token'] . '~~~~',
                    'min_conditions'  => 0,
                    'max_conditions'  => $item['purpose'] === 'other_conditions' ? 20 : 30,
                    'auto_number'     => $item['purpose'] === 'other_conditions',
                    'locked'          => false,
                ];
            }
        }

        return $blocks;
    }

    /**
     * Context-aware auto-identification for marker-detected fields.
     * Reads text before/after each field_placeholder to determine
     * what kind of field it is (contact name, ID, address, amount, etc).
     */
    private function identifyFieldsFromContext(array $sections): array
    {
        foreach ($sections as &$section) {
            if (!isset($section['content'])) continue;

            for ($i = 0; $i < count($section['content']); $i++) {
                $item = &$section['content'][$i];

                if ($item['type'] !== 'field_placeholder') continue;

                // Get text BEFORE this marker (same content array)
                $textBefore = '';
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($section['content'][$j]['type'] === 'text') {
                        $textBefore = trim($section['content'][$j]['value']);
                        break;
                    }
                }

                // Get text AFTER this marker
                $textAfter = '';
                for ($j = $i + 1; $j < count($section['content']); $j++) {
                    if ($section['content'][$j]['type'] === 'text') {
                        $textAfter = trim($section['content'][$j]['value']);
                        break;
                    }
                }

                // Full clause text for broader context
                $fullClauseText = $this->contentToPlainText($section['content']);

                // Run identification
                $identification = $this->identifyField(
                    $textBefore, $textAfter, $fullClauseText
                );

                $item['label'] = $identification['label'];
                $item['field_name'] = $identification['field_name'];
                $item['field_type'] = $identification['field_type'];
                $item['source'] = $identification['source'];
                $item['confidence'] = $identification['confidence'];
            }
        }

        return $sections;
    }

    /**
     * Identify a field from surrounding text context.
     * Returns label, field_name, field_type, source, and confidence.
     */
    private function identifyField(string $before, string $after, string $clause): array
    {
        $patterns = [
            // CONTACT — Name fields
            ['match' => '/I\s*\/\s*We\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Owner Name(s)', 'field_name' => 'contact.full_names', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/the\s+undersigned/i', 'on' => 'after',
             'result' => ['label' => 'Owner Name(s)', 'field_name' => 'contact.full_names', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/landlord.*means$/i', 'on' => 'before',
             'result' => ['label' => 'Landlord Name', 'field_name' => 'contact.landlord_name', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/tenant.*means$/i', 'on' => 'before',
             'result' => ['label' => 'Tenant Name', 'field_name' => 'contact.tenant_name', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            // CONTACT — ID
            ['match' => '/id\s*(number|no)/i', 'on' => 'before',
             'result' => ['label' => 'ID Number', 'field_name' => 'contact.id_number', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/\(id:?\s*$/i', 'on' => 'before',
             'result' => ['label' => 'ID Number', 'field_name' => 'contact.id_number', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/passport.*registration/i', 'on' => 'before',
             'result' => ['label' => 'ID/Passport No.', 'field_name' => 'contact.id_number', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            // CONTACT — Address
            ['match' => '/physical\s+address/i', 'on' => 'before',
             'result' => ['label' => 'Physical Address', 'field_name' => 'contact.address', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            // CONTACT — Phone / Email
            ['match' => '/\btel:?\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Phone', 'field_name' => 'contact.phone', 'field_type' => 'tel', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/\bcell:?\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Cell', 'field_name' => 'contact.cell', 'field_type' => 'tel', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/email:?\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Email', 'field_name' => 'contact.email', 'field_type' => 'email', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/email\s*address:?\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Email', 'field_name' => 'contact.email', 'field_type' => 'email', 'source' => 'contact', 'confidence' => 'high']],

            // PROPERTY — Address / Erf
            ['match' => '/property\s*(situated|known|at)/i', 'on' => 'before',
             'result' => ['label' => 'Property Address', 'field_name' => 'property.address', 'field_type' => 'text', 'source' => 'property', 'confidence' => 'high']],

            ['match' => '/erf\s*\/?\s*(sectional|unit|no)/i', 'on' => 'before',
             'result' => ['label' => 'Erf/Unit No.', 'field_name' => 'property.erf_number', 'field_type' => 'text', 'source' => 'property', 'confidence' => 'high']],

            ['match' => '/complex.*estate.*known/i', 'on' => 'before',
             'result' => ['label' => 'Complex Name', 'field_name' => 'property.complex_name', 'field_type' => 'text', 'source' => 'property', 'confidence' => 'high']],

            ['match' => '/\(street\)/i', 'on' => 'after',
             'result' => ['label' => 'Street', 'field_name' => 'property.street', 'field_type' => 'text', 'source' => 'property', 'confidence' => 'high']],

            ['match' => '/\(township\)/i', 'on' => 'after',
             'result' => ['label' => 'Township', 'field_name' => 'property.township', 'field_type' => 'text', 'source' => 'property', 'confidence' => 'high']],

            ['match' => '/\(district\)/i', 'on' => 'after',
             'result' => ['label' => 'District', 'field_name' => 'property.district', 'field_type' => 'text', 'source' => 'property', 'confidence' => 'high']],

            // FINANCIAL — Amounts
            ['match' => '/\bR\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Amount (R)', 'field_name' => 'deal.amount', 'field_type' => 'currency', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/price.*is\s*R\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Price (R)', 'field_name' => 'deal.price', 'field_type' => 'currency', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/rental.*R\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Rental Amount (R)', 'field_name' => 'deal.rental_amount', 'field_type' => 'currency', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/deposit.*R\s*$/i', 'on' => 'before',
             'result' => ['label' => 'Deposit (R)', 'field_name' => 'deal.deposit', 'field_type' => 'currency', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/percentage\s*of/i', 'on' => 'before',
             'result' => ['label' => '% Rate', 'field_name' => 'deal.percentage', 'field_type' => 'number', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/commission/i', 'on' => 'clause',
             'result' => ['label' => '% Commission', 'field_name' => 'deal.commission_percent', 'field_type' => 'number', 'source' => 'deal', 'confidence' => 'medium']],

            // FINANCIAL — Amount in words
            ['match' => '/^\s*\(/i', 'on' => 'after',
             'result' => ['label' => 'Amount in Words', 'field_name' => 'deal.amount_words', 'field_type' => 'text', 'source' => 'deal', 'confidence' => 'medium']],

            // BANKING
            ['match' => '/account\s*holder/i', 'on' => 'before',
             'result' => ['label' => 'Account Holder', 'field_name' => 'banking.account_holder', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/bank\s*name/i', 'on' => 'before',
             'result' => ['label' => 'Bank Name', 'field_name' => 'banking.bank_name', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/account\s*(number|no)/i', 'on' => 'before',
             'result' => ['label' => 'Account No.', 'field_name' => 'banking.account_number', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/branch.*code/i', 'on' => 'before',
             'result' => ['label' => 'Branch Code', 'field_name' => 'banking.branch_code', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            ['match' => '/branch.*name/i', 'on' => 'before',
             'result' => ['label' => 'Branch Name', 'field_name' => 'banking.branch_name', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            // DATES
            ['match' => '/commenc.*on/i', 'on' => 'before',
             'result' => ['label' => 'Start Date', 'field_name' => 'deal.start_date', 'field_type' => 'date', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/terminat.*on/i', 'on' => 'before',
             'result' => ['label' => 'End Date', 'field_name' => 'deal.end_date', 'field_type' => 'date', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/midnight\s*on/i', 'on' => 'before',
             'result' => ['label' => 'Expiry Date', 'field_name' => 'deal.expiry_date', 'field_type' => 'date', 'source' => 'deal', 'confidence' => 'high']],

            ['match' => '/on\s*this$/i', 'on' => 'before',
             'result' => ['label' => 'Day', 'field_name' => 'signing.day', 'field_type' => 'text', 'source' => 'manual', 'confidence' => 'high']],

            ['match' => '/day\s*of$/i', 'on' => 'before',
             'result' => ['label' => 'Month', 'field_name' => 'signing.month', 'field_type' => 'text', 'source' => 'manual', 'confidence' => 'high']],

            ['match' => '/20$/i', 'on' => 'before',
             'result' => ['label' => 'Year', 'field_name' => 'signing.year', 'field_type' => 'text', 'source' => 'manual', 'confidence' => 'high']],

            ['match' => '/signed.*at$/i', 'on' => 'before',
             'result' => ['label' => 'Place of Signing', 'field_name' => 'signing.place', 'field_type' => 'text', 'source' => 'manual', 'confidence' => 'high']],

            ['match' => '/\bat$/i', 'on' => 'before',
             'result' => ['label' => 'Time', 'field_name' => 'signing.time', 'field_type' => 'text', 'source' => 'manual', 'confidence' => 'medium']],

            // VAT
            ['match' => '/vat.*number/i', 'on' => 'before',
             'result' => ['label' => 'VAT No.', 'field_name' => 'contact.vat_number', 'field_type' => 'text', 'source' => 'contact', 'confidence' => 'high']],

            // CONTACT DETAILS (generic label:field pattern)
            ['match' => '/contact\s*details/i', 'on' => 'before',
             'result' => ['label' => 'Contact Details', 'field_name' => 'contact.phone', 'field_type' => 'tel', 'source' => 'contact', 'confidence' => 'medium']],
        ];

        // Check patterns
        foreach ($patterns as $p) {
            $target = match($p['on']) {
                'before' => $before,
                'after' => $after,
                'clause' => $clause,
                default => $before,
            };

            if (preg_match($p['match'], $target)) {
                return $p['result'];
            }
        }

        // Medium confidence — common label:field pattern
        // If text before ends with a colon or looks like a label, use it
        if (preg_match('/([A-Za-z\s]+):?\s*$/', $before, $m)) {
            $label = trim($m[1]);
            if (strlen($label) > 2 && strlen($label) < 40) {
                return [
                    'label' => $label,
                    'field_name' => '',
                    'field_type' => 'text',
                    'source' => 'manual',
                    'confidence' => 'medium',
                ];
            }
        }

        // Low confidence — unknown field
        return [
            'label' => 'Input',
            'field_name' => '',
            'field_type' => 'text',
            'source' => 'manual',
            'confidence' => 'low',
        ];
    }

    /**
     * Legacy: replaced by marker-based detection.
     * Post-process: detect underscore runs as field placeholders.
     * Lines of underscores (3+) become interactive field_placeholder items.
     */
    private function detectFieldPlaceholders(array $sections): array
    {
        foreach ($sections as &$section) {
            if (!isset($section['content'])) continue;

            $newContent = [];
            foreach ($section['content'] as $item) {
                if ($item['type'] !== 'text') {
                    $newContent[] = $item;
                    continue;
                }

                // Split text on underscore runs (3+), dot runs (4+), or unicode ellipsis runs (2+)
                $parts = preg_split('/(_{3,}|\.{4,}|…{2,})/u', $item['value'], -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($parts as $part) {
                    if (preg_match('/^(_{3,}|\.{4,}|…{2,})$/u', $part)) {
                        // This is a field placeholder
                        $newContent[] = [
                            'type' => 'field_placeholder',
                            'length' => mb_strlen($part),
                        ];
                    } elseif ($part !== '') {
                        // Copy formatting from original item
                        $newItem = ['type' => 'text', 'value' => $part];
                        if (!empty($item['bold'])) $newItem['bold'] = true;
                        if (!empty($item['italic'])) $newItem['italic'] = true;
                        if (!empty($item['underline'])) $newItem['underline'] = true;
                        $newContent[] = $newItem;
                    }
                }
            }

            $section['content'] = $newContent;
        }

        return $sections;
    }

    /**
     * Post-process: assign smart labels to field placeholders
     * based on surrounding text context.
     */
    private function labelFieldPlaceholders(array $sections): array
    {
        foreach ($sections as &$section) {
            if (!isset($section['content'])) continue;

            $prevText = '';
            foreach ($section['content'] as &$item) {
                if ($item['type'] === 'text') {
                    $prevText = trim($item['value']);
                }

                if ($item['type'] !== 'field_placeholder') continue;

                // Try to label based on preceding text
                $label = $this->inferFieldLabel($prevText);
                if ($label) {
                    $item['label'] = $label['label'];
                    $item['field_type'] = $label['type'];
                    $item['field_name'] = $label['name'];
                }
            }
        }

        return $sections;
    }

    /**
     * Infer a field label from the text that precedes the placeholder.
     */
    private function inferFieldLabel(string $precedingText): ?array
    {
        $patterns = [
            // Banking fields (specific patterns first)
            '/account\s*holder.?s?\s*name/i' => ['label' => 'Account Holder', 'type' => 'text', 'name' => 'banking.account_holder'],
            '/account\s*holder/i' => ['label' => 'Account Holder', 'type' => 'text', 'name' => 'banking.account_holder'],
            '/bank\s*name/i' => ['label' => 'Bank Name', 'type' => 'text', 'name' => 'banking.bank_name'],
            '/account\s*number/i' => ['label' => 'Account No.', 'type' => 'text', 'name' => 'banking.account_number'],
            '/branch.*(?:code|name)/i' => ['label' => 'Branch Code', 'type' => 'text', 'name' => 'banking.branch_code'],

            // Owner/party fields (specific before generic)
            '/contact\s*details/i' => ['label' => 'Phone', 'type' => 'tel', 'name' => 'contact.phone'],
            '/owner.?s?\s*contact/i' => ['label' => 'Phone', 'type' => 'tel', 'name' => 'contact.phone'],
            '/owner.?s?\s*email/i' => ['label' => 'Email', 'type' => 'email', 'name' => 'contact.email'],
            '/email\s*address/i' => ['label' => 'Email', 'type' => 'email', 'name' => 'contact.email'],

            // Property fields
            '/property\s*known/i' => ['label' => 'Property Address', 'type' => 'text', 'name' => 'property.address'],
            '/property\s*situated/i' => ['label' => 'Property Address', 'type' => 'text', 'name' => 'property.address'],

            // Financial
            '/\bR\s*$/i' => ['label' => 'Amount (R)', 'type' => 'currency', 'name' => 'deal.amount'],
            '/percentage\s*of/i' => ['label' => '% Rate', 'type' => 'number', 'name' => 'deal.percentage'],
            '/commission.*of/i' => ['label' => '% Commission', 'type' => 'number', 'name' => 'deal.commission'],
            '/management\s*fee\s*of/i' => ['label' => '% Fee', 'type' => 'number', 'name' => 'deal.management_fee'],

            // Date fields
            '/commencing\s*on/i' => ['label' => 'Start Date', 'type' => 'date', 'name' => 'deal.start_date'],
            '/terminat.*on/i' => ['label' => 'End Date', 'type' => 'date', 'name' => 'deal.end_date'],
            '/day\s*of$/i' => ['label' => 'Month', 'type' => 'text', 'name' => 'signing.month'],
            '/on\s*this$/i' => ['label' => 'Day', 'type' => 'text', 'name' => 'signing.day'],
            '/signed.*at$/i' => ['label' => 'Place', 'type' => 'text', 'name' => 'signing.place'],

            // Time
            '/\bat$/i' => ['label' => 'Time', 'type' => 'time', 'name' => 'signing.time'],

            // ID / Registration
            '/id\s*number/i' => ['label' => 'ID Number', 'type' => 'text', 'name' => 'contact.id_number'],
            '/registration\s*number/i' => ['label' => 'Reg No.', 'type' => 'text', 'name' => 'contact.reg_number'],
            '/vat.*number/i' => ['label' => 'VAT No.', 'type' => 'text', 'name' => 'contact.vat_number'],

            // Exclusive Authority / Sale document patterns
            '/^I\s*\/\s*We$/i' => ['label' => 'Owner Names', 'type' => 'text', 'name' => 'contact.full_names'],
            '/property\s*erf/i' => ['label' => 'Erf/Unit No.', 'type' => 'text', 'name' => 'property.erf_number'],
            '/complex.*estate.*known/i' => ['label' => 'Complex Name', 'type' => 'text', 'name' => 'property.complex_name'],
            '/\(street\)/i' => ['label' => 'Street', 'type' => 'text', 'name' => 'property.street'],
            '/\(township\)/i' => ['label' => 'Township', 'type' => 'text', 'name' => 'property.township'],
            '/\(district\)/i' => ['label' => 'District', 'type' => 'text', 'name' => 'property.district'],
            '/physical\s*address/i' => ['label' => 'Address', 'type' => 'text', 'name' => 'contact.address'],
            '/tel:/i' => ['label' => 'Tel', 'type' => 'tel', 'name' => 'contact.phone'],
            '/gross\s*price.*R$/i' => ['label' => 'Price (R)', 'type' => 'currency', 'name' => 'deal.price'],
            '/midnight\s*on/i' => ['label' => 'Expiry Date', 'type' => 'date', 'name' => 'deal.expiry_date'],
            '/20$/i' => ['label' => 'Year', 'type' => 'text', 'name' => 'signing.year'],
        ];

        foreach ($patterns as $pattern => $result) {
            if (preg_match($pattern, $precedingText)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Post-process: group consecutive label:field paragraphs into a
     * label_value_group for neat two-column rendering.
     */
    private function detectLabelValuePairs(array $sections): array
    {
        $result = [];
        $group = [];

        for ($i = 0; $i < count($sections); $i++) {
            $section = $sections[$i];

            if ($this->isLabelValuePair($section)) {
                $group[] = $section;
                continue;
            }

            // Flush any accumulated group
            if (count($group) >= 2) {
                $result[] = $this->buildLabelValueGroup($group);
            } elseif (count($group) === 1) {
                $result[] = $group[0];
            }
            $group = [];

            $result[] = $section;
        }

        // Flush remaining
        if (count($group) >= 2) {
            $result[] = $this->buildLabelValueGroup($group);
        } elseif (count($group) === 1) {
            $result[] = $group[0];
        }

        return $result;
    }

    /**
     * Check if a section follows the "Label text: [FIELD]" pattern.
     */
    private function isLabelValuePair(array $section): bool
    {
        if (!in_array($section['type'], ['paragraph', 'clause'])) return false;

        $content = $section['content'] ?? [];
        if (empty($content)) return false;

        $hasText = false;
        $hasField = false;

        foreach ($content as $item) {
            if ($item['type'] === 'text' && trim($item['value'] ?? '') !== '') {
                $hasText = true;
            }
            if ($item['type'] === 'field_placeholder') {
                $hasField = true;
            }
        }

        if (!$hasText || !$hasField) return false;

        // The last text item before the first field should end with a colon
        $lastTextBeforeField = '';
        foreach ($content as $item) {
            if ($item['type'] === 'field_placeholder') break;
            if ($item['type'] === 'text' && trim($item['value'] ?? '') !== '') {
                $lastTextBeforeField = $item['value'];
            }
        }

        $trimmed = rtrim($lastTextBeforeField);
        return str_ends_with($trimmed, ':') || str_ends_with($trimmed, ': ');
    }

    /**
     * Build a label_value_group from consecutive label:field sections.
     */
    private function buildLabelValueGroup(array $items): array
    {
        $pairs = [];
        foreach ($items as $item) {
            $label = '';
            $fields = [];

            foreach ($item['content'] ?? [] as $c) {
                if ($c['type'] === 'text') {
                    $label .= $c['value'];
                } elseif ($c['type'] === 'field_placeholder') {
                    $fields[] = $c;
                }
            }

            $pairs[] = [
                'label' => rtrim(trim($label), ': '),
                'fields' => $fields,
            ];
        }

        return [
            'type' => 'label_value_group',
            'pairs' => $pairs,
        ];
    }

    /**
     * Post-process: detect signature sections at the end of the document.
     * Scans backwards for signature trigger phrases and collects all
     * remaining sections into a single signature_section with party roles.
     */
    private function detectSignatureSections(array $sections): array
    {
        $sigStart = null;
        $sigKeywords = [
            'signed', 'signature', 'thus done', 'accepted and signed',
            'signed at', 'hereto set',
        ];

        // Scan backwards for the signature area trigger
        for ($i = count($sections) - 1; $i >= 0; $i--) {
            $text = strtolower($this->contentToPlainText($sections[$i]['content'] ?? []));

            foreach ($sigKeywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $sigStart = $i;
                    break 2;
                }
            }

            // If we've gone more than 15 sections from the end without finding a trigger, stop
            if (count($sections) - $i > 15) break;
        }

        if ($sigStart === null) return $sections;

        // Extract signature sections
        $sigSections = array_slice($sections, $sigStart);
        $mainSections = array_slice($sections, 0, $sigStart);

        // Parse the signature sections to extract party roles
        $parties = $this->extractPartyRoles($sigSections);

        // Replace with a single signature_section
        $mainSections[] = [
            'type' => 'signature_section',
            'preamble' => $this->buildSignaturePreamble($sigSections),
            'parties' => $parties,
        ];

        return $mainSections;
    }

    /**
     * Extract party roles from signature area paragraphs.
     */
    private function extractPartyRoles(array $sections): array
    {
        $roles = [];
        $roleKeywords = [
            'owner' => 'landlord',
            'landlord' => 'landlord',
            'tenant' => 'tenant',
            'lessee' => 'tenant',
            'seller' => 'seller',
            'purchaser' => 'buyer',
            'buyer' => 'buyer',
            'agent' => 'agent',
            'witness' => 'witness',
        ];

        foreach ($sections as $section) {
            $text = strtolower(trim($this->contentToPlainText($section['content'] ?? [])));

            // Skip field-only paragraphs (these are sig lines)
            $hasOnlyFields = collect($section['content'] ?? [])
                ->every(fn($c) => $c['type'] === 'field_placeholder'
                    || (($c['type'] === 'text') && trim($c['value'] ?? '') === ''));
            if ($hasOnlyFields) continue;

            // Check for role words in short label-like paragraphs
            foreach ($roleKeywords as $keyword => $role) {
                if (str_contains($text, $keyword)
                    && !str_contains($text, 'print name')
                    && !str_contains($text, 'the ')
                    && strlen($text) < 30) {
                    // Count occurrences to handle "Owner Owner Agent"
                    $count = substr_count($text, $keyword);
                    for ($i = 0; $i < $count; $i++) {
                        $roles[] = [
                            'role' => $role,
                            'label' => ucfirst($role),
                        ];
                    }
                }
            }
        }

        // If "Agent" not found, add one as default
        if (empty(array_filter($roles, fn($r) => $r['role'] === 'agent'))) {
            $roles[] = ['role' => 'agent', 'label' => 'Agent'];
        }

        return $roles;
    }

    /**
     * Build the preamble text from signature sections (e.g. "This Agreement has been accepted...").
     */
    private function buildSignaturePreamble(array $sections): string
    {
        $preamble = '';
        $excludePatterns = [
            '/^print\s*name/i',
            '/^owner\s*$/i',
            '/^agent\s*$/i',
            '/^tenant\s*$/i',
            '/^landlord\s*$/i',
            '/^witness\s*$/i',
            '/^seller\s*$/i',
            '/^buyer\s*$/i',
            '/^(owner\s*){2,}/i',
            '/^[\s_]+$/',
        ];

        foreach ($sections as $section) {
            $text = trim($this->contentToPlainText($section['content'] ?? []));
            if (strlen($text) < 5) continue;

            $isExcluded = false;
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    $isExcluded = true;
                    break;
                }
            }
            if ($isExcluded) continue;

            // Only include longer text that reads like a sentence
            if (strlen($text) > 20) {
                $preamble .= $text . ' ';
            }
        }

        return trim($preamble);
    }

    /**
     * Post-process: detect mid-document signature points.
     * A mid-document signature is a paragraph of only field_placeholders
     * followed by a paragraph with role labels, with more content after.
     */
    private function detectInlineSignatures(array $sections): array
    {
        $result = [];
        $i = 0;

        while ($i < count($sections)) {
            $section = $sections[$i];

            // Check if this is a mid-document signature point
            if ($this->isSignatureLine($section) && $i + 1 < count($sections)) {
                $nextSection = $sections[$i + 1];
                $nextText = strtolower(trim(
                    $this->contentToPlainText($nextSection['content'] ?? [])
                ));

                // Check if next paragraph contains role labels or "signature"
                $roleWords = ['owner', 'landlord', 'tenant', 'agent',
                             'seller', 'buyer', 'witness', 'lessee', 'lessor'];
                $hasRoles = false;
                foreach ($roleWords as $role) {
                    if (str_contains($nextText, $role)) {
                        $hasRoles = true;
                        break;
                    }
                }

                // Also detect standalone "signature" label after underscore line
                $isSignatureLabel = !$hasRoles && preg_match('/^signature/i', trim($nextText));

                // Only treat as inline signature if MORE content follows
                $remainingSections = count($sections) - $i - 2;

                if (($hasRoles || $isSignatureLabel) && $remainingSections > 3) {
                    $parties = $isSignatureLabel
                        ? [['role' => 'seller', 'label' => 'Seller']]
                        : $this->extractInlinePartyRoles($nextText);

                    $result[] = [
                        'type' => 'inline_signature',
                        'parties' => $parties,
                        'context' => $isSignatureLabel ? 'mid_document_acknowledgement' : 'mid_document',
                    ];

                    // Skip the signature line and role label paragraphs
                    $i += 2;

                    // Also skip any "Print Name" line that follows
                    if ($i < count($sections)) {
                        $checkText = strtolower(trim(
                            $this->contentToPlainText($sections[$i]['content'] ?? [])
                        ));
                        if (str_contains($checkText, 'print name')
                            || $this->isSignatureLine($sections[$i])) {
                            $i++;
                        }
                    }
                    continue;
                }
            }

            $result[] = $section;
            $i++;
        }

        return $result;
    }

    /**
     * Check if a section is a signature line (only field_placeholders and whitespace).
     */
    private function isSignatureLine(array $section): bool
    {
        $content = $section['content'] ?? [];
        if (empty($content)) return false;

        $hasField = false;
        $hasSubstantialText = false;

        foreach ($content as $item) {
            if ($item['type'] === 'field_placeholder') {
                $hasField = true;
            } elseif ($item['type'] === 'text') {
                $text = trim($item['value'] ?? '');
                if (strlen($text) > 5) {
                    $hasSubstantialText = true;
                }
            }
        }

        return $hasField && !$hasSubstantialText;
    }

    /**
     * Extract party roles from a role label line for inline signatures.
     */
    private function extractInlinePartyRoles(string $text): array
    {
        $roles = [];
        $roleMap = [
            'owner' => 'landlord',
            'landlord' => 'landlord',
            'lessor' => 'landlord',
            'tenant' => 'tenant',
            'lessee' => 'tenant',
            'seller' => 'seller',
            'buyer' => 'buyer',
            'purchaser' => 'buyer',
            'agent' => 'agent',
            'witness' => 'witness',
        ];

        foreach ($roleMap as $keyword => $role) {
            $count = substr_count(strtolower($text), $keyword);
            for ($j = 0; $j < $count; $j++) {
                $roles[] = [
                    'role' => $role,
                    'label' => ucfirst($role),
                ];
            }
        }

        return $roles;
    }

    /**
     * Post-process: insert page initial markers at regular intervals.
     * Estimates page breaks based on line counts (~45 lines per A4 page).
     */
    private function insertPageInitials(array $sections): array
    {
        $result = [];
        $lineCount = 0;
        $linesPerPage = 45;
        $pageNum = 1;

        foreach ($sections as $section) {
            $result[] = $section;

            $lines = $this->estimateSectionLines($section);
            $lineCount += $lines;

            if ($lineCount >= $linesPerPage) {
                $result[] = [
                    'type' => 'page_initials',
                    'page_number' => $pageNum,
                ];
                $lineCount = 0;
                $pageNum++;
            }
        }

        return $result;
    }

    /**
     * Estimate the number of lines a section takes on an A4 page.
     */
    private function estimateSectionLines(array $section): int
    {
        $type = $section['type'] ?? '';

        if ($type === 'heading') return 3;
        if ($type === 'signature_section') return 15;
        if ($type === 'inline_signature') return 5;
        if ($type === 'page_initials') return 2;
        if ($type === 'table') {
            $rows = count($section['rows'] ?? []);
            return max(3, $rows + 2);
        }
        if ($type === 'label_value_group') {
            return count($section['pairs'] ?? []) + 1;
        }

        // For clauses and paragraphs, estimate from text length
        $text = $this->contentToPlainText($section['content'] ?? []);
        $charCount = strlen($text);
        return max(1, (int) ceil($charCount / 80));
    }

    /**
     * Detect the document title from the first sections.
     */
    private function detectTitle(array $sections): string
    {
        foreach (array_slice($sections, 0, 5) as $section) {
            if ($section['type'] === 'title') {
                return $section['text'] ?? '';
            }
            if ($section['type'] === 'heading' && ($section['level'] ?? 99) <= 2) {
                return $section['text'] ?? '';
            }
        }

        // Fallback: first non-empty text
        foreach (array_slice($sections, 0, 3) as $section) {
            if (($section['type'] ?? '') === 'company_header') continue;
            $text = $this->contentToPlainText($section['content'] ?? []);
            if (trim($text) !== '') {
                return trim($text);
            }
        }

        return 'Untitled Document';
    }

    /**
     * Convert content array to plain text.
     */
    private function contentToPlainText(array $content): string
    {
        return collect($content)
            ->filter(fn($c) => ($c['type'] ?? '') === 'text')
            ->pluck('value')
            ->join('');
    }

    /**
     * Extract the full plain text of all sections for validation comparison.
     */
    private function extractFullPlainText(array $sections): string
    {
        $text = '';
        foreach ($sections as $section) {
            if (isset($section['content'])) {
                $text .= $this->contentToPlainText($section['content']) . "\n";
            }
            if (isset($section['text'])) {
                $text .= $section['text'] . "\n";
            }
            // Tables
            if (($section['type'] ?? '') === 'table') {
                foreach ($section['rows'] ?? [] as $row) {
                    $text .= implode(' | ', $row) . "\n";
                }
            }
        }
        return $text;
    }
}
