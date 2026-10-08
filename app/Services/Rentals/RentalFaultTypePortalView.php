<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeDocument;
use App\Models\RentalPortalSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rentals-faults-work-orders.md §2/§3.2/§4.3 + rental-portal-access.md §21 — everything the portal needs to
 * show a person once they pick a fault type, BEFORE they can report it: the first-aid steps worked out for THIS property,
 * the urgency, the agency's own uploaded images / documents / video links for that fault type, and the property's own
 * recorded main-water-valve and DB-board photos. The tenant picker and the owner picker both read this one view, so the
 * two can never show different things (the portal used to return the steps text only — the documents, video links and
 * valve photos the agent screen shows never reached the tenant).
 */
class RentalFaultTypePortalView
{
    public function __construct(private readonly RentalFaultTypeService $renderer)
    {
    }

    /**
     * @param  Collection<int,RentalFaultType>  $types
     * @return array<int,array<string,mixed>>
     */
    public function payload(Collection $types, Property $property): array
    {
        $valve = Property::publicImageUrl($property->rental_main_water_valve_photo_path);
        $board = Property::publicImageUrl($property->rental_db_board_photo_path);
        $docs = RentalFaultTypeDocument::withoutGlobalScopes()
            ->where('agency_id', $property->agency_id)
            ->whereIn('rental_fault_type_id', $types->pluck('id'))
            ->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('rental_fault_type_id');

        return $types->map(fn (RentalFaultType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'category' => $type->category,
            'urgency' => $type->urgency,
            'first_aid_steps' => $this->renderer->renderFirstAidSteps($type, $property),
            // Where the valve / board are — only when the agent recorded a photo for this property.
            'photos' => array_values(array_filter([
                $valve ? ['label' => 'Main water valve', 'url' => $valve] : null,
                $board ? ['label' => 'DB board', 'url' => $board] : null,
            ])),
            'documents' => ($docs->get($type->id) ?? collect())->map(fn ($d) => $this->document($d))->filter()->values()->all(),
        ])->values()->all();
    }

    /** @return array{max_photos:int,max_photo_mb:int} the agency's fault-photo limits (the page checks them before sending) */
    public function limits(int $agencyId): array
    {
        return [
            'max_photos' => RentalPortalSetting::faultPhotoMaxCountFor($agencyId),
            'max_photo_mb' => RentalPortalSetting::faultPhotoMaxMbFor($agencyId),
        ];
    }

    /** @return array{type:string,caption:?string,url:string}|null */
    private function document(RentalFaultTypeDocument $d): ?array
    {
        $url = null;
        if ($d->document_type === RentalFaultTypeDocument::TYPE_VIDEO_LINK) {
            $link = trim((string) $d->external_url);
            $url = preg_match('#^https?://#i', $link) ? $link : null; // never a javascript: / data: link on a tenant's phone
        } elseif ($d->storage_path) {
            $url = $d->document_type === RentalFaultTypeDocument::TYPE_IMAGE
                ? asset('storage/' . ltrim($d->storage_path, '/'))                    // images live on the public disk (RentalFaultTypeController::storeDocuments)
                : route('client.rentals.fault-type-documents.file', ['document' => $d->id]); // PDFs / documents are private: the portal's own gated download
        }

        return $url ? ['type' => $d->document_type, 'caption' => $d->caption, 'url' => $url] : null;
    }

    /** Is this stored (private) document one the portal may hand to a person of this agency? */
    public function privateDocumentFor(int $agencyId, int $documentId): ?RentalFaultTypeDocument
    {
        $doc = RentalFaultTypeDocument::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->find($documentId);
        if (!$doc || !$doc->storage_path || $doc->document_type === RentalFaultTypeDocument::TYPE_IMAGE) {
            return null;
        }
        $typeActive = RentalFaultType::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('is_active', true)->whereNull('deleted_at')->whereKey($doc->rental_fault_type_id)->exists();

        return $typeActive && Storage::disk('local')->exists($doc->storage_path) ? $doc : null;
    }
}
