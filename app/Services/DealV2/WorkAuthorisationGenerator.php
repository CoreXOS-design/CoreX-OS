<?php

namespace App\Services\DealV2;

use App\Models\Agency;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-229 DR2 W3 — the work-authorisation (HFC "COC request" form) generator.
 *
 * Generalises CocRequestGenerator: renders HFC's manual work-order form as a PDF
 * from deal + property + contact data (no hand-filling), and files it as the
 * catalogued `work_authorisation` Document anchored to the deal. defaultFields()
 * auto-fills every field; the agent may edit all of them before send (the caller
 * passes the edited values into generate()). The supplier is NOT stored on the
 * step — it is chosen/created at send time and addressed to its primary contact.
 */
class WorkAuthorisationGenerator
{
    public function __construct(private DealDocumentService $dealDocumentService)
    {
    }

    /** The service line on the form (Johan: COC / Pest / Gas …). */
    public const SERVICE_LABELS = [
        'coc'            => 'Electrical Certificate of Compliance (COC)',
        'electrician'    => 'Electrical Certificate of Compliance (COC)',
        'electrician_coc' => 'Electrical Certificate of Compliance (COC)',
        'pest'           => 'Pest / Entomologist (Beetle) Certificate',
        'entomologist'   => 'Pest / Entomologist (Beetle) Certificate',
        'gas'            => 'Gas Certificate of Conformity',
        'electric_fence' => 'Electric Fence Certificate of Compliance',
        'plumbing'       => 'Plumbing Certificate of Compliance',
        'water'          => 'Water Installation Certificate of Compliance',
    ];

    public const DEFAULT_NOTES = 'Please deliver the original certificates to Home Finders Coastal office.';

    public function serviceLabel(?string $serviceType): string
    {
        if (! $serviceType) {
            return 'Certificate of Compliance';
        }

        return self::SERVICE_LABELS[$serviceType] ?? (Str::headline($serviceType) . ' Certificate');
    }

    /**
     * Auto-fill every form field from the deal. All values are editable by the
     * agent before send (the caller merges any edits over these defaults).
     */
    public function defaultFields(DealV2 $deal, ?string $serviceType = null): array
    {
        $deal->loadMissing(['property', 'contacts', 'listingAgent', 'providerParties']);
        $property = $deal->property;

        $seller = $deal->sellers()->first();
        $buyer  = $deal->buyers()->first();
        $agent  = $deal->listingAgent;

        // Attorney = the deal's transfer/bond attorney provider party (firm).
        $attorney = $deal->providerParties
            ->first(fn ($p) => in_array($p->pivot->role ?? '', ['transfer_attorney', 'conveyancer', 'bond_attorney', 'attorney'], true));

        return [
            'date'             => now()->format('d F Y'),
            'service_type'     => $serviceType,
            'service_label'    => $this->serviceLabel($serviceType),
            'property_address' => $this->propertyAddress($property),
            'seller_name'      => $seller?->full_name,
            'seller_email'     => $seller?->email,
            'seller_tel'       => $seller?->phone ?? $seller?->cell ?? null,
            'purchaser_name'   => $buyer?->full_name,
            'purchaser_tel'    => $buyer?->phone ?? $buyer?->cell ?? null,
            'attorneys'        => $attorney?->name ?? $attorney?->company,
            'rep_name'         => $agent?->name,
            'rep_email'        => $agent?->outward_email ?? $agent?->email,
            'rep_tel'          => $agent?->phone ?? $agent?->cell ?? null,
            // No dedicated keys-holder field on the deal — default to the agent
            // (they usually hold/arrange keys), fully editable.
            'keys_name'        => $agent?->name,
            'keys_tel'         => $agent?->phone ?? $agent?->cell ?? null,
            // Payer is selectable/free per deal — default to the seller (typical).
            'payer'            => $seller?->full_name ? ('Seller — ' . $seller->full_name) : 'Seller',
            'notes'            => self::DEFAULT_NOTES,
        ];
    }

    /**
     * Render + file the work-authorisation Document from the (auto-filled, then
     * agent-edited) $fields. $provider (optional) is the addressed supplier.
     */
    public function generate(DealV2 $deal, array $fields, ?AgencyServiceProvider $provider, User $actor): Document
    {
        $deal->loadMissing('property');
        $property   = $deal->property;
        $agency     = Agency::find($deal->agency_id);
        $serviceLbl = $fields['service_label'] ?? $this->serviceLabel($fields['service_type'] ?? null);

        $html = view('documents.work-authorisation', [
            'deal'            => $deal,
            'agency'          => $agency,
            'company'         => $agency?->trading_name ?: ($agency?->name ?? 'Home Finders Coastal'),
            'logoData'        => $this->logoDataUri($agency),
            'fields'          => $fields,
            'serviceLabel'    => $serviceLbl,
            'providerName'    => $provider?->name,
            'providerCompany' => $provider?->company,
            'providerEmail'   => $provider?->email,
        ])->render();

        $pdfBytes = $this->renderPdf($html);

        $disk = 'local';
        $path = "deals/{$deal->id}/work-orders/" . Str::random(10) . '_work_auth_' . ($fields['service_type'] ?? 'coc') . '.pdf';
        Storage::disk($disk)->put($path, $pdfBytes);

        $typeId = DocumentType::where('slug', 'work_authorisation')->value('id');

        $originalName = $serviceLbl . ' — Work Order — ' . ($property->address ?? $deal->reference) . '.pdf';

        return $this->dealDocumentService->createDealDocument($deal, [
            'original_name'    => Str::limit($originalName, 250, ''),
            'storage_path'     => $path,
            'disk'             => $disk,
            'mime_type'        => 'application/pdf',
            'size'             => strlen($pdfBytes),
            'document_type_id' => $typeId,
            'source_type'      => 'work_authorisation',
        ], $actor);
    }

    private function propertyAddress($property): ?string
    {
        if (! $property) {
            return null;
        }
        if (method_exists($property, 'buildDisplayAddress')) {
            return $property->buildDisplayAddress();
        }

        return $property->address ?? null;
    }

    /** Inline the agency logo as a data URI (dompdf isRemoteEnabled=false). */
    private function logoDataUri(?Agency $agency): ?string
    {
        $path = $agency?->logo_path;
        if (! $path) {
            return null;
        }
        $full = storage_path('app/public/' . ltrim($path, '/'));
        if (! is_file($full) || ! is_readable($full)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            'gif'   => 'image/gif',
            default => 'image/jpeg',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($full));
    }

    /** dompdf render (writable font cache — the staging gotcha the brochure solved). */
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
