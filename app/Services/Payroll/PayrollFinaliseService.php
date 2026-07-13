<?php

namespace App\Services\Payroll;

use App\Models\Document;
use App\Models\Payroll\PayrollRun;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollFinaliseService
{
    /**
     * Finalise a payroll run: validate, generate PDFs, create documents, lock run.
     *
     * @return array{success: bool, errors: array, warnings: array, payslip_count: int}
     */
    public function finalise(PayrollRun $run, User $finalisedBy): array
    {
        $errors = [];
        $warnings = [];

        // ── Pre-checks ──

        if (!$run->isDraft()) {
            return ['success' => false, 'errors' => ["Run is {$run->status}, not draft."], 'warnings' => [], 'payslip_count' => 0];
        }

        $run->loadMissing('payslips.lines');

        foreach ($run->payslips as $payslip) {
            if (bccomp((string) $payslip->net_pay, '0', 2) < 0) {
                $errors[] = "{$payslip->employee_name_snapshot} has negative net pay (R " . number_format($payslip->net_pay, 2) . "). Resolve before finalising.";
            }

            $earningCount = $payslip->lines->where('line_type', 'earning')->count();
            if ($earningCount === 0) {
                $errors[] = "{$payslip->employee_name_snapshot} has no earning lines.";
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'warnings' => [], 'payslip_count' => 0];
        }

        // ── Generate PDFs + create documents inside transaction ──

        $pdfService = new PayslipPdfService();
        $payslipCount = 0;

        try {
            DB::transaction(function () use ($run, $finalisedBy, $pdfService, &$payslipCount, &$warnings) {
                // AT-237 D2 — pessimistic lock + re-check UNDER the lock. Two concurrent
                // finalises (double-click, retry) would otherwise both proceed and
                // double-file Documents / UserDocuments / PDFs. The second waits here,
                // then sees a non-draft run and aborts cleanly (transaction rolls back).
                $locked = PayrollRun::withoutGlobalScopes()->where('id', $run->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->isDraft()) {
                    throw new \RuntimeException('CONCURRENT_FINALISE');
                }
                $run->update([
                    'status'       => 'finalised',
                    'finalised_at' => now(),
                    'finalised_by' => $finalisedBy->id,
                ]);

                foreach ($run->payslips as $payslip) {
                    // Generate PDF (run is now finalised → no PREVIEW watermark)
                    $pdfPath = $pdfService->regenerate($payslip);

                    $fileSize = file_exists($pdfPath) ? filesize($pdfPath) : 0;
                    $lastName = last(explode(' ', $payslip->employee_name_snapshot));
                    $periodYm = $payslip->period_month->format('Ym');
                    $originalName = "Payslip-{$lastName}-{$periodYm}.pdf";

                    // Relative storage path for the Document row
                    $normalizedPdf = str_replace('\\', '/', $pdfPath);
                    $normalizedBase = str_replace('\\', '/', storage_path('app')) . '/';
                    $storagePath = str_replace($normalizedBase, '', $normalizedPdf);

                    // Create Document row
                    $document = Document::create([
                        'agency_id'        => $run->agency_id,
                        'branch_id'        => $payslip->branch_id,
                        'original_name'    => $originalName,
                        'storage_path'     => $storagePath,
                        'disk'             => 'local',
                        'mime_type'        => 'application/pdf',
                        'size'             => $fileSize,
                        'source_type'      => 'payroll',
                        'source_id'        => $payslip->id,
                        'uploaded_by'      => $finalisedBy->id,
                    ]);

                    // Update payslip with document link
                    $payslip->update([
                        'document_id'      => $document->id,
                        'pdf_generated_at' => now(),
                    ]);

                    // Auto-file to user_documents
                    try {
                        UserDocument::create([
                            'agency_id'          => $run->agency_id,
                            'user_id'            => $payslip->user_id,
                            'document_type'      => 'payslip',
                            'file_path'          => $storagePath,
                            'file_name'          => $originalName,
                            'file_size'          => $fileSize,
                            'mime_type'          => 'application/pdf',
                            'status'             => 'verified',
                            'verified_by'        => $finalisedBy->id,
                            'verified_at'        => now(),
                            'uploaded_by'        => $finalisedBy->id,
                            'uploaded_by_admin'  => true,
                            'admin_upload_reason' => "Auto-filed from payroll run {$run->run_number}",
                        ]);
                    } catch (\Throwable $e) {
                        // Log but don't fail the whole finalise for a filing error
                        $warnings[] = "Failed to auto-file payslip for {$payslip->employee_name_snapshot}: {$e->getMessage()}";
                        Log::warning('Payslip auto-file failed', [
                            'payslip_id' => $payslip->id,
                            'user_id'    => $payslip->user_id,
                            'error'      => $e->getMessage(),
                        ]);
                    }

                    $payslipCount++;
                }

                // Recompute and cache run totals
                $this->cacheRunTotals($run);
            });
        } catch (\Throwable $e) {
            // AT-237 D2 — the lock-loser aborts cleanly with a plain message (no duplicate filing).
            if ($e->getMessage() === 'CONCURRENT_FINALISE') {
                return [
                    'success'       => false,
                    'errors'        => ['This run is already being finalised (or is no longer a draft). Refresh to see its status.'],
                    'warnings'      => $warnings,
                    'payslip_count' => 0,
                ];
            }
            Log::error('Payroll finalise failed', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);

            // Transaction rolled back — run is still draft
            return [
                'success'      => false,
                'errors'       => ["Finalise failed: {$e->getMessage()}"],
                'warnings'     => $warnings,
                'payslip_count' => 0,
            ];
        }

        return [
            'success'      => true,
            'errors'       => [],
            'warnings'     => $warnings,
            'payslip_count' => $payslipCount,
        ];
    }

    private function cacheRunTotals(PayrollRun $run): void
    {
        $payslips = $run->payslips()->get();

        $totalGross = '0.00';
        $totalPaye = '0.00';
        $totalUifEmployee = '0.00';
        $totalUifEmployer = '0.00';
        $totalSdl = '0.00';
        $totalNet = '0.00';

        foreach ($payslips as $ps) {
            $totalGross = bcadd($totalGross, (string) $ps->total_earnings, 2);
            $totalPaye = bcadd($totalPaye, (string) $ps->paye_amount, 2);
            $totalUifEmployee = bcadd($totalUifEmployee, (string) $ps->uif_employee_amount, 2);
            $totalUifEmployer = bcadd($totalUifEmployer, (string) $ps->uif_employer_amount, 2);
            $totalSdl = bcadd($totalSdl, (string) $ps->sdl_amount, 2);
            $totalNet = bcadd($totalNet, (string) $ps->net_pay, 2);
        }

        $run->update([
            'payslip_count'      => $payslips->count(),
            'total_gross'        => $totalGross,
            'total_paye'         => $totalPaye,
            'total_uif_employee' => $totalUifEmployee,
            'total_uif_employer' => $totalUifEmployer,
            'total_sdl'          => $totalSdl,
            'total_net'          => $totalNet,
        ]);
    }
}
