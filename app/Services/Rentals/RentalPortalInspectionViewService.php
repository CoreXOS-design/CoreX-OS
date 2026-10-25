<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionSignature;
use Illuminate\Support\Collection;

/**
 * AT-508 (Johan, 25 Oct 2026) - a tenant and an owner can SEE the move-in and move-out inspection in the app: room by room, the condition of each
 * item, the notes and the photos, and who has signed. Read-only, and only an inspection the portal already lists as SENT
 * (RentalPortalScopeService::inspectionPortalState) - a booked date shows as a date, never as content.
 *
 * A move-out shows, beside each item, how the same item stood at move-in (`move_in`) when that inspection was also sent.
 * Nothing internal leaves: no discrepancies, attendance, agent notes, user ids or storage paths. The TENANT payload says nothing about the lessor
 * (the signature block is `you_signed` / `agent_signed` only); the owner's also carries `tenant_signed`.
 */
class RentalPortalInspectionViewService
{
    public function __construct(private readonly RentalPortalScopeService $scope) {}

    /** @return array<string,mixed> */
    public function payload(RentalInspection $inspection, Contact $contact, string $audience): array
    {
        $inspection->loadMissing(['property']);
        $property = $inspection->property;
        $observations = $this->observations($inspection);
        $photos = RentalInspectionPhoto::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->whereNull('deleted_at')
            ->orderBy('taken_at')->orderBy('id')->get();
        $notes = RentalInspectionRoomNote::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->orderBy('id')->get()->keyBy('property_room_id');

        $moveIn = $inspection->type === RentalInspection::TYPE_OUT ? $this->sentCounterpart($inspection, $contact, RentalInspection::TYPE_IN) : null;
        $moveInByItem = $moveIn ? $this->observations($moveIn) : collect();

        $rooms = PropertyRoom::query()->where('property_id', $inspection->property_id)->orderBy('sort_order')->orderBy('id')->get();
        $items = RentalInspectionItem::query()->where('property_id', $inspection->property_id)->orderBy('sort_order')->orderBy('id')->get();

        $itemRow = function (RentalInspectionItem $item) use ($observations, $photos, $moveInByItem, $moveIn) {
            $obs = $observations->get($item->id);
            if (! $obs) {
                return null;   // not assessed in this inspection: nothing to show
            }
            $before = $moveInByItem->get($item->id);

            return [
                'id' => $item->id,
                'label' => $item->label,
                'condition' => $obs->condition,
                'notes' => $obs->notes,
                'photos' => $photos->where('rental_inspection_observation_id', $obs->id)->map(fn ($p) => $this->photo($p))->values()->all(),
            ] + ($moveIn ? ['move_in' => $before ? ['condition' => $before->condition, 'notes' => $before->notes] : null] : []);
        };

        $roomRows = $rooms->map(function (PropertyRoom $r) use ($items, $itemRow, $notes, $photos) {
            $rows = $items->where('kind', RentalInspectionItem::KIND_SPACE)->where('property_room_id', $r->id)->map($itemRow)->filter()->values();

            return [
                'id' => $r->id, 'label' => $r->label,
                'note' => $notes->get($r->id)?->note,
                'items' => $rows->all(),
                'photos' => $photos->where('property_room_id', $r->id)->whereNull('rental_inspection_observation_id')->map(fn ($p) => $this->photo($p))->values()->all(),
            ];
        })->filter(fn ($r) => $r['items'] !== [] || $r['note'] !== null || $r['photos'] !== [])->values();

        $meters = $items->where('kind', RentalInspectionItem::KIND_METER)->map($itemRow)->filter()->values();
        $other = $items->where('kind', RentalInspectionItem::KIND_SPACE)->whereNull('property_room_id')->map($itemRow)->filter()->values();

        return [
            'id' => $inspection->id,
            'type' => $inspection->type,
            'type_label' => RentalInspection::typeName((string) $inspection->type),
            'status' => $inspection->status,
            'date' => ($inspection->completed_at ?? $inspection->scheduled_for ?? $inspection->created_at)?->toDateString(),
            'completed_at' => $inspection->completed_at?->toIso8601String(),
            'property' => ['id' => $property?->id, 'address' => $property?->buildDisplayAddress()],
            'header' => [
                'electricity_meter_reading' => $inspection->electricity_meter_reading, 'water_meter_reading' => $inspection->water_meter_reading,
                'furnished_status' => $inspection->furnished_status,
                'keys_count' => $inspection->keys_count, 'keys_description' => $inspection->keys_description,
                'remotes_count' => $inspection->remotes_count, 'remotes_description' => $inspection->remotes_description,
            ],
            'overall_notes' => $inspection->overall_notes,
            'rooms' => $roomRows->all(),
            'meters' => $meters->all(),
            'other_items' => $other->all(),
            'signatures' => $this->signatures($inspection, $contact, $audience),
            // the other half of the pair, when it has also been sent: open it the same way
            'related' => $this->related($inspection, $contact),
        ];
    }

