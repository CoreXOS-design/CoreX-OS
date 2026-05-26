<?php

namespace App\Services\Payroll;

use App\Models\Payroll\PayrollPayslip;
use Illuminate\Support\Facades\Log;

class PayslipPdfService
{
    /**
     * Generate a PDF for the given payslip. Returns absolute path to the PDF file.
     */
    public function generate(PayrollPayslip $payslip): string
    {
        $payslip->loadMissing([
            'lines', 'employee.user.bankingDetail', 'run',
        ]);

        // Load agency directly (BelongsToAgency uses agency_id column)
        $agency = \App\Models\Agency::find($payslip->agency_id);

        $banking = $payslip->employee?->user?->bankingDetail;

        // Verification hash: SHA256 of payslip_number|net_pay|finalised_at|agency_id
        $hashInput = implode('|', [
            $payslip->payslip_number,
            $payslip->net_pay,
            $payslip->run->finalised_at ?? 'preview',
            $payslip->agency_id,
        ]);
        $verificationHash = substr(hash('sha256', $hashInput), 0, 16);

        // Gather leave balances for PDF footer
        $leaveBalances = $this->getLeaveBalancesForPdf($payslip);

        // Render HTML
        $html = view('payroll.pdf.payslip', compact('payslip', 'agency', 'banking', 'verificationHash', 'leaveBalances'))->render();

        // Temp HTML path
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/payslip-' . $payslip->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        // Output PDF path
        $taxYear = $payslip->period_month->month >= 3
            ? $payslip->period_month->year . '-' . ($payslip->period_month->year + 1)
            : ($payslip->period_month->year - 1) . '-' . $payslip->period_month->year;
        $periodYm = $payslip->period_month->format('Ym');
        $pdfDir = storage_path("app/payslips/{$taxYear}/{$periodYm}");
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/' . $payslip->payslip_number . '.pdf';

        // Generate via Puppeteer
        $this->invokePuppeteer($htmlPath, $pdfPath, $payslip->id);

        // Clean up temp HTML
        @unlink($htmlPath);

        return $pdfPath;
    }

    /**
     * Regenerate — overwrites existing PDF if present.
     */
    public function regenerate(PayrollPayslip $payslip): string
    {
        $existing = $this->getStoredPath($payslip);
        if ($existing && file_exists($existing)) {
            @unlink($existing);
        }

        return $this->generate($payslip);
    }

    /**
     * Returns absolute path if PDF file exists on disk, else null.
     */
    public function getStoredPath(PayrollPayslip $payslip): ?string
    {
        $payslip->loadMissing('run');

        $taxYear = $payslip->period_month->month >= 3
            ? $payslip->period_month->year . '-' . ($payslip->period_month->year + 1)
            : ($payslip->period_month->year - 1) . '-' . $payslip->period_month->year;
        $periodYm = $payslip->period_month->format('Ym');
        $path = storage_path("app/payslips/{$taxYear}/{$periodYm}/{$payslip->payslip_number}.pdf");

        return file_exists($path) ? $path : null;
    }

    /**
     * Returns a streamed inline response to preview the PDF in browser.
     */
    public function getInlineResponse(PayrollPayslip $payslip, string $pdfPath): \Symfony\Component\HttpFoundation\Response
    {
        $payslip->loadMissing('employee.user');
        $lastName = last(explode(' ', $payslip->employee_name_snapshot ?? 'Employee'));
        $periodYm = $payslip->period_month->format('Ym');
        $filename = "Payslip-{$lastName}-{$periodYm}.pdf";

        return response()->file($pdfPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Returns a download response (Content-Disposition: attachment).
     */
    public function getDownloadResponse(PayrollPayslip $payslip, string $pdfPath): \Symfony\Component\HttpFoundation\Response
    {
        $payslip->loadMissing('employee.user');
        $lastName = last(explode(' ', $payslip->employee_name_snapshot ?? 'Employee'));
        $periodYm = $payslip->period_month->format('Ym');
        $filename = "Payslip-{$lastName}-{$periodYm}.pdf";

        return response()->download($pdfPath, $filename);
    }

    /**
     * Get filtered leave balances for the payslip PDF footer.
     * Only types with non-zero entitlement, hiding unpaid/special/parental(unless used).
     */
    private function getLeaveBalancesForPdf(PayrollPayslip $payslip): array
    {
        if (!class_exists(\App\Services\Leave\LeaveBalanceService::class)) {
            return [];
        }

        try {
            $employee = $payslip->employee;
            if (!$employee) return [];

            $balanceService = new \App\Services\Leave\LeaveBalanceService();
            $allBalances = $balanceService->getAllBalancesForEmployee($employee);

            $filtered = [];
            foreach ($allBalances as $bal) {
                $type = $bal['leave_type'];
                $entitlement = (float) $bal['entitlement_days'];

                // Skip types with zero entitlement (unpaid, special, study with 0)
                if ($entitlement <= 0) continue;

                // Skip parental unless employee has used some
                if ($type->category === 'parental' && (float) $bal['taken_days'] <= 0) continue;

                $label = $type->label;
                if ($type->cycle_months == 36) {
                    $label .= ' (3-yr cycle)';
                }

                $filtered[] = [
                    'label'       => $label,
                    'entitlement' => $bal['entitlement_days'],
                    'taken'       => $bal['taken_days'],
                    'pending'     => $bal['pending_days'],
                    'available'   => $bal['available_days'],
                ];
            }

            return $filtered;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Leave balance PDF footer failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Puppeteer invocation — follows RMCP receipt pattern exactly
    // ══════════════════════════════════════════════════════════════

    private function invokePuppeteer(string $htmlPath, string $pdfPath, int $payslipId): void
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
        if (!$isWindows) {
            $envPrefix = 'HOME=/tmp';
            if ($browserPath) {
                $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
            }
            $envPrefix .= ' ';
        }

        $command = sprintf('%s%s %s %s %s', $envPrefix, $nodeArg, $scriptArg, $htmlArg, $outArg);

        $tempDir = storage_path('app/temp');
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'payslip_pdf_' . $payslipId . '.log';

        Log::info('Payslip PDF generation starting', ['payslip_id' => $payslipId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (!file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            Log::error('Payslip PDF not generated', [
                'payslip_id' => $payslipId,
                'log'        => substr($logContent, 0, 500),
            ]);
            throw new \RuntimeException(
                'PDF generation failed for payslip ' . $payslipId . '. '
                . ($logContent ? 'Script output: ' . substr($logContent, 0, 200) : 'No output from script.')
            );
        }

        Log::info('Payslip PDF complete', [
            'payslip_id' => $payslipId,
            'path'       => $normalizedOutput,
            'size'       => filesize($normalizedOutput),
        ]);
    }
}
