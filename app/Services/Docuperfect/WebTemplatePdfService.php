<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Flatten a web template document to PDF page images.
 *
 * Takes the merged_html from a web template document, converts it
 * to PDF via Puppeteer, measures client field positions for overlay
 * inputs, then splits the PDF into per-page PNG images.
 *
 * After flattening, the document behaves identically to a PDF template
 * in the signature setup and signing flows. Client fields become
 * interactive overlays positioned on the page images — same data
 * structure that sign.blade.php already reads.
 *
 * Storage: docuperfect/documents/{doc_id}/page-{n}.png
 */
class WebTemplatePdfService
{
    /**
     * Flatten a web template document to page images.
     *
     * Steps:
     * 1. Write merged_html to temp file
     * 2. Build list of client fields to measure
     * 3. Call web-template-flatten.mjs (Puppeteer): measure fields + generate PDF
     * 4. Convert PDF pages to PNGs
     * 5. Update fields_json with measured positions
     * 6. Store page count
     *
     * @return int Number of pages generated (0 on failure)
     */
    public function flatten(Document $document): int
    {
        $webTemplateData = $document->web_template_data ?? [];
        $mergedHtml = $webTemplateData['merged_html'] ?? '';

        if (empty($mergedHtml)) {
            Log::warning('WebTemplatePdfService::flatten — no merged_html', ['document_id' => $document->id]);
            return 0;
        }

        // 1. Wrap merged_html in a full HTML document shell and write to temp file
        $fullHtml = $this->wrapHtml($mergedHtml);
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/web-flatten-' . $document->id . '.html';
        $pdfPath = $tempDir . '/web-flatten-' . $document->id . '.pdf';
        file_put_contents($htmlPath, $fullHtml);

        // 2. Build list of client fields to measure (non-agent, non-auto, with field_name)
        $fieldsJson = $document->fields_json ?? [];
        $clientFields = $this->getClientFieldsToMeasure($fieldsJson);
        $fieldsPath = null;

        if (!empty($clientFields)) {
            $fieldsPath = $tempDir . '/web-flatten-' . $document->id . '-fields.json';
            file_put_contents($fieldsPath, json_encode($clientFields));
        }

        // 3. Call Puppeteer: measure field positions + generate PDF
        $puppeteerResult = $this->runPuppeteerFlatten($htmlPath, $pdfPath, $fieldsPath);

        // Clean up temp files
        @unlink($htmlPath);
        if ($fieldsPath) @unlink($fieldsPath);

        if (!$puppeteerResult) {
            Log::error('WebTemplatePdfService::flatten — Puppeteer flatten failed', ['document_id' => $document->id]);
            return 0;
        }

        // 4. Get page count from Puppeteer result or PDF
        $pageCount = $puppeteerResult['pages'] ?? 0;
        if ($pageCount < 1) {
            $pageCount = $this->getPdfPageCount($pdfPath);
        }
        if ($pageCount < 1) {
            Log::error('WebTemplatePdfService::flatten — could not determine page count', ['document_id' => $document->id]);
            @unlink($pdfPath);
            return 0;
        }

        // 5. Convert PDF pages to PNG images
        $outputDir = 'docuperfect/documents/' . $document->id;
        $storagePath = Storage::disk('local')->path($outputDir);
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $pagesGenerated = $this->convertPdfToImages($pdfPath, $storagePath, $pageCount);
        @unlink($pdfPath);

        if ($pagesGenerated < 1) {
            Log::error('WebTemplatePdfService::flatten — image conversion failed', ['document_id' => $document->id]);
            return 0;
        }

        // 6. Update fields_json with measured positions from Puppeteer
        $measuredFields = $puppeteerResult['fields'] ?? [];
        if (!empty($measuredFields)) {
            $fieldsJson = $this->applyMeasuredPositions($fieldsJson, $measuredFields);
            $document->update(['fields_json' => $fieldsJson]);

            Log::info('WebTemplatePdfService::flatten — updated field positions', [
                'document_id' => $document->id,
                'fields_measured' => count($measuredFields),
            ]);
        }

        // 7. Store page count in web_template_data
        $webTemplateData['flattened_page_count'] = $pagesGenerated;
        $document->update(['web_template_data' => $webTemplateData]);

        Log::info('WebTemplatePdfService::flatten — success', [
            'document_id' => $document->id,
            'page_count' => $pagesGenerated,
            'client_fields_measured' => count($measuredFields),
        ]);

        return $pagesGenerated;
    }

