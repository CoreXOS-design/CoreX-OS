<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeDocument;
use App\Models\RentalPortalSetting;
use App\Services\Images\PortalImageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rentals-faults-work-orders.md §2/§3.2/§4.3 + rental-portal-access.md §22 — everything the portal needs to
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

        return $types->map(fn (RentalFaultType $type) => $this->splitSteps($this->renderer->renderFirstAidSteps($type, $property)) + [
            'id' => $type->id,
            'name' => $type->name,
            'category' => $type->category,
            'urgency' => $type->urgency,
            'first_aid_steps' => $this->renderer->renderFirstAidSteps($type, $property),
            // Where the valve / board are — only when the agent recorded a photo for this property.
            'photos' => array_values(array_filter([
                $valve ? ['label' => 'Main water valve'] + app(PortalImageService::class)->urls($valve) : null,
                $board ? ['label' => 'DB board'] + app(PortalImageService::class)->urls($board) : null,
            ])),
            'documents' => ($docs->get($type->id) ?? collect())->map(fn ($d) => $this->document($d))->filter()->values()->all(),
        ])->values()->all();
    }

    /** AT-452 round 2 (F-OWNER) - the advice the tenant was shown for this type on this property (the steps, one sentence after another), for the owner's "Resolved with the suggested step". */
    public function adviceFor(RentalFaultType $type, \App\Models\Property $property): string
    {
        return implode(' ', $this->splitSteps($this->renderer->renderFirstAidSteps($type, $property))['steps']);
    }

    /**
     * AT-452 item 8 - the tenant screen shows the safety line in its own warning box and the steps as a list. The WORDING is untouched: the
     * rendered text is only cut into pieces (the leading safety line; then one step per line, or per sentence when the text is one block).
     * `first_aid_steps` (the whole text) stays in the payload for the owner screen and anything else that reads it.
     *
     * @return array{safety_line:?string, steps:array<int,string>}
     */
    public function splitSteps(string $rendered): array
    {
        $text = trim(str_replace("\r\n", "\n", $rendered));
        $safety = null;
        if (str_starts_with($text, RentalFaultType::SAFETY_LINE)) {
            $safety = RentalFaultType::SAFETY_LINE;
            $text = trim(substr($text, strlen(RentalFaultType::SAFETY_LINE)));
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        if (count($lines) === 1) {
            $lines = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $lines[0]) ?: $lines;
        }
        // a typed "1." / "2)" / "- " / "•" in front of a step is dropped: the list draws its own number
        $steps = array_values(array_filter(array_map(fn ($l) => trim((string) preg_replace('/^\s*(?:\d{1,2}[.)]|[-•*])\s+/u', '', $l)), $lines), fn ($l) => $l !== ''));

        return ['safety_line' => $safety, 'steps' => $steps];
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

        if (! $url) {
            return null;
        }
        // §23 - an image the agency attached is shown small in the panel and full-size only when opened.
        $pair = $d->document_type === RentalFaultTypeDocument::TYPE_IMAGE ? app(PortalImageService::class)->urls($url) : ['url' => $url];

        return ['type' => $d->document_type, 'caption' => $d->caption] + $pair;
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
