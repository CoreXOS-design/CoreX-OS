<?php

namespace App\Services\Rentals;

use App\Models\RentalCatalogueItem;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardPriceRequest;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 / §17.5 — Build 1's plug-in slot in the crew
 * view: the "Parts & labour" panel — the price-request banner, the crew's own
 * lines with their state chips, and the "Send to office" action.
 *
 * Returns plain values only — never a model — and NEVER selling, markup, margin or an
 * owner amount (guarded by CrewPayloadNeverCarriesSellingTest). The crew sees ONLY what the
 * crew typed (their own cost) plus the office's decision on each line; the catalogue list is
 * NAMES ONLY (no default price, no default cost).
 *
 * Rendered by rentals/crew-link/_block-pricing.blade.php.
 */
final class CrewPricingBlock
{
    private const STATES = [
        RentalJobCardLine::OFFICE_CREW_DRAFT => ['draft', 'Draft — not sent yet'],
        RentalJobCardLine::OFFICE_AWAITING => ['sent', 'Sent to office'],
        RentalJobCardLine::OFFICE_ACCEPTED => ['accepted', 'Accepted'],
        RentalJobCardLine::OFFICE_REJECTED => ['rejected', 'Not accepted'],
        RentalJobCardLine::OFFICE_DECLINED_BY_OWNER => ['declined', 'Declined by owner'],
    ];

    /** @return array<string, mixed> [] when the card is closed or archived (nothing to add to). */
    public static function for(RentalJobCard $card, CrewViewContext $ctx): array
    {
        if ($card->isClosed() || $card->trashed()) {
            return [];
        }

        $pricesOn = RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id);

        $request = RentalJobCardPriceRequest::withoutGlobalScopes()
            ->where('rental_job_card_id', $card->id)->where('status', RentalJobCardPriceRequest::STATUS_OPEN)
            ->latest('id')->first();

        $lines = RentalJobCardLine::withoutGlobalScopes()
            ->where('rental_job_card_id', $card->id)->whereNull('deleted_at')
            ->whereIn('origin', [RentalJobCardLine::ORIGIN_CREW_PRICING, RentalJobCardLine::ORIGIN_CREW_EXTRA])
            ->orderBy('id')->get();

        $photos = $lines->isEmpty() ? collect() : RentalWorkOrderPhoto::withoutGlobalScopes()
            ->whereIn('rental_job_card_line_id', $lines->pluck('id'))->orderBy('id')->get()->groupBy('rental_job_card_line_id');

        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

        $rows = $lines->map(function (RentalJobCardLine $l) use ($photos, $fmt) {
            [$state, $label] = self::STATES[$l->office_status] ?? ['sent', 'Sent to office'];

            return [
                'id' => $l->id,
                'kind' => $l->type,
                'description' => $l->description,
                'code' => $l->code,
                'quantity' => $fmt($l->quantity),
                'unit' => $l->unit,
                // The crew's OWN entry (or the cost the office corrected it to) — a cost, never a selling price.
                'unit_cost' => $l->unit_cost !== null ? number_format((float) $l->unit_cost, 2, '.', '') : null,
                'cost_total' => $l->cost_total !== null ? number_format((float) $l->cost_total, 2) : null,
                'note' => $l->crew_note,
                'is_extra' => $l->origin === RentalJobCardLine::ORIGIN_CREW_EXTRA,
                'state' => $state,
                'state_label' => $label,
                'reason' => $l->office_status === RentalJobCardLine::OFFICE_REJECTED ? $l->reject_reason : null,
                'editable' => $l->office_status === RentalJobCardLine::OFFICE_CREW_DRAFT,
                'photos' => ($photos[$l->id] ?? collect())->map(fn ($p) => ['url' => $p->storage_path])->values()->all(),
            ];
        })->values()->all();

        // Names only — what the crew may pick from. Never a price, never a cost.
        $catalogue = RentalCatalogueItem::withoutGlobalScopes()
            ->where('agency_id', $ctx->agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->with('catalogueUnit')->orderBy('sort_order')->orderBy('id')->limit(300)->get()
            ->map(fn (RentalCatalogueItem $i) => [
                'id' => $i->id,
                'label' => trim(($i->code ? $i->code . ' — ' : '') . $i->description),
                'description' => $i->description,
                'kind' => $i->kind(),
                'unit' => $i->catalogueUnit?->name,
            ])->values()->all();

        return [
            'prices_on' => $pricesOn,
            'request' => $request ? [
                'note' => $request->note,
                'asked_at' => $request->requested_at?->copy()->setTimezone(config('app.timezone') ?: 'Africa/Johannesburg')->format('j M, H:i'),
            ] : null,
            'lines' => $rows,
            'draft_count' => collect($rows)->where('state', 'draft')->count(),
            'catalogue' => $catalogue,
            'photo_limit' => CrewJobService::MAX_LINE_PHOTOS,
        ];
    }
}
