<?php

namespace App\Services\Compliance;

use App\Models\FicaSubmission;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server-side PDF for the FICA Completion Report — Johan, 2026-08-25:
 * "Print / Save as PDF" is a browser feature, not a deliverable — output
 * varies by browser/print settings, backgrounds and section rules can drop,
 * and a signature image can land split across a page break with nobody
 * noticing. This generates a real file the same way payroll already does.
 *
 * Reuses the SAME Puppeteer pipeline as PayslipPdfService/RMCP/policy
 * acknowledgements (scripts/html-to-pdf.mjs) — not a second PDF library.
 * FicaController::downloadPdf() (the existing "Download PDF" certificate)
 * turns out NOT to be a server-side generator itself — it returns the same
 * kind of browser-print HTML page this report already has. Nothing there to
 * reuse beyond the html-to-pdf.mjs pipeline it also implicitly depends on
 * for every other real PDF in this codebase.
 *
 * Generated on demand, not stored — this only produces the download
 * response; whether the file should also be persisted against the
 * submission/deal is a separate, reported-not-built question.
 */
class FicaCompletionReportPdfService
{
    /**
     * Render the completion report HTML (the same view + data the on-screen
     * report uses) and convert it to a real PDF. Returns the absolute path
     * to a temp file — caller is responsible for the download response and
     * cleanup.
     */
    public function generate(FicaSubmission $submission): string
    {
        $data = app(FicaCompletionReportService::class)->build($submission);
        $html = view('compliance.fica.completion-report', $data)->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $stamp = uniqid();
        $htmlPath = $tempDir . "/fica-completion-{$submission->id}-{$stamp}.html";
        $pdfPath = $tempDir . "/fica-completion-{$submission->id}-{$stamp}.pdf";
        file_put_contents($htmlPath, $html);

        $this->invokePuppeteer($htmlPath, $pdfPath, $submission->id);

        @unlink($htmlPath);

        return $pdfPath;
    }

    /**
     * Client name, document type, and the approval date — an agent must be
     * able to tell what this is sitting in a downloads folder or an email
     * attachment without opening it. The approval date (not "today"), so the
     * filename stays the same no matter when it's downloaded.
     */
    public function filenameFor(FicaSubmission $submission): string
    {
        $submission->loadMissing('contact');
        $data = $submission->form_data ?? [];
        $name = $submission->contact?->full_name
            ?: ($data['personal']['full_name'] ?? null)
            ?: ($data['entity']['company_name'] ?? $data['entity']['trust_name'] ?? $data['entity']['partnership_name'] ?? null)
            ?: 'Client';

        $date = $submission->co_verified_at?->format('Y-m-d')
            ?? $submission->agent_verified_at?->format('Y-m-d')
            ?? $submission->created_at->format('Y-m-d');

        $safeName = Str::slug($name, '-') ?: 'client';

        return "FICA-Completion-Report-{$safeName}-{$date}.pdf";
    }

    // ══════════════════════════════════════════════════════════════
    // Puppeteer invocation — follows PayslipPdfService's pattern exactly
    // ══════════════════════════════════════════════════════════════

    private function invokePuppeteer(string $htmlPath, string $pdfPath, int $submissionId): void
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

        $nodeArg = escapeshellarg(str_replace('\\', '/', $nodePath));
        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg = escapeshellarg(str_replace('\\', '/', $htmlPath));
        $outArg = escapeshellarg(str_replace('\\', '/', $pdfPath));

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
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'fica_completion_pdf_' . $submissionId . '.log';

        Log::info('FICA completion report PDF generation starting', ['submission_id' => $submissionId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (! file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            Log::error('FICA completion report PDF not generated', [
                'submission_id' => $submissionId,
                'log' => substr($logContent, 0, 500),
            ]);
            throw new \RuntimeException(
                'PDF generation failed for FICA completion report ' . $submissionId . '. '
                . ($logContent ? 'Script output: ' . substr($logContent, 0, 200) : 'No output from script.')
            );
        }

        Log::info('FICA completion report PDF complete', [
            'submission_id' => $submissionId,
            'path' => $normalizedOutput,
            'size' => filesize($normalizedOutput),
        ]);
    }
}
