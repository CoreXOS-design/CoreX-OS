<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Document\DocumentFiledToProperty;
use App\Models\Document;
use App\Models\DocumentProperty;
use App\Models\DocumentType;
use App\Models\Property;
use App\Support\Events\SafeRaise;
use Illuminate\Support\Facades\Log;

/**
 * Raises DocumentFiledToProperty when a document of a counted type is linked to a property
 * (road-to-sold.md step 6, Johan's answer 2: an uploaded mandate and a filed e-signed mandate both count).
 *
 * Never breaks the filing: any failure is logged and swallowed.
 */
final class DocumentPropertyObserver
{
    /**
     * document_types.slug values that count: the mandate (step 6) and, since the phase 0 remainder (build log P8), the offer to
     * purchase (step 11, Johan's answer 2: a paper OTP uploaded onto the property counts the same way). An agency-editable list
     * comes with the task engine (road-to-sold.md 13.1).
     */
    public const COUNTED_TYPE_SLUGS = ['mandate', 'otp'];

    public function created(DocumentProperty $link): void
    {
        try {
            $document = Document::withoutGlobalScopes()->find($link->document_id);
            if (! $document || ! $document->document_type_id) {
                return;
            }
            $slug = DocumentType::withoutGlobalScopes()->whereKey($document->document_type_id)->value('slug');
            if (! in_array($slug, self::COUNTED_TYPE_SLUGS, true)) {
                return;
            }
            $propertyAgencyId = Property::withoutGlobalScopes()->whereKey($link->property_id)->value('agency_id');
            if ($document->agency_id && $propertyAgencyId && (int) $document->agency_id !== (int) $propertyAgencyId) {
                return; // a document and a property of two different agencies: never one agency's step ticked by another's file
            }
            $agencyId = $document->agency_id ?? $propertyAgencyId;

            SafeRaise::dispatch('DocumentFiledToProperty', fn () => new DocumentFiledToProperty(
                documentId: (int) $document->id,
                propertyId: (int) $link->property_id,
                documentTypeSlug: (string) $slug,
                agencyId: $agencyId !== null ? (int) $agencyId : null,
                actorUserId: $document->uploaded_by ? (int) $document->uploaded_by : (auth()->id() ? (int) auth()->id() : null),
                sourceType: $document->source_type ? (string) $document->source_type : null,
                branchId: $document->branch_id ? (int) $document->branch_id : null,
                dealId: $document->deal_id ? (int) $document->deal_id : null,
            ));
        } catch (\Throwable $e) {
            Log::warning('DocumentFiledToProperty hook caught exception, filing proceeds', [
                'document_id' => $link->document_id ?? null,
                'property_id' => $link->property_id ?? null,
                'message'     => $e->getMessage(),
            ]);
        }
    }
}
