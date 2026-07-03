<?php

namespace App\Services\DealV2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-158 DR2 · WS4 (§8.3) — the hand-filled-form killer.
 *
 * Generates a COC-request document from deal + property + contact data (no
 * manual entry), renders it to a PDF via dompdf, and files it as a unified
 * Document of type `coc_request` anchored to the deal (+ property + contacts,
 * via DealDocumentService). Returns the filed Document, ready to distribute.
 */
class CocRequestGenerator
{
    public function __construct(private DealDocumentService $dealDocumentService)
    {
    }

    /** Human labels per COC specialty (agency-neutral, plain English). */
    private const SPECIALTY_LABELS = [
        'electrician'    => 'Electrical Certificate of Compliance',
        'electrician_coc' => 'Electrical Certificate of Compliance',
        'entomologist'   => 'Entomologist (Beetle) Certificate',
        'plumber'        => 'Plumbing Certificate of Compliance',
        'gas'            => 'Gas Certificate of Conformity',
        'electric_fence' => 'Electric Fence Certificate of Compliance',
        'water'          => 'Water Installation Certificate of Compliance',
    ];

    public function specialtyLabel(string $specialty): string
    {
        return self::SPECIALTY_LABELS[$specialty] ?? (Str::headline($specialty) . ' Certificate of Compliance');
    }

    /**
     * Generate + file the COC-request Document. $specialty selects the label;
     * $provider (optional) is the addressed service provider.
     */
    public function generate(DealV2 $deal, string $specialty, ?AgencyServiceProvider $provider, User $actor): Document
    {
        $deal->loadMissing(['property', 'contacts', 'listingAgent']);
        $property = $deal->property;

        $sellers = $deal->contacts->filter(
            fn ($c) => in_array($c->pivot->role ?? '', ['seller', 'co_seller'], true)
        );
        $ownerNames = $sellers->map(fn ($c) => $c->full_name)->filter()->implode(', ');

        $agent = $deal->listingAgent;
        $agencyName = optional(\App\Models\Agency::find($deal->agency_id))->name ?? 'Home Finders Coastal';

        $generatedAt = now();
        $label = $this->specialtyLabel($specialty);

        $html = view('documents.coc-request', [
            'deal'             => $deal,
            'property'         => $property,
            'specialtyLabel'   => $label,
            'providerName'     => $provider?->name,
            'providerCompany'  => $provider?->company,
            'providerEmail'    => $provider?->email,
            'ownerNames'       => $ownerNames,
            'agentName'        => $agent?->name,
            'agentDesignation' => $agent?->designation,
            'agentFfc'         => $agent?->ffc_number,
            'agentEmail'       => $agent?->outward_email ?? $agent?->email,
            'agentPhone'       => $agent?->phone ?? $agent?->cell ?? null,
            'agencyName'       => $agencyName,
            'generatedAt'      => $generatedAt,
        ])->render();

        $pdfBytes = $this->renderPdf($html);

        $disk = 'local';
        $path = "deals/{$deal->id}/coc-requests/" . Str::random(10) . '_coc_' . $specialty . '.pdf';
        Storage::disk($disk)->put($path, $pdfBytes);

        $typeId = DocumentType::where('slug', 'coc_request')->value('id');

        $originalName = $label . ' Request — ' . ($property->address ?? $deal->reference) . '.pdf';

        return $this->dealDocumentService->createDealDocument($deal, [
            'original_name'    => Str::limit($originalName, 250, ''),
            'storage_path'     => $path,
            'disk'             => $disk,
            'mime_type'        => 'application/pdf',
            'size'             => strlen($pdfBytes),
            'document_type_id' => $typeId,
            'source_type'      => 'coc_request',
        ], $actor);
    }

    /**
     * dompdf render. Uses the built-in DejaVu Sans (no @font-face) but still
     * points the font cache at a writable runtime dir — the staging gotcha the
     * brochure service already solved (default storage/fonts isn't writable by
     * www-data).
     */
    private function renderPdf(string $html): string
    {
        $fontDir = storage_path('app/dompdf-fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('isHtml5ParserEnabled', true);
        if (is_dir($fontDir) && is_writable($fontDir)) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }

        return $pdf->output();
    }
}