    /**
     * Extract client fields from fields_json that need measuring.
     *
     * Returns array of {field_id, data_field} for each field assigned
     * to a non-agent party that has a field_name matching an HTML data-field.
     */
    private function getClientFieldsToMeasure(array $fieldsJson): array
    {
        $agentRoles = ['agent', 'creator', 'auto'];
        $measureFields = [];

        foreach ($fieldsJson as $field) {
            $assignedTo = $field['assignedTo'] ?? 'creator';
            $type = $field['type'] ?? 'placeholder';

            // Skip agent/creator/auto fields — already baked into HTML
            if (in_array($assignedTo, $agentRoles)) {
                continue;
            }

            // Skip signature/initial fields — handled by signature markers
            if (in_array($type, ['signature', 'initial'])) {
                continue;
            }

            $fieldName = $field['field_name'] ?? $field['data_field'] ?? null;
            $fieldId = $field['id'] ?? null;

            if ($fieldName && $fieldId) {
                $measureFields[] = [
                    'field_id' => $fieldId,
                    'data_field' => $fieldName,
                ];
            }
        }

        return $measureFields;
    }

    /**
     * Apply measured positions from Puppeteer back to fields_json.
     *
     * Updates each client field's position, size, and pageIndex so
     * sign.blade.php can render them as interactive overlays on the
     * page images — identical to the PDF template field format.
     */
    private function applyMeasuredPositions(array $fieldsJson, array $measuredFields): array
    {
        // Index measured fields by field_id for fast lookup
        $positionMap = [];
        foreach ($measuredFields as $mf) {
            $positionMap[$mf['field_id']] = $mf;
        }

        foreach ($fieldsJson as &$field) {
            $fieldId = $field['id'] ?? null;
            if (!$fieldId || !isset($positionMap[$fieldId])) {
                continue;
            }

            $measured = $positionMap[$fieldId];

            // Update position and size as percentages (0-100) — same format
            // that sign.blade.php's fieldDisplayStyle() reads
            $field['position'] = [
                'x' => $measured['x'],
                'y' => $measured['y'],
            ];
            $field['size'] = [
                'width' => $measured['width'],
                'height' => $measured['height'],
            ];
            $field['pageIndex'] = $measured['pageIndex'];
        }
        unset($field);

        return $fieldsJson;
    }

