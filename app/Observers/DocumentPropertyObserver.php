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
     * document_types.slug values that count. Mandate only for now; the OTP type is added by the Sell Stock
     * phase (step 11), and an agency-editable list comes with the task engine (road-to-sold.md 13.1).
     */
    public const COUNTED_TYPE_SLUGS = ['mandate'];

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
            $agencyId = $document->agency_id
                ?? Property::withoutGlobalScopes()->whereKey($link->property_id)->value('agency_id');

            SafeRaise::dispatch('DocumentFiledToProperty', fn () => new DocumentFiledToProperty(
                documentId: (int) $document->id,
                propertyId: (int) $link->property_id,
                documentTypeSlug: (string) $slug,
                agencyId: $agencyId !== null ? (int) $agencyId : null,
                actorUserId: $document->uploaded_by ? (int) $document->uploaded_by : (auth()->id() ? (int) auth()->id() : null),
                sourceType: $document->source_type ? (string) $document->source_type : null,
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
