<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\FieldCorrection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class DocxParserService
{
    /**
     * What a fill-in blank looks like in a real HFC document.
     *
     * This used to be `/_{2,}%?/` — underscores only. HFC's actual templates barely use
     * underscores: they rule their fill-in lines with ELLIPSIS runs (`…………`, U+2026) and
     * dot leaders. Measured on the four live source documents:
     *
     *     document                        _runs   …runs   ....runs
     *     exclusive-authority-to-sell-v10     3      35          5
     *     offer-to-purchase-v13-enviro        2     117         13
     *     fica-natural-person-v8             11       0          0
     *     seller-mandatory-disclosure-v7     11       0          0
     *
     * So the importer saw 3 blanks in the EATS and 2 in the OTP — the two launch-critical
     * sales documents — and imported them as near-empty shells. It was not detecting
     * badly; it was looking for the wrong character.
     *
     * `u` flag is required: an ellipsis is multibyte.
     * Dot leaders need 4+ so ordinary prose "..." is never mistaken for a blank.
     */
    private const BLANK_PATTERN = '/(?:_{2,}|…{2,}|\.{4,})%?/u';

    /** Which shape produced this blank — useful when triaging a bad import. */
    private function blankSource(string $raw): string
    {
        if (str_starts_with($raw, '[')) {
            return 'bracket';
        }
        if (str_contains($raw, '…')) {
            return 'ellipsis';
        }
        if (str_contains($raw, '.')) {
            return 'dot_leader';
        }
        return 'underscore';
    }

    /** Strip the markup that splits one ruled line into several runs, so both consumers scan identical HTML. */
    private function normalizeForBlankScan(string $html): string
    {
        return (string) preg_replace('/<u>([_\s…\.]+)<\/u>/u', '$1', $html);
    }

    /**
     * THE ONE SCAN — every blank in the document, in document order.
     *
     * There used to be TWO scans and they could never agree: the field detector read only
     * the inner text of `<p>` elements, while the span injector scanned the WHOLE document
     * and merged adjacent runs. A blank inside a table therefore got a span but no field,
     * and the two lists fell out of alignment — on the real HFC documents by 5 (EATS),
     * 14 (OTP) and 2-3 (FICA / Disclosure).
     *
     * That misalignment is not cosmetic. The importer correlates the AI's suggestions to
     * the blanks in the page BY INDEX (`claude_originals[i]` ↔ `data-index="i"`), so the
     * moment the counts diverge every field after the divergence is written onto the WRONG
     * BLANK — the shift-assignments bug class STANDARDS.md names explicitly. The document
     * imports looking perfectly plausible and being wrong.
     *
     * One scan, consumed by both, means they cannot drift apart.
     *
     * @return list<array{offset:int,length:int,raw:string,source:string,label:?string}>
     */
    private function scanBlanks(string $normalizedHtml): array
    {
        $blanks = [];

        preg_match_all(self::BLANK_PATTERN, $normalizedHtml, $runs, PREG_OFFSET_CAPTURE);
        foreach ($runs[0] as $run) {
            $blanks[] = [
                'offset' => $run[1],
                'length' => strlen($run[0]),
                'raw'    => $run[0],
                'source' => $this->blankSource($run[0]),
                'label'  => null,
            ];
        }

        // Bracket placeholders — [Purchaser's full name]. Never the numeric [1] markers we
        // inject ourselves.
        preg_match_all('/\[([^\]]{1,80})\]/', $normalizedHtml, $brackets, PREG_OFFSET_CAPTURE);
        foreach ($brackets[0] as $i => $bracket) {
            $label = $brackets[1][$i][0];
            if (preg_match('/^\d+$/', $label)) {
                continue;
            }
            $blanks[] = [
                'offset' => $bracket[1],
                'length' => strlen($bracket[0]),
                'raw'    => $bracket[0],
                'source' => 'bracket',
                'label'  => $label,
            ];
        }

        usort($blanks, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        // Re-join runs that Mammoth split — but ONLY across empty space. The gap used to be
        // counted in characters alone (<= 10); HFC writes "Tel: ………… Email: …………", and
        // " Email: " is an 8-character gap, so two genuinely different fields merged into
        // one. A label between two blanks means TWO blanks.
        $merged = [];
        foreach ($blanks as $blank) {
            if ($merged !== []) {
                $prev     = $merged[count($merged) - 1];
                $gapStart = $prev['offset'] + $prev['length'];
                $gap      = $blank['offset'] - $gapStart;

                $mergeable = $prev['source'] !== 'bracket'
                    && $blank['source'] !== 'bracket'
                    && $gap >= 0
                    && $gap <= 10
                    && trim(strip_tags(substr($normalizedHtml, $gapStart, max($gap, 0)))) === '';

                if ($mergeable) {
                    $merged[count($merged) - 1]['length'] =
                        ($blank['offset'] + $blank['length']) - $prev['offset'];
                    continue;
                }
            }
            $merged[] = $blank;
        }

        return $merged;
    }

    /** Markup-free text for context extraction (entities decoded, whitespace collapsed). */
    private function plainSlice(string $htmlSlice): string
    {
        $text = html_entity_decode(strip_tags($htmlSlice), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Parse a .docx file.
     * Pipeline: Mammoth (HTML) + Claude AI (field detection on plain text).
     * Falls back to regex field detection if Claude fails.
     */
    public function parse(string $filePath): array
    {
        Log::info('DocxParser: parse() called', [
            'file_path' => $filePath,
            'file_exists' => file_exists($filePath),
            'file_size' => file_exists($filePath) ? filesize($filePath) : 0,
            'session_id' => session()->getId(),
            'temp_dir_exists' => is_dir(storage_path('app/public/imports/temp')),
        ]);

        if (!file_exists($filePath)) {
            throw new \RuntimeException('Upload file not found: ' . $filePath);
        }

        // Ensure temp directories exist
        $tempDir = storage_path('app/public/imports/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
            Log::info('DocxParser: Created temp directory', ['path' => $tempDir]);
        }

        // Step 1: Mammoth → HTML (fast, reliable, ~2 seconds)
        $mammothResult = $this->generateHtmlWithMammoth($filePath);
        $html = $mammothResult['html'];
        $warnings = $mammothResult['warnings'];

        Log::info('DocxParser: Mammoth HTML generated', [
            'html_length' => strlen($html),
        ]);

        // Step 1b: Strip original document header and signature from mammoth HTML
        $lengthBefore = strlen($html);
        $html = $this->stripDocumentHeader($html);
        Log::info('DocxParser: stripped header', [
            'html_length_before' => $lengthBefore,
            'html_length_after' => strlen($html),
        ]);

        $lengthBefore = strlen($html);
        $html = $this->stripDocumentSignature($html);
        Log::info('DocxParser: stripped signature', [
            'html_length_before' => $lengthBefore,
            'html_length_after' => strlen($html),
        ]);

        // Step 2: Extract plain text from docx for Claude
        $plainText = $this->extractPlainText($filePath);

        Log::info('DocxParser: Plain text extracted', [
            'text_length' => strlen($plainText),
        ]);

        // Step 3: Detect fields — Claude AI on plain text, regex fallback
        $regexFields = $this->detectFieldsFromHtml($html);

        Log::info('DocxParser: Regex detected ' . count($regexFields) . ' blanks');

        $fields = $regexFields; // default to regex

        if (!empty($plainText)) {
            try {
                $aiFields = $this->parseFieldsWithAi($plainText, $regexFields);

                if ($aiFields && count($aiFields) > 0) {
                    $fields = $aiFields;
                    Log::info('DocxParser: Using AI fields', [
                        'count' => count($fields),
                    ]);
                } else {
                    Log::info('DocxParser: AI returned no fields, using regex fallback');
                    Log::info('ImporterAI: Engine used', [
                        'engine' => 'regex_fallback',
                        'reason' => 'both_failed',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('DocxParser: AI field detection failed, using regex fallback', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('DocxParser: Empty text, using regex fields');
        }

        // Step 4: Inject field-blank spans into Mammoth HTML
        $html = $this->injectFieldSpans($html, $fields);

        // Step 4b: Strip signature sections and replace with placeholders
        $html = $this->stripSignatureSections($html);

        // Step 5: Apply CoreX document renderer
        try {
            $renderer = new CorexDocumentRenderer();
            $html = $renderer->render($html);
        } catch (\Throwable $e) {
            Log::error('DocxParser: renderer failed', ['error' => $e->getMessage()]);
        }

        Log::info('DocxParser: Parse complete', [
            'html_length' => strlen($html),
            'field_count' => count($fields),
        ]);

        return [
            'html' => $html,
            'fields' => $fields,
            'warnings' => $warnings,
        ];
    }

    /**
     * Extract plain text from docx using ZipArchive.
     * Strips all XML tags, keeps text content and underscore runs.
     */
    protected function extractPlainText(string $filePath): string
    {
        $zip = new ZipArchive();
        $result = $zip->open($filePath);

        if ($result !== true) {
            Log::warning('DocxParser: Could not open docx for plain text extraction');
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return '';
        }

        // Insert newlines at paragraph boundaries before stripping tags
        $xml = str_replace('</w:p>', "\n", $xml);
        // Insert space at run boundaries to prevent word concatenation
        $xml = str_replace('</w:r>', ' ', $xml);
        // Insert tab for table cells
        $xml = str_replace('</w:tc>', "\t", $xml);
        $xml = str_replace('</w:tr>', "\n", $xml);

        // Strip all XML tags
        $text = strip_tags($xml);

        // Normalize whitespace (but preserve newlines and underscores)
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $text = trim($text);

        // Truncate at 25,000 chars — more than enough for any document
        if (strlen($text) > 25000) {
            Log::warning('DocxParser: Plain text truncated from ' . strlen($text) . ' to 25000 chars');
            $text = mb_substr($text, 0, 25000);
        }

        return $text;
    }

    /**
     * Send numbered blank list to AI for field assignment.
     * Uses ImporterAiService dual-engine: Claude → OpenAI → empty.
     * Merge is a direct lookup — no similarity matching, no index shifting possible.
     */
    protected function parseFieldsWithAi(string $plainText, array $regexFields): ?array
    {
        set_time_limit(120);

        // Step 1: Build numbered blank list with before/after context
        $numberedBlanks = [];
        foreach ($regexFields as $i => $field) {
            $n = $i + 1;
            $before = $field['context_before'] ?? '';
            $after = $field['context_after'] ?? '';
            $numberedBlanks[] = "Blank [{$n}]: ...{$before} [___] {$after}...";
        }
        $blankList = implode("\n", $numberedBlanks);

        $userMessage = "Document plain text:\n---\n{$plainText}\n---\n\n";
        $userMessage .= "Numbered blanks (" . count($regexFields) . " total):\n{$blankList}";

        // Inject learned corrections from previous imports
        $corrections = $this->getLearnedCorrections($regexFields);
        if (!empty($corrections)) {
            $userMessage .= "\n\nLEARNED CORRECTIONS from previous imports (apply these):\n" . $corrections;
        }

        Log::info('DocxParser: Sending numbered blanks to AI', [
            'text_length' => strlen($plainText),
            'blank_count' => count($regexFields),
        ]);

        $aiService = new ImporterAiService();
        $parsed = $aiService->detectFields($userMessage, 4000);

        if (empty($parsed)) {
            return null;
        }

        Log::info('DocxParser: AI returned assignments', [
            'keys' => array_keys($parsed),
        ]);

        // Step 2: Direct lookup merge — blank [N] gets assignment for key "N"
        $assigned = 0;
        foreach ($regexFields as $i => &$field) {
            $n = (string) ($i + 1);

            if (isset($parsed[$n]) && is_array($parsed[$n])) {
                $cf = $parsed[$n];
                $field['suggested_label'] = $cf['label'] ?? 'Field ' . $n;
                $field['suggested_key'] = $cf['key'] ?? 'custom.field_' . $n;
                $field['pillar'] = $cf['pillar'] ?? 'custom';
                $field['assigned_to'] = $cf['assigned_to'] ?? 'agent';
                $field['confidence'] = $cf['confidence'] ?? 'low';
                $assigned++;
            } else {
                // AI didn't return this blank — mark unassigned
                $field['suggested_label'] = 'Unassigned [' . $n . ']';
                $field['suggested_key'] = 'custom.field_' . $n;
                $field['pillar'] = 'custom';
                $field['assigned_to'] = 'agent';
                $field['confidence'] = 'low';
            }
        }
        unset($field);

        Log::info('DocxParser: Direct merge complete', [
            'total_blanks' => count($regexFields),
            'assigned' => $assigned,
            'unassigned' => count($regexFields) - $assigned,
        ]);

        return $regexFields;
    }

    /**
     * Query stored corrections that match any of the current blanks' context.
     * Returns a formatted string to inject into the Claude prompt.
     */
    protected function getLearnedCorrections(array $regexFields): string
    {
        try {
            $corrections = FieldCorrection::orderByDesc('created_at')
                ->where('user_corrected_key', '!=', '')
                ->whereNotNull('user_corrected_key')
                ->where('context', '!=', '')
                ->whereNotNull('context')
                // Exclude garbage suffixed keys from early testing
                ->whereRaw("user_corrected_key NOT REGEXP '_[0-9]+(_[0-9]+)*$'")
                ->limit(200)
                ->get();
        } catch (\Throwable $e) {
            // Table may not exist yet — silently skip
            return '';
        }

        if ($corrections->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($regexFields as $i => $field) {
            $blankCtx = mb_strtolower($field['context'] ?? '');
            if (empty($blankCtx) || mb_strlen($blankCtx) < 10) continue;

            foreach ($corrections as $correction) {
                $corrCtx = mb_strtolower($correction->context);
                if (mb_strlen($corrCtx) < 10) continue;

                similar_text($blankCtx, $corrCtx, $pct);

                // Require 75% similarity — 60% was too loose and caused false matches
                if ($pct > 75 || (mb_strlen($corrCtx) > 20 && str_contains($blankCtx, $corrCtx))) {
                    $n = $i + 1;
                    $line = "- Blank [{$n}] context '{$field['context']}' → correct answer is {$correction->user_corrected_label} ({$correction->user_corrected_key}), NOT {$correction->claude_suggested_label} ({$correction->claude_suggested_key})";
                    if (!empty($correction->correction_reason)) {
                        $line .= " (reason: {$correction->correction_reason})";
                    }
                    $lines[] = $line;
                    break; // one correction per blank is enough
                }
            }
        }

        if (empty($lines)) {
            return '';
        }

        Log::info('DocxParser: Injecting ' . count($lines) . ' learned corrections into prompt');
        return implode("\n", $lines);
    }

    // =========================================================
    // MAMMOTH HTML GENERATION
    // =========================================================

    /**
     * Generate HTML using Mammoth (Node.js).
     * Returns raw HTML and warnings — no field spans injected yet.
     */
    protected function generateHtmlWithMammoth(string $filePath): array
    {
        $outputPath = str_replace('\\', '/',
            storage_path('app/imports/temp/' . uniqid('mammoth_') . '.json'));

        $scriptPath = str_replace('\\', '/',
            base_path('resources/js/mammoth-convert.mjs'));

        $filePath = str_replace('\\', '/', $filePath);

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $cmd = sprintf('node %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($filePath),
            escapeshellarg($outputPath));

        Log::info('DocxParser: Running Mammoth', ['cmd' => $cmd]);

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            Log::error('DocxParser: Mammoth failed', [
                'exit_code' => $exitCode,
                'output' => $error,
            ]);
            throw new \RuntimeException('Mammoth conversion failed: ' . $error);
        }

        if (!file_exists($outputPath)) {
            throw new \RuntimeException('Mammoth produced no output file.');
        }

        $json = file_get_contents($outputPath);
        @unlink($outputPath);

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Mammoth output is invalid JSON: ' . json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new \RuntimeException('Mammoth error: ' . $data['error']);
        }

        $html = $data['html'] ?? '';
        if (empty(trim($html))) {
            throw new \RuntimeException('Mammoth returned empty HTML.');
        }

        return [
            'html' => $html,
            'warnings' => $data['messages'] ?? [],
        ];
    }

    // =========================================================
    // REGEX FIELD DETECTION (fallback)
    // =========================================================

    /**
     * Detect field blanks from Mammoth HTML.
     *
     * Three detection sources:
     *   1. Underscore runs: _{2,} patterns in <p> text content
     *   2. Yellow highlights: spans with class "highlight" or elements with
     *      yellow background styles (from <w:highlight w:val="yellow"/>)
     *   3. Square brackets: [Text Inside Brackets] — label is explicit,
     *      these skip AI assignment entirely
     *
     * All sources produce the same context array structure and are merged
     * by document position so injectFieldSpans() handles them uniformly.
     */
    protected function detectFieldsFromHtml(string $html): array
    {
        // ONE scan — the same one injectFieldSpans() uses (see scanBlanks()). The detector
        // used to read only the inner text of <p> elements while the injector scanned the
        // whole document, so a blank inside a table produced a span with no field and the
        // two lists silently fell out of alignment. Because the importer correlates the AI's
        // suggestions to blanks BY INDEX, that misalignment reassigns every later field to
        // the wrong blank. Sharing the scan makes the two lists 1:1 by construction.
        $normalized = $this->normalizeForBlankScan($html);
        $blanks = $this->scanBlanks($normalized);

        $fields = [];
        foreach ($blanks as $blank) {
            // Context is what the AI names the field from, so hand it the surrounding
            // sentence with the markup stripped — taken from the blank's own coordinates.
            $beforeRaw = substr($normalized, max(0, $blank['offset'] - 260), min($blank['offset'], 260));
            $afterRaw  = substr($normalized, $blank['offset'] + $blank['length'], 260);

            $contextBefore = trim(mb_substr($this->plainSlice($beforeRaw), -60));
            $contextAfter  = trim(mb_substr($this->plainSlice($afterRaw), 0, 60));

            $field = [
                'raw' => $blank['raw'],
                'context' => trim($contextBefore . ' [___] ' . $contextAfter),
                'context_before' => $contextBefore,
                'context_after' => $contextAfter,
                'position' => $blank['offset'],
                'source' => $blank['source'],
            ];

            // A bracket placeholder names itself — [Purchaser's full name] — so it skips AI
            // assignment entirely, exactly as before.
            if ($blank['source'] === 'bracket' && $blank['label'] !== null) {
                $key = 'custom.' . preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($blank['label'])));
                $field['suggested_label'] = $blank['label'];
                $field['suggested_key']   = rtrim((string) $key, '_');
                $field['pillar']          = 'custom';
                $field['assigned_to']     = 'agent';
                $field['confidence']      = 'high';
            }

            $fields[] = $field;
        }

        // Yellow highlights are NOT indexed fields, and never really were: the injector only
        // ever replaced ruled blanks, so a highlight-sourced field arrived with no span
        // behind it and pushed every subsequent field onto the wrong blank. Surface them
        // loudly instead of silently corrupting the mapping.
        $highlights = $this->detectHighlightFields($html);
        if ($highlights !== []) {
            Log::warning('DocxParser: highlighted blanks found but NOT indexed — they carry no field-blank span', [
                'count' => count($highlights),
                'hint'  => 'rule the blank (…… or ____) or bracket it [Like This] so it can be mapped',
            ]);
        }

        return $fields;
    }

    /**
     * Detect yellow-highlighted spans from Mammoth HTML.
     *
     * Matches: class containing "highlight", or inline styles with
     * background:yellow / background:#ffff00 / background-color:yellow.
     * These come from Mammoth converting <w:highlight w:val="yellow"/>.
     */
    private function detectHighlightFields(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="hl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $fields = [];

        // Query: any element with class containing "highlight"
        // OR style containing yellow background variations
        $nodes = $xpath->query(
            '//*[contains(@class, "highlight")]'
            . ' | //*[contains(@style, "background:yellow")]'
            . ' | //*[contains(@style, "background:#ffff00")]'
            . ' | //*[contains(@style, "background-color:yellow")]'
            . ' | //*[contains(@style, "background:#FFFF00")]'
            . ' | //*[contains(@style, "background-color:#ffff00")]'
            . ' | //*[contains(@style, "background-color:#FFFF00")]'
        );

        if (!$nodes || $nodes->length === 0) {
            return [];
        }

        // Build a plain-text position map to locate highlights by document offset
        $fullText = strip_tags($html);

        foreach ($nodes as $node) {
            $highlightText = trim($node->textContent ?? '');
            if (mb_strlen($highlightText) < 1 || mb_strlen($highlightText) > 200) {
                continue;
            }

            // Find position in full document text
            $offset = mb_strpos($fullText, $highlightText);
            if ($offset === false) {
                $offset = 0; // fallback — still add the field
            }

            $contextBefore = mb_substr($fullText, max(0, $offset - 40), min($offset, 40));
            $afterPos = $offset + mb_strlen($highlightText);
            $contextAfter = mb_substr($fullText, $afterPos, 40);
            $context = trim($contextBefore . ' [___] ' . $contextAfter);

            $fields[] = [
                'raw' => $highlightText,
                'context' => $context,
                'context_before' => trim($contextBefore),
                'context_after' => trim($contextAfter),
                'position' => $offset,
                'source' => 'highlight',
            ];
        }

        return $fields;
    }

    // =========================================================
    // HEADER & SIGNATURE STRIPPING
    // =========================================================

    /**
     * Strip the original document header from mammoth HTML.
     *
     * Single precise rule: if the first block-level element is a <table>
     * containing a base64 image OR company registration details (Reg + FFC/VAT),
     * remove it and any trailing short address paragraphs.
     */
    private function stripDocumentHeader(string $html): string
    {
        $trimmed = ltrim($html);

        // Only attempt stripping if document starts with a table element
        if (stripos($trimmed, '<table') !== 0) {
            return $html;
        }

        // Find the closing </table> tag
        $tableEnd = stripos($trimmed, '</table>');
        if ($tableEnd === false) {
            return $html;
        }

        $tableHtml = substr($trimmed, 0, $tableEnd + 8);
        $tableText = strtolower(strip_tags($tableHtml));

        $hasBase64 = strpos($tableHtml, 'data:image') !== false;
        $hasReg    = strpos($tableText, 'reg') !== false;
        $hasFfc    = strpos($tableText, 'ffc') !== false
                  || strpos($tableText, 'vat') !== false;

        if (!$hasBase64 && !($hasReg && $hasFfc)) {
            return $html;
        }

        // Strip the header table
        $remainder = ltrim(substr($trimmed, $tableEnd + 8));

        return $remainder;
    }

    /**
     * Signature stripping is handled in DocumentTemplateGenerator::detectSignatureBoundary().
     * This method is kept as a no-op for pipeline compatibility.
     */
    private function stripDocumentSignature(string $html): string
    {
        return $html; // handled in generator
    }

    /**
     * Inject <span class="field-blank"> markers into Mammoth HTML.
     */
    private function injectFieldSpans(string $html, array $fields): string
    {
        // THE SAME scan the detector ran. One source of truth means the span at data-index=i
        // is always the blank that produced fields[i] — which is the whole ballgame, because
        // the importer maps the AI's suggestions onto blanks by that index.
        $normalizedHtml = $this->normalizeForBlankScan($html);
        $blanks = $this->scanBlanks($normalizedHtml);

        Log::info('DocxParser: Injecting field spans', [
            'blanks_in_html' => count($blanks),
            'fields_detected' => count($fields),
        ]);

        // If these ever disagree, every field after the divergence is mapped onto the wrong
        // blank and the document imports looking plausible and being wrong. Say so loudly.
        if ($fields !== [] && count($blanks) !== count($fields)) {
            Log::error('DocxParser: blank/field count mismatch — field mappings would shift', [
                'blanks' => count($blanks),
                'fields' => count($fields),
            ]);
        }

        // Replace in reverse order so offsets stay valid
        for ($i = count($blanks) - 1; $i >= 0; $i--) {
            $blank = $blanks[$i];
            $num = $i + 1;
            $span = '<span class="field-blank" data-index="' . $i . '" contenteditable="false">[' . $num . ']</span>';
            $normalizedHtml = substr_replace($normalizedHtml, $span, $blank['offset'], $blank['length']);
        }

        return $normalizedHtml;
    }

    // =========================================================
    // SIGNATURE SECTION STRIPPING
    // =========================================================

    /**
     * Detect and replace signature clusters in the HTML with a placeholder.
     *
     * A signature cluster is a group of consecutive paragraphs containing:
     *   - Lines with ONLY underscores (4+)
     *   - Party label lines: Owner, Lessor, Lessee, Agent, Witness, Buyer, Seller,
     *     "Print Name", "Print name", "Printed Name"
     *   - Preamble lines: "accepted and signed", "thus done and signed",
     *     "signed at", "on this", "day of", "am / pm", "(am/pm)"
     *
     * The entire cluster is replaced with an amber dashed placeholder div.
     */
    private function stripSignatureSections(string $html): string
    {
        // Split HTML into block-level elements (paragraphs, divs, tables)
        // We work on <p>...</p> blocks since Mammoth outputs paragraphs
        $pattern = '/(<p[^>]*>.*?<\/p>)/si';
        $blocks = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (empty($blocks)) {
            return $html;
        }

        // Classify each block
        $classified = [];
        foreach ($blocks as $i => $block) {
            $text = trim(strip_tags($block));
            $classified[] = [
                'html'  => $block,
                'text'  => $text,
                'type'  => $this->classifySignatureLine($text),
                'index' => $i,
            ];
        }

        // Find clusters: scan for seed lines (underscore or party label)
        // and expand to include adjacent signature-related lines within 5 positions
        $inCluster = array_fill(0, count($classified), false);
        $clusterSeeds = [];

        // First pass: find seed lines (underscore lines or party labels)
        foreach ($classified as $i => $block) {
            if (in_array($block['type'], ['underscore', 'party_label'])) {
                $clusterSeeds[] = $i;
            }
        }

        if (empty($clusterSeeds)) {
            return $html;
        }

        // Group seeds into clusters (seeds within 5 positions of each other)
        $clusters = [];
        $currentCluster = [$clusterSeeds[0]];

        for ($i = 1; $i < count($clusterSeeds); $i++) {
            if ($clusterSeeds[$i] - end($currentCluster) <= 10) {
                $currentCluster[] = $clusterSeeds[$i];
            } else {
                $clusters[] = $currentCluster;
                $currentCluster = [$clusterSeeds[$i]];
            }
        }
        $clusters[] = $currentCluster;

        // Validate clusters: must have BOTH underscore AND (party_label OR preamble)
        $validClusters = [];
        foreach ($clusters as $seedIndices) {
            $clusterStart = max(0, min($seedIndices) - 5);
            $clusterEnd = min(count($classified) - 1, max($seedIndices) + 5);

            $hasUnderscore = false;
            $hasPartyOrPreamble = false;
            $clusterLines = [];

            for ($i = $clusterStart; $i <= $clusterEnd; $i++) {
                $type = $classified[$i]['type'];
                if ($type === 'underscore') $hasUnderscore = true;
                if (in_array($type, ['party_label', 'preamble'])) $hasPartyOrPreamble = true;
                if (in_array($type, ['underscore', 'party_label', 'preamble'])) {
                    $clusterLines[] = $i;
                }
            }

            if ($hasUnderscore && $hasPartyOrPreamble && count($clusterLines) >= 2) {
                // Expand to include ALL signature-related lines in the range
                $rangeStart = min($clusterLines);
                $rangeEnd = max($clusterLines);

                // Also include empty/whitespace-only lines between cluster lines
                for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
                    if (in_array($classified[$i]['type'], ['underscore', 'party_label', 'preamble', 'empty'])) {
                        $inCluster[$i] = true;
                    }
                }

                $validClusters[] = ['start' => $rangeStart, 'end' => $rangeEnd];
            }
        }

        if (empty($validClusters)) {
            return $html;
        }

        $strippedCount = 0;

        // Rebuild HTML, replacing cluster blocks with placeholder
        $output = '';
        $placeholderInserted = array_fill(0, count($classified), false);

        foreach ($classified as $i => $block) {
            if ($inCluster[$i]) {
                // Check if this is the first line in its cluster — insert placeholder once
                $isFirstInCluster = true;
                if ($i > 0 && $inCluster[$i - 1]) {
                    $isFirstInCluster = false;
                }

                if ($isFirstInCluster) {
                    $output .= '<div class="sig-strip-placeholder" '
                        . 'data-stripped="true" '
                        . 'style="border: 2px dashed #f59e0b; background: #fffbeb; '
                        . 'padding: 12px 16px; margin: 16px 0; border-radius: 6px; '
                        . 'color: #92400e; font-style: italic; cursor: pointer;" '
                        . 'contenteditable="false">'
                        . "\xe2\x9a\xa1 Signature section removed &mdash; click the [Signature] button in the "
                        . 'toolbar to add a configured signature block here'
                        . '</div>';
                    $strippedCount++;
                }
                // Skip this block (it's part of the stripped cluster)
            } else {
                $output .= $block['html'];
            }
        }

        Log::info('DocxParser: stripSignatureSections', [
            'clusters_found' => count($validClusters),
            'placeholders_inserted' => $strippedCount,
        ]);

        return $output;
    }

    /**
     * Classify a line of text as part of a signature section or not.
     */
    private function classifySignatureLine(string $text): string
    {
        // Empty or whitespace only
        if ($text === '' || trim($text) === '') {
            return 'empty';
        }

        // Underscore-only line (4+ underscores, possibly with spaces/dots/dashes)
        if (preg_match('/^[\s_.\-\/]{0,10}_{4,}[\s_.\-\/]*$/', $text)) {
            return 'underscore';
        }

        $lower = mb_strtolower($text);

        // Party label line — line containing ONLY a party label word (with optional punctuation)
        $partyLabels = [
            'owner', 'lessor', 'lessee', 'agent', 'witness', 'buyer', 'seller',
            'tenant', 'landlord', 'purchaser', 'vendor',
            'print name', 'print names', 'printed name', 'full name', 'full names',
            'name and surname', 'signature', 'date', 'place', 'capacity',
            'signed', 'initial', 'initials',
        ];

        $stripped = preg_replace('/[^a-z\s]/', '', $lower);
        $stripped = trim($stripped);

        foreach ($partyLabels as $label) {
            if ($stripped === $label) {
                return 'party_label';
            }
        }

        // Also match compound labels like "Lessor / Agent" or "Buyer/Seller"
        $words = preg_split('/[\s\/,&]+/', $stripped);
        $allParty = true;
        $partyCount = 0;
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') continue;
            if (in_array($word, $partyLabels)) {
                $partyCount++;
            } else {
                $allParty = false;
                break;
            }
        }
        if ($allParty && $partyCount > 0 && mb_strlen($text) < 60) {
            return 'party_label';
        }

        // Preamble phrases
        $preamblePhrases = [
            'accepted and signed',
            'thus done and signed',
            'thus signed',
            'signed at',
            'on this',
            'day of',
            'am / pm',
            'am/pm',
            '(am/pm)',
            'in the presence of',
            'as witnesses',
            'who warrants',
            'duly authorised',
            'duly authorized',
            'hereto set',
            'hereunto set',
        ];

        foreach ($preamblePhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'preamble';
            }
        }

        return 'text';
    }
}
