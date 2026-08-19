<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Property;
use App\Models\Rental\RentalProperty;

/**
 * AT-373-adjacent / e-sign recipient supporting docs — Part B (step 1).
 *
 * Resolves the KNOWN property / address prefill for a recipient's supporting-document BATCH, to
 * hand to Andre's multi-doc splitter so the agent never re-types what CoreX already knows. The
 * uploads belong to a signing request → e-sign Document, which the e-sign wizard stamped with the
 * chosen property (property_id = main Property pillar when the flow carried one, else a
 * RentalProperty) + a property_address string.
 *
 * Returns NULL cleanly when nothing is known — the splitter then lets the agent enter it manually
 * (Johan's fallback). The output shape is the stable prefill contract the hand-off (step 2) passes.
 */
class SupportingBatchPrefillResolver
{
    /**
     * @return array{property_id:?int, property_source:?string, address:?string, address_parts:array}|null
     */
    public function forSigningRequest(SignatureRequest $request): ?array
    {
        $document = $request->template?->document;

        return $document ? $this->forDocument($document) : null;
    }

    /**
     * @return array{property_id:?int, property_source:?string, address:?string, address_parts:array}|null
     */
    public function forDocument(Document $document): ?array
    {
        $propertyId = $document->property_id ? (int) $document->property_id : null;
        $address    = trim((string) ($document->property_address ?? '')) ?: null;

        $source = null;
        $parts  = [];

        if ($propertyId !== null) {
            // property_id is the main Property pillar when the wizard used flow->property_id, else a
            // RentalProperty (the two id-spaces are distinct, so resolve by trying the pillar first).
            if ($p = Property::withoutGlobalScopes()->whereNull('deleted_at')->find($propertyId)) {
                $source = 'properties';
                $parts  = [
                    'street_number' => $p->street_number ?: null,
                    'street_name'   => $p->street_name ?: null,
                    'suburb'        => $p->suburb ?: null,
                    'city'          => $p->city ?: ($p->town ?: null),
                    'province'      => $p->province ?: null,
                    'postal_code'   => $p->postal_code ?: null,
                ];
                // Prefer a stored address; else compose one from the pillar's parts.
                if ($address === null) {
                    $line = trim(implode(' ', array_filter([$p->street_number, $p->street_name])));
                    $address = trim($line . ($p->suburb ? ', ' . $p->suburb : '')) ?: null;
                }
            } elseif ($rp = RentalProperty::withoutGlobalScopes()->find($propertyId)) {
                $source = 'rental_properties';
                $parts  = [
                    'street_name' => $rp->address_line_1 ?: null,
                    'suburb'      => $rp->suburb ?: null,
                    'city'        => $rp->city ?: null,
                    'province'    => $rp->province ?: null,
                    'postal_code' => $rp->postal_code ?: null,
                ];
                if ($address === null) {
                    $address = ($rp->full_address ?: trim(implode(', ', array_filter([$rp->address_line_1, $rp->suburb])))) ?: null;
                }
            }
        }

        // Nothing known — the splitter falls back to manual entry.
        if ($propertyId === null && $address === null) {
            return null;
        }

        return [
            'property_id'     => $propertyId,
            'property_source' => $source,
            'address'         => $address,
            'address_parts'   => array_filter($parts, fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
