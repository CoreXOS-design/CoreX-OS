<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use App\Services\Compliance\Concerns\GeneratesPdfViaPuppeteer;
use Carbon\Carbon;

/**
 * PPRA FFC renewal — Confirmation of Employment letter PDF.
 * .ai/specs/ppra-ffc-employment-letter.md (§6, §18)
 *
 * Reproduces the agency's Word "letter of employment" (HFC's, 5 Oct 2026) exactly. The letterhead is NOT
 * built here: it is the shared company-header component the agency's Company Settings drive and the
 * e-sign documents render (docuperfect.web-templates.components.company-header), with the branch's
 * overrides when the letter belongs to a branch. Same GeneratesPdfViaPuppeteer trait as every other
 * PPRA PDF.
 *
 * The letter is printed for wet-ink signing (spec §20): the callers pass NO signature images, so the signature
 * lines are always EMPTY (never blank merge-field placeholders — every merge field is validated present before a
 * letter can even be created). A legacy PIN-signed letter keeps the PDF baked at the time (signed_pdf_path), which
 * is streamed as-is and never regenerated.
 */
class PpraEmploymentLetterPdfService
{
    use GeneratesPdfViaPuppeteer;

    /**
     * @param string|null $agentSignatureImage data:image/... URI, or null for an empty line.
     * @param string|null $principalSignatureImage data:image/... URI, or null for an empty line.
     */
    public function generate(
        PpraEmploymentLetter $letter,
        User $agent,
        ?User $principal,
        Agency $agency,
        ?string $agentSignatureImage,
        ?string $principalSignatureImage,
    ): string {
        $branch = $letter->branch_id ? $letter->branch()->first() : null;
        $agencyPpraNumber = $branch?->ppra_number ?: ($agency->ppra_number ?? null);

        $html = view('compliance.ppra-employment-letters.pdf', [
            'agency'                   => $agency,
            'branch'                   => $branch,
            'logoData'                 => $this->logoDataUri($branch, $agency),
            'agent'                    => $agent,
            'principal'                => $principal,
            'ppraCategory'             => strtoupper($agent->ppraCategoryLabel()),
            'ppraCategoryLabel'        => $agent->ppraCategoryLabel(),
            'agentFirstNames'          => $agent->letterFirstNames(),
            'agentSurname'             => $agent->letterSurname(),
            'agentIdNumber'            => $agent->id_number ?: '—',
            'agentFfcNumber'           => $agent->ffc_number ?: '—',
            'companyLine'              => $this->companyLine($agency),
            'agencyPpraNumber'         => $agencyPpraNumber ?: '—',
            'principalFirstNames'      => $principal?->letterFirstNames() ?: '—',
            'principalSurname'         => $principal?->letterSurname() ?: '—',
            'principalFfcNumber'       => $principal?->ffc_number ?: '—',
            'ppraAddressBlock'         => $agency->ppra_employment_letter_address_block
                ?: PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK,
            'letterDate'               => $this->letterDate($letter),
            'agentSignatureImage'      => $agentSignatureImage,
            'principalSignatureImage'  => $principalSignatureImage,
        ])->render();

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $htmlPath = $tempDir . '/ppra-employment-letter-' . $letter->id . '-' . uniqid() . '.html';
        file_put_contents($htmlPath, $html);

        $pdfDir = storage_path('app/ppra-employment-letters/' . $agency->id);
        if (! is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $pdfPath = $pdfDir . '/letter-' . $letter->id . '-' . now()->format('Ymd-His') . '-' . uniqid() . '.pdf';

        $this->invokePuppeteer($htmlPath, $pdfPath, 'ppra-employment-letter-' . $letter->id);

        @unlink($htmlPath);

        return $pdfPath;
    }

    /**
     * The letter's dateline: the day the letter was CREATED, on every print (wet-ink flow — a reprint on another
     * day must not change the date). "5 October 2026" (no leading zero).
     */
    private function letterDate(PpraEmploymentLetter $letter): string
    {
        // Wet-ink flow (spec §20): fixed to the day the letter was created — never the day it happens to be printed.
        $date = $letter->created_at ?? Carbon::now();

        return $date->format('j F Y');
    }

    /**
     * "{registered/legal name} t/a {trading name}" — legal name is agencies.name, trading name is
     * agencies.trading_name (Company Settings → Trading Name). Just the legal name when there is no
     * distinct trading name, so an agency that trades under its own name reads naturally.
     */
    private function companyLine(Agency $agency): string
    {
        $legal   = trim((string) $agency->name);
        $trading = trim((string) $agency->trading_name);

        return ($trading !== '' && strcasecmp($trading, $legal) !== 0) ? $legal . ' t/a ' . $trading : $legal;
    }

    /**
     * The letterhead component renders an asset() URL for the logo, which a file:// Chromium page cannot
     * fetch — so hand it the same branch-then-agency logo (when the file really exists) as a data: URI.
     */
    private function logoDataUri(?Branch $branch, Agency $agency): ?string
    {
        foreach ([$branch?->logo_path, $agency->logo_path] as $path) {
            if (! $path) {
                continue;
            }
            $abs = storage_path('app/public/' . ltrim($path, '/'));
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif']
                [strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? null;
            if ($mime && is_file($abs)) {
                return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs));
            }
        }

        return null;
    }
}
