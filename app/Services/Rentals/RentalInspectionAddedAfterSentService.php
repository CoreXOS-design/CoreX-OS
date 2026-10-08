<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-inspections.md §49 — Johan, 8 Oct 2026: once a report has been sent it is locked, but two kinds of
 * record may still be added to it — a tenant's fault report filed inside the fault-report window, and the agent's
 * move-out comparison findings. Each is shown and printed CLEARLY MARKED "Added after the report was sent", with the
 * date and who, in a block of its own, apart from the locked report body.
 *
 * This is the ONE builder every surface reads (the inspection page, the PDF, the public report link and a party's
 * signing link), so they can never disagree. Fresh scope-free queries throughout: the public pages have no agency
 * context, and the token / the bound inspection is the authority.
 *
 * What counts as "added after sent":
 *  - a tenant fault report: an observation with source `tenant_fault_report` created at or after the moment the
 *    inspection was completed (RentalInspection::isAddedAfterSent());
 *  - a move-out finding: a LIVE (not superseded) finding on a completed Out whose record time is at or after the
 *    completion. A finding recorded before completion, or one since corrected before completion, is not part of this block.
 *
 * Nothing here is ever shown for an inspection that has not been completed.
 */
class RentalInspectionAddedAfterSentService
{
    public const MARK = 'Added after the report was sent';

    /**
     * @return Collection<int, array{kind: string, kind_label: string, room: ?string, item: string, headline: string, note: ?string, at: \Illuminate\Support\Carbon, by: string, photos: Collection}>
     */
    public function entriesFor(RentalInspection $inspection): Collection
    {
        if ($inspection->status !== RentalInspection::STATUS_COMPLETED || ! $inspection->completed_at) {
            return collect();
        }

        $agencyId = $inspection->agency_id;
        $conditionLabels = collect(RentalInspectionSetting::conditionStatesFor($agencyId))->pluck('label', 'key');
        $dispositionLabels = RentalInspectionSetting::dispositionLabelsFor($agencyId);

        $items = RentalInspectionItem::withoutGlobalScopes()
            ->where('property_id', $inspection->property_id)
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->keyBy('id');
        // The wording the report was sent with (an item added later simply keeps its own label).
        $inspection->applyWordingSnapshot($items);

        $entries = collect();

        $observations = RentalInspectionObservation::withoutGlobalScopes()
            ->where('rental_inspection_id', $inspection->id)
            ->where('source', RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT)
            ->where('condition', '!=', RentalInspectionObservation::CONDITION_PENDING)
            ->where('created_at', '>=', $inspection->completed_at)
            ->orderBy('id')
            ->get();
        $photosByObservation = $observations->isEmpty() ? collect() : RentalInspectionPhoto::withoutGlobalScopes()
            ->whereIn('rental_inspection_observation_id', $observations->pluck('id'))
            ->whereNull('deleted_at')
            ->orderBy('id')->get()->groupBy('rental_inspection_observation_id');

        foreach ($observations as $o) {
            $item = $items->get($o->rental_inspection_item_id);
            $entries->push([
                'kind' => 'fault_report',
                'kind_label' => 'Tenant fault report',
                'room' => $item?->room?->label,
                'item' => $item?->label ?? 'Unknown item',
                'headline' => $conditionLabels->get($o->condition) ?? ucfirst(str_replace('_', ' ', (string) $o->condition)),
                'note' => $o->notes,
                'at' => $o->created_at,
                'by' => $this->who($o),
                'photos' => $photosByObservation->get($o->id) ?? collect(),
            ]);
        }

        if ($inspection->type === RentalInspection::TYPE_OUT) {
            $findings = RentalInspectionItemFinding::withoutGlobalScopes()
                ->where('rental_inspection_id', $inspection->id)
                ->whereNull('superseded_at')
                ->where('recorded_at', '>=', $inspection->completed_at)
                ->orderBy('id')
                ->get();
            $users = User::withoutGlobalScopes()->whereIn('id', $findings->pluck('recorded_by_user_id')->filter())->get()->keyBy('id');

            foreach ($findings as $f) {
                $item = $items->get($f->rental_inspection_item_id);
                $entries->push([
                    'kind' => 'move_out_finding',
                    'kind_label' => 'Move-out finding',
                    'room' => $item?->room?->label,
                    'item' => $item?->label ?? 'Unknown item',
                    'headline' => $dispositionLabels[$f->disposition] ?? ucfirst(str_replace('_', ' ', (string) $f->disposition)),
                    'note' => $f->note,
                    'at' => $f->recorded_at,
                    'by' => $users->get($f->recorded_by_user_id)?->name ?? 'Unknown',
                    'photos' => collect(),
                ]);
            }
        }

        return $entries->sortBy(fn (array $e) => $e['at']?->getTimestamp() ?? 0)->values();
    }

    /** Who made the entry: the tenant who reported it when the record names one, otherwise the agent who recorded it. */
    private function who(RentalInspectionObservation $o): string
    {
        if ($o->observed_by_contact_id) {
            $contact = Contact::withoutGlobalScopes()->find($o->observed_by_contact_id);
            if ($contact?->full_name) {
                return $contact->full_name;
            }
        }
        $user = $o->observed_by_user_id ? User::withoutGlobalScopes()->find($o->observed_by_user_id) : null;

        return $user ? ($user->name . ' (recorded for the tenant)') : 'Unknown';
    }
}
