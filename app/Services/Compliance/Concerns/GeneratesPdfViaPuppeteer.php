<?php

namespace App\Services\Compliance\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * PPRA Inspection Pack — shared by PpraInspectionReportPdfService and
 * PpraLetterheadSampleService (2026-09-28, Phase B) so a second PDF
 * generator in this same module doesn't duplicate the Puppeteer
 * invocation a third time. Scoped to this module only — mirrors, but does
 * not touch, WhistleblowComplaintService::invokePuppeteer() and every
 * other module's own independent copy; there is no cross-module shared
 * PDF service in this codebase (see PpraInspectionReportPdfService's own
 * docblock).
 */
trait GeneratesPdfViaPuppeteer
{
    private function invokePuppeteer(string $htmlPath, string $pdfPath, string $logContextId): void
    {
        $scriptPath = base_path('scripts/html-to-pdf.mjs');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $nodePath = 'node';
        if ($isWindows) {
            $candidates = [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
                trim(shell_exec('where node 2>NUL') ?? ''),
            ];
            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate && file_exists($candidate)) {
                    $nodePath = $candidate;
                    break;
                }
            }
        }

        $nodeArg   = escapeshellarg(str_replace('\\', '/', $nodePath));
        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg   = escapeshellarg(str_replace('\\', '/', $htmlPath));
        $outArg    = escapeshellarg(str_replace('\\', '/', $pdfPath));

        $envPrefix = '';
        if (! $isWindows) {
            $envPrefix = 'HOME=/tmp';
            if ($browserPath) {
                $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
            }
            $envPrefix .= ' ';
        }

        $command = sprintf('%s%s %s %s %s', $envPrefix, $nodeArg, $scriptArg, $htmlArg, $outArg);

        $tempDir = storage_path('app/temp');
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'ppra_pdf_' . $logContextId . '.log';

        Log::info('PPRA PDF generation starting', ['context' => $logContextId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (! file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            Log::error('PPRA PDF not generated', [
                'context' => $logContextId,
                'log'     => substr($logContent, 0, 500),
            ]);
            throw new \RuntimeException(
                'PPRA PDF generation failed for ' . $logContextId . '. '
                . ($logContent ? 'Script output: ' . substr($logContent, 0, 200) : 'No output from script.')
            );
        }

        Log::info('PPRA PDF complete', [
            'context' => $logContextId,
            'path'    => $normalizedOutput,
            'size'    => filesize($normalizedOutput),
        ]);
    }
}