    /** latest REAL assessment per item (a photo anchor with no condition, or late evidence, is not one) */
    private function observations(RentalInspection $inspection): Collection
    {
        return RentalInspectionObservation::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)
            ->where('condition', '!=', RentalInspectionObservation::CONDITION_PENDING)->whereNull('added_after_signing_at')
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('rental_inspection_item_id')->map(fn ($g) => $g->last());
    }

    /** @return array<string,mixed> */
    private function photo(RentalInspectionPhoto $p): array
    {
        return [
            'id' => $p->id,
            'url' => $p->storage_path,   // a signed, expiring address (RentalInspectionPhoto::getStoragePathAttribute)
            'taken_at' => $p->taken_at?->toIso8601String(),
            'added_after_signing' => $p->added_after_signing_at !== null,
        ];
    }

    /** @return array<string,bool> */
    private function signatures(RentalInspection $inspection, Contact $contact, string $audience): array
    {
        $mine = $this->scope->personContactIds($contact);
        $signed = RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->whereNull('superseded_at')
            ->whereIn('disposition', [RentalInspectionSignature::DISPOSITION_SIGNED, RentalInspectionSignature::DISPOSITION_WET_INK])->get();
        $by = fn (string $role) => $signed->where('party_role', $role);

        $out = [
            'you_signed' => $by($audience === 'tenant' ? RentalInspectionSignature::PARTY_TENANT : RentalInspectionSignature::PARTY_LANDLORD)
                ->contains(fn ($s) => in_array((int) $s->party_contact_id, $mine, true)),
            'agent_signed' => $by(RentalInspectionSignature::PARTY_AGENT)->isNotEmpty(),
        ];
        if ($audience !== 'tenant') {
            $out['tenant_signed'] = $by(RentalInspectionSignature::PARTY_TENANT)->isNotEmpty();
        }

        return $out;
    }

    /** @return array{id:int,type:string,type_label:string}|null */
    private function related(RentalInspection $inspection, Contact $contact): ?array
    {
        $other = $this->sentCounterpart($inspection, $contact, $inspection->type === RentalInspection::TYPE_OUT ? RentalInspection::TYPE_IN : RentalInspection::TYPE_OUT);

        return $other ? ['id' => $other->id, 'type' => $other->type, 'type_label' => RentalInspection::typeName((string) $other->type)] : null;
    }

    /** The same lease's In (or Out) inspection, if THIS person may see it as sent. */
    private function sentCounterpart(RentalInspection $inspection, Contact $contact, string $type): ?RentalInspection
    {
        if (! in_array($inspection->type, [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT], true)) {
            return null;
        }

        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $inspection->agency_id)->where('lease_id', $inspection->lease_id)->where('type', $type)->whereNull('deleted_at')
            ->orderByDesc('id')->get()
            ->first(fn (RentalInspection $i) => $this->scope->inspectionPortalState($i) === RentalPortalScopeService::INSPECTION_SENT);
    }
}