    /**
     * Wrap merged HTML content in a full HTML document shell.
     */
    private function wrapHtml(string $mergedHtml): string
    {
        // If it already has a DOCTYPE or <html> tag, return as-is
        if (preg_match('/<!DOCTYPE|<html/i', $mergedHtml)) {
            return $mergedHtml;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @page {
            size: A4;
            margin: 15mm 18mm 20mm 18mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 0;
        }
        /* Ensure page breaks work */
        .page-break, [style*="page-break"] {
            page-break-after: always;
        }
    </style>
</head>
<body>
{$mergedHtml}
</body>
</html>
HTML;
    }

    /**
     * Run the web-template-flatten.mjs Puppeteer script.
     *
     * This generates the PDF AND measures client field positions in one
     * Puppeteer session for efficiency.
     *
     * @return array|null Parsed JSON result with 'pages' and 'fields' keys, or null on failure
     */
    private function runPuppeteerFlatten(string $htmlPath, string $pdfPath, ?string $fieldsPath): ?array
    {
        $scriptPath = base_path('scripts/web-template-flatten.mjs');
        $wrapper = config('services.pdf.node_wrapper', '');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg = escapeshellarg(str_replace('\\', '/', $htmlPath));
        $outArg = escapeshellarg(str_replace('\\', '/', $pdfPath));
        $fieldsArg = $fieldsPath ? escapeshellarg(str_replace('\\', '/', $fieldsPath)) : '';

        if ($wrapper) {
            $command = sprintf(
                'sudo %s %s %s %s %s 2>&1',
                escapeshellarg($wrapper), $scriptArg, $htmlArg, $outArg, $fieldsArg
            );
        } else {
            $envPrefix = '';
            if (!$isWindows) {
                $envPrefix = 'HOME=/tmp';
                if ($browserPath) {
                    $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
                }
                $envPrefix .= ' ';
            }
            $command = sprintf('%snode %s %s %s %s 2>&1', $envPrefix, $scriptArg, $htmlArg, $outArg, $fieldsArg);
        }

        $output = shell_exec($command);

        if (!file_exists($pdfPath)) {
            Log::error('WebTemplatePdfService::runPuppeteerFlatten — PDF not generated', [
                'command' => $command,
                'output' => $output ?: 'unknown error',
            ]);
            return null;
        }

        // Parse JSON output from Puppeteer script
        $result = null;
        if ($output) {
            // The script outputs JSON to stdout — find the JSON line
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $decoded = json_decode(trim($line), true);
                if ($decoded && !empty($decoded['success'])) {
                    $result = $decoded;
                    break;
                }
            }
        }

        if (!$result) {
            // PDF was generated but JSON output parsing failed — still usable
            Log::warning('WebTemplatePdfService::runPuppeteerFlatten — PDF generated but no JSON output', [
                'output' => $output,
            ]);
            $result = ['success' => true, 'pages' => 0, 'fields' => []];
        }

        Log::info('WebTemplatePdfService::runPuppeteerFlatten — success', [
            'pdf_size' => filesize($pdfPath),
            'pages' => $result['pages'] ?? 0,
            'fields_measured' => count($result['fields'] ?? []),
        ]);

        return $result;
    }

    /**
     * Get PDF page count using pdfinfo (same as DocumentFlattener).
     */
    private function getPdfPageCount(string $pdfPath): int
    {
        $pdfinfoPath = $this->findPdfInfo();
        $proc = new Process([$pdfinfoPath, $pdfPath]);
        $proc->setTimeout(30);
        $proc->run();

        if ($proc->isSuccessful()) {
            if (preg_match('/Pages:\s+(\d+)/', $proc->getOutput(), $m)) {
                return (int) $m[1];
            }
        }

        // Fallback for Windows: try using Ghostscript to count pages
        if (DIRECTORY_SEPARATOR === '\\') {
            return $this->getPdfPageCountGhostscript($pdfPath);
        }

        return 0;
    }

    /**
     * Fallback page count using Ghostscript (Windows).
     */
    private function getPdfPageCountGhostscript(string $pdfPath): int
    {
        $gsPath = $this->findGhostscript();
        if (!$gsPath) return 0;

        $command = sprintf(
            '%s -q -dNODISPLAY -c "(%s) (r) file runpdfbegin pdfpagecount = quit" 2>&1',
            escapeshellarg($gsPath),
            str_replace('\\', '/', $pdfPath)
        );
        $output = trim(shell_exec($command) ?? '');

        if (is_numeric($output)) {
            return (int) $output;
        }

        return 0;
    }

    /**
     * Convert PDF pages to PNG images using pdftoppm or Ghostscript.
     */
    private function convertPdfToImages(string $pdfPath, string $outputDir, int $pageCount): int
    {
        $pdftoppmPath = config('splitter.pdftoppm_path', 'pdftoppm');

        // Try pdftoppm first (preferred, same as DocumentFlattener)
        if ($this->commandExists($pdftoppmPath)) {
            return $this->convertWithPdftoppm($pdfPath, $outputDir, $pageCount, $pdftoppmPath);
        }

        // Fallback: Ghostscript
        $gsPath = $this->findGhostscript();
        if ($gsPath) {
            return $this->convertWithGhostscript($pdfPath, $outputDir, $pageCount, $gsPath);
        }

        Log::error('WebTemplatePdfService — no PDF-to-image converter found (pdftoppm or Ghostscript)');
        return 0;
    }

