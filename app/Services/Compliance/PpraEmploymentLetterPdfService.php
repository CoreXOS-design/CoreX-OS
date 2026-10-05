<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use App\Services\Compliance\Concerns\GeneratesPdfViaPuppeteer;
use Carbon\Carbon;

/**
 * PPRA FFC renewal — Confirmation of Employment letter PDF.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * Reuses the PPRA Inspection Pack letterhead header/footer Blade pattern
 * (resources/views/admin/ppra-inspection-pack/letterhead-sample.blade.php)
 * and the same GeneratesPdfViaPuppeteer trait every other PPRA Inspection
 * Pack PDF uses — this is a sibling PDF generator in the same module, not a
 * new letterhead renderer (per the 2026-10-05 investigation's pointer).
 *
 * Unsigned stages render a live preview with EMPTY signature lines (never
 * blank merge-field placeholders — every merge field is validated present
 * before a letter can even be created). The signed PDF, once baked by
 * PpraEmploymentLetterService::signAsPrincipal(), is stored and never
 * regenerated — generate() here is only ever called for the unsigned
 * preview and for the one-time final bake.
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
        $designation = trim((string) $agent->designation) !== '' ? $agent->designation : 'Property Practitioner';

        $branch = $letter->branch_id ? $letter->branch()->first() : null;
        $agencyPpraNumber = $branch?->ppra_number ?: ($agency->ppra_number ?? null);

        [$principalFirstName, $principalSurname] = $this->splitName($principal?->name);

        $html = view('compliance.ppra-employment-letters.pdf', [
            'agency'                   => $agency,
            'agent'                    => $agent,
            'principal'                => $principal,
            'designation'              => $designation,
            'agentIdNumber'            => $agent->id_number ?: '—',
            'agentFfcNumber'           => $agent->ffc_number ?: '—',
            'agencyLegalName'          => $agency->name,
            'agencyTradingName'        => $agency->trading_name ?: $agency->name,
            'agencyPpraNumber'         => $agencyPpraNumber ?: '—',
            'principalFirstName'       => $principalFirstName ?: '—',
            'principalSurname'         => $principalSurname ?: '—',
            'principalFfcNumber'       => $principal?->ffc_number ?: '—',
            'ppraAddressBlock'         => $agency->ppra_employment_letter_address_block
                ?: PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK,
            'letterDate'               => $this->letterDate($letter),
            'agentSignatureImage'      => $agentSignatureImage,
            'principalSignatureImage'  => $principalSignatureImage,
            'agentSignedAt'            => $letter->agent_signed_at?->format('d F Y, H:i'),
            'principalSignedAt'        => $letter->principal_signed_at?->format('d F Y, H:i'),
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
     * The letter's dateline: the agent's own signing date once they've
     * signed (frozen from that point on, including after the principal
     * signs), otherwise today — never blank.
     */
    private function letterDate(PpraEmploymentLetter $letter): string
    {
        $date = $letter->agent_signed_at ?? Carbon::now();

        return $date->format('d F Y');
    }

    /** @return array{0:?string,1:?string} [firstName, surname] */
    private function splitName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $fullName);
        $first = array_shift($parts);
        $surname = $parts !== [] ? implode(' ', $parts) : null;

        return [$first, $surname];
    }
}
