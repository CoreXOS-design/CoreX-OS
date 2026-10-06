<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-work-orders.md §14.29 — what a TENANT or a LANDLORD may see
 * of a job card: its status, schedule, completion, and the photos of the work.
 * ONE place decides it, so the tenant/landlord API, the portal web screens and
 * anything added later all agree.
 *
 * Photo rule (§14.27.1 Q7, agency setting `crew_photos_visible_to_clients`):
 *   - 'in_progress_and_completed' (default) → in-progress + completed photos
 *   - 'completed_only'                      → completed photos only
 *   - 'reported' (before) photos are NEVER shown to a client.
 *
 * Callers pass records they have ALREADY resolved through
 * RentalPortalScopeService (the caller's own lease / property) — this class
 * does no scoping of which records a party may open, only what of an opened
 * record is shown. Every query here strips global scopes (a portal request has
 * no staff user for AgencyScope to resolve) and pins `agency_id` explicitly.
 */
class RentalJobCardClientViewService
{
    /**
     * A draft is the office's unfinished prep — never shown to a client. Every
     * other status is real, booked or finished work and is shown truthfully
     * (a cancelled card reads "cancelled").
     */
    public const CLIENT_HIDDEN_STATUSES = [RentalJobCard::STATUS_DRAFT];

    /** @return string[] the photo_type values this agency lets a client see. */
    public function visiblePhotoTypes(?int $agencyId): array
    {
        return RentalPortalSetting::crewPhotosVisibleToClientsFor($agencyId) === RentalPortalSetting::CREW_PHOTOS_COMPLETED_ONLY
            ? [RentalWorkOrder::PHOTO_COMPLETED]
            : [RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED];
    }

    /**
     * Visible photos of one job card: the card's own photos plus its linked
     * work order's (the same merge the office card screen shows), filtered by
     * the agency rule, oldest first.
     */
    public function photosForCard(RentalJobCard $card): Collection
    {
        return $this->photos($card->agency_id, [$card->id], $card->rental_work_order_id ? [$card->rental_work_order_id] : []);
    }

    /** Visible photos for a work order: its own plus those of the job card(s) linked to it. */
    public function photosForWorkOrder(RentalWorkOrder $workOrder): Collection
    {
        $cardIds = RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $workOrder->agency_id)
            ->whereNull('deleted_at')
            ->where('rental_work_order_id', $workOrder->id)
            ->pluck('id')->all();

        return $this->photos($workOrder->agency_id, $cardIds, [$workOrder->id]);
    }

    /** Visible photos of the WORK done for a fault report (never the photos the reporter attached). */
    public function photosForFaultReport(RentalFaultReport $fault): Collection
    {
        $cardQuery = RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $fault->agency_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($fault) {
                $q->where('rental_fault_report_id', $fault->id);
                if ($fault->rental_work_order_id) {
                    $q->orWhere('rental_work_order_id', $fault->rental_work_order_id);
                }
            });

        return $this->photos(
            $fault->agency_id,
            $cardQuery->pluck('id')->all(),
            $fault->rental_work_order_id ? [$fault->rental_work_order_id] : [],
        );
    }

    /** @param int[] $cardIds @param int[] $workOrderIds */
    private function photos(int $agencyId, array $cardIds, array $workOrderIds): Collection
    {
        if (empty($cardIds) && empty($workOrderIds)) {
            return collect();
        }

        return RentalWorkOrderPhoto::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereIn('photo_type', $this->visiblePhotoTypes($agencyId))
            ->where(function ($q) use ($cardIds, $workOrderIds) {
                if ($cardIds) {
                    $q->whereIn('rental_job_card_id', $cardIds);
                }
                if ($workOrderIds) {
                    $q->orWhereIn('rental_work_order_id', $workOrderIds);
                }
            })
            ->orderBy('created_at')->orderBy('id')
            ->get();
    }

    /** The JSON shape of one photo for a client. Never the uploader, never the storage internals. */
    public function photoPayload(RentalWorkOrderPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => $photo->storage_path,
            'photo_type' => $photo->photo_type,
            'caption' => $photo->getAttribute('caption'),
            'uploaded_at' => $photo->created_at?->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function photosPayload(Collection $photos): array
    {
        return $photos->map(fn (RentalWorkOrderPhoto $p) => $this->photoPayload($p))->values()->all();
    }

    /**
     * Who signed the crew's completion and how. The `via` column arrives with
     * the crew-link build — until a row has it, it reads null.
     *
     * @return array{signed_by: ?string, signed_at: ?string, via: ?string}|null null until the crew has completed
     */
    public function crewCompletion(RentalJobCard $card): ?array
    {
        if (! $card->worker_signed_off_at) {
            return null;
        }

        return [
            'signed_by' => $card->worker_sign_off_name ?: null,
            'signed_at' => $card->worker_signed_off_at->toIso8601String(),
            'via' => $card->getAttribute('worker_sign_off_via'),
        ];
    }

    /**
     * One job card as a client sees it. Tenant: never a price. Landlord: the
     * selected quote amount only — exactly what the existing landlord
     * work-order endpoint already shows — passed in via $includeQuoteAmount.
     */
    public function payload(RentalJobCard $card, bool $includeQuoteAmount = false): array
    {
        $payload = [
            'id' => $card->id,
            'title' => $card->title,
            'status' => $card->status,
            'property_id' => $card->property_id,
            'property_address' => $card->property?->buildDisplayAddress(),
            'lease_id' => $card->lease_id,
            'rental_work_order_id' => $card->rental_work_order_id,
            'rental_fault_report_id' => $card->rental_fault_report_id,
            'scheduled_at' => $card->scheduled_at?->toIso8601String(),
            'due_at' => $card->due_at?->toIso8601String(),
            'completed_at' => $card->completed_at?->toIso8601String(),
            'crew_completion' => $this->crewCompletion($card),
            'photos' => $this->photosPayload($this->photosForCard($card)),
        ];

        if ($includeQuoteAmount) {
            $payload['selected_quote_amount'] = $card->rental_work_order_id
                ? optional(\App\Models\RentalWorkOrderQuote::withoutGlobalScopes()
                    ->where('agency_id', $card->agency_id)
                    ->where('rental_work_order_id', $card->rental_work_order_id)
                    ->where('is_selected', true)
                    ->first())->amount
                : null;
        }

        return $payload;
    }
}
