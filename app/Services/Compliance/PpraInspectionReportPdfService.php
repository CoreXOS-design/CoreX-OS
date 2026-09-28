<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * PPRA Inspection Pack — the Inspection Report (the primary deliverable).
 * .ai/specs/ppra-inspection-pack.md §6.2.
 *
 * Renders synchronously (no queue — pure querying + rendering, no large
 * file bundling) via the same Puppeteer HTML-to-PDF pipeline every other
 * PDF-producing module in CoreX uses (scripts/html-to-pdf.mjs) — see
 * WhistleblowComplaintService::generatePdf()/invokePuppeteer() for the
 * sibling implementation this one mirrors; there is no shared cross-module
 * PDF service in this codebase, every module owns its own thin wrapper.
 */
class PpraInspectionReportPdfService
{
    public function __construct(
        private PpraInspectionPackChecklistService $checklist = new PpraInspectionPackChecklistService(),
    ) {
    }

    /**
     * Render the Inspection Report and return the path to the generated PDF.
     */
    public function generate(Agency $agency, User $generatedBy): string
    {
        $rows = $this->checklist->checklistFor($agency);
        $gaps = $rows->whereIn('status', ['amber', 'red'])->values();

        $reportReference = 'PPRA-' . $agency->id . '-' . now()->format('Ymd-Hi');

        $html = view('admin.ppra-inspection-pack.report', [
            'agency'          => $agency,
            'rows'            => $rows,
            'gaps'            => $gaps,
            'reportReference' => $reportReference,
            'generatedBy'     => $generatedBy,
            'generatedAt'     => now(),
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/ppra-report-' . $agency->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfDir = storage_path('app/ppra-inspection-pack/' . $agency->id);
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/' . $reportReference . '.pdf';

        $this->invokePuppeteer($htmlPath, $pdfPath, $agency->id);

        @unlink($htmlPath);

        return $pdfPath;
    }

    /** Mirrors WhistleblowComplaintService::invokePuppeteer() exactly. */
    private function invokePuppeteer(string $htmlPath, string $pdfPath, int $agencyId): void
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
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'ppra_report_' . $agencyId . '.log';

        Log::info('PPRA Inspection Report PDF generation starting', ['agency_id' => $agencyId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (! file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            Log::error('PPRA Inspection Report PDF not generated', [
                'agency_id' => $agencyId,
                'log'       => substr($logContent, 0, 500),
            ]);
            throw new \RuntimeException(
                'PPRA Inspection Report PDF generation failed for agency ' . $agencyId . '. '
                . ($logContent ? 'Script output: ' . substr($logContent, 0, 200) : 'No output from script.')
            );
        }

        Log::info('PPRA Inspection Report PDF complete', [
            'agency_id' => $agencyId,
            'path'      => $normalizedOutput,
            'size'      => filesize($normalizedOutput),
        ]);
    }
}