    /**
     * Convert using pdftoppm (Linux/server, same tool as DocumentFlattener).
     */
    private function convertWithPdftoppm(string $pdfPath, string $outputDir, int $pageCount, string $pdftoppmPath): int
    {
        $generated = 0;

        for ($page = 1; $page <= $pageCount; $page++) {
            $tempPrefix = $outputDir . '/temp-page';

            $proc = new Process([
                $pdftoppmPath,
                '-f', (string) $page,
                '-l', (string) $page,
                '-png',
                '-r', '200',
                $pdfPath,
                $tempPrefix,
            ]);
            $proc->setTimeout(120);
            $proc->run();

            if (!$proc->isSuccessful()) {
                Log::warning('WebTemplatePdfService::convertWithPdftoppm — failed', [
                    'page' => $page,
                    'error' => trim($proc->getErrorOutput()),
                ]);
                continue;
            }

            // pdftoppm names output as prefix-NN.png — find and rename
            $files = glob($tempPrefix . '-*.png');
            if (!empty($files)) {
                sort($files);
                $targetPath = $outputDir . '/page-' . ($page - 1) . '.png';
                rename($files[0], $targetPath);
                foreach (array_slice($files, 1) as $f) {
                    @unlink($f);
                }
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Convert using Ghostscript (Windows fallback).
     */
    private function convertWithGhostscript(string $pdfPath, string $outputDir, int $pageCount, string $gsPath): int
    {
        $generated = 0;

        for ($page = 1; $page <= $pageCount; $page++) {
            $outputPath = $outputDir . '/page-' . ($page - 1) . '.png';

            $proc = new Process([
                $gsPath,
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-sDEVICE=png16m',
                '-r200',
                '-dFirstPage=' . $page,
                '-dLastPage=' . $page,
                '-sOutputFile=' . str_replace('\\', '/', $outputPath),
                str_replace('\\', '/', $pdfPath),
            ]);
            $proc->setTimeout(120);
            $proc->run();

            if ($proc->isSuccessful() && file_exists($outputPath)) {
                $generated++;
            } else {
                Log::warning('WebTemplatePdfService::convertWithGhostscript — failed', [
                    'page' => $page,
                    'error' => trim($proc->getErrorOutput()),
                ]);
            }
        }

        return $generated;
    }

    /**
     * Find pdfinfo executable.
     */
    private function findPdfInfo(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates = [
                'C:/Program Files/poppler/Library/bin/pdfinfo.exe',
                'C:/tools/poppler/Library/bin/pdfinfo.exe',
                'C:/poppler/bin/pdfinfo.exe',
            ];
            foreach ($candidates as $path) {
                if (file_exists($path)) return $path;
            }
        }
        return 'pdfinfo';
    }

    /**
     * Find Ghostscript executable.
     */
    private function findGhostscript(): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates = [
                'C:/Program Files/gs/gs10.04.0/bin/gswin64c.exe',
                'C:/Program Files/gs/gs10.03.1/bin/gswin64c.exe',
                'C:/Program Files/gs/gs10.02.1/bin/gswin64c.exe',
                'C:/Program Files (x86)/gs/gs10.04.0/bin/gswin32c.exe',
            ];
            foreach ($candidates as $path) {
                if (file_exists($path)) return $path;
            }
            $output = trim(shell_exec('where gswin64c 2>NUL') ?? '');
            if ($output && file_exists($output)) return $output;
        } else {
            if ($this->commandExists('gs')) return 'gs';
        }
        return null;
    }

    /**
     * Check if a command exists on the system.
     */
    private function commandExists(string $command): bool
    {
        $check = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($command) . ' 2>NUL'
            : 'which ' . escapeshellarg($command) . ' 2>/dev/null';

        return !empty(trim(shell_exec($check) ?? ''));
    }
}
