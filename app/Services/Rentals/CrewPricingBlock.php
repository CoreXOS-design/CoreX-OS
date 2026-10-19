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
 * crew typed (their own cost) plus the office's decision on each line. The catalogue list is
 * names, kinds and units; AT-459: a PART item also carries its cost on file so the cost box can be
 * pre-filled with what it should cost (never a selling price). A LABOUR item carries no money at all:
 * the crew counts hours and the office prices them, so no labour rate or labour amount ever reaches the crew.
 *
 * Rendered by rentals/crew-link/_block-pricing.blade.php.
 */
final class CrewPricingBlock
{
    private const STATES = [
        RentalJobCardLine::OFFICE_CREW_DRAFT => ['draft', 'On your list — not sent yet'],
        RentalJobCardLine::OFFICE_AWAITING => ['sent', 'Sent — waiting for the office'],
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

        $rows = $lines->map(fn (RentalJobCardLine $l) => self::mapRow($l, $photos[$l->id] ?? collect()))->values()->all();

        // What the crew may pick from: names, kinds and units. A PART also carries its cost on file (pre-fills "what it cost you");
        // a LABOUR item carries no money. Never a selling price. The cost is read through the one resolver the office lines use.
        $agency = $card->agency ?? \App\Models\Agency::withoutGlobalScopes()->find($card->agency_id);
        $vat = app(RentalJobCardVatService::class);
        $catalogue = RentalCatalogueItem::withoutGlobalScopes()
            ->where('agency_id', $ctx->agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->with(['catalogueUnit', 'catalogueItemType'])->orderBy('sort_order')->orderBy('id')->limit(300)->get()
            ->map(function (RentalCatalogueItem $i) use ($pricesOn, $vat, $agency) {
                $kind = $i->kind();
                $cost = $pricesOn && $agency && $kind !== RentalCatalogueItem::TYPE_LABOUR ? $vat->catalogueDefaultCostForLine($i, $agency) : null;

                return [
                    'id' => $i->id,
                    'label' => trim(($i->code ? $i->code . ' — ' : '') . $i->description),
                    'description' => $i->description,
                    'kind' => $kind,
                    'unit' => $i->catalogueUnit?->name,
                    'cost' => $cost !== null ? number_format($cost, 2, '.', '') : null,
                ];
            })->values()->all();

        return [
            'prices_on' => $pricesOn,
            'request' => $request ? [
                'note' => $request->note,
                'asked_at' => $request->requested_at?->copy()->setTimezone(config('app.timezone') ?: 'Africa/Johannesburg')->format('j M, H:i'),
            ] : null,
            'lines' => $rows,
            'draft_count' => collect($rows)->where('state', 'draft')->count(),
            // AT-465 - how many lines are with the office waiting, and whether this is the crew's first send (findings are then required)
            'sent_count' => collect($rows)->where('state', 'sent')->count(),
            'first_send' => ! \App\Models\RentalJobCardCrewReport::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->exists(),
            'catalogue' => $catalogue,
            'photo_limit' => CrewJobService::MAX_LINE_PHOTOS,
        ];
    }

    /**
     * ONE line, in the exact shape the page renders and the fetch handlers return (spec §17.42), so a line added without a reload
     * looks identical to the same line after a refresh. Plain values only - a cost, never a selling price, markup or margin.
     *
     * @return array<string, mixed>
     */
    public static function rowFor(RentalJobCardLine $line): array
    {
        $own = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->orderBy('id')->get();

        return self::mapRow($line, $own);
    }

    /**
     * @param \Illuminate\Support\Collection<int, RentalWorkOrderPhoto> $own
     * @return array<string, mixed>
     */
    private static function mapRow(RentalJobCardLine $l, \Illuminate\Support\Collection $own): array
    {
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
        // AT-459 — the crew never sees a labour rate or a labour amount.
        $labour = $l->type === RentalCatalogueItem::TYPE_LABOUR;
        $receipt = $own->where('photo_type', RentalJobCardLine::RECEIPT_PHOTO_TYPE)->last();

        [$state, $label] = self::STATES[$l->office_status] ?? ['sent', 'Sent — waiting for the office'];

        return [
            'id' => $l->id,
            'item_id' => $l->rental_catalogue_item_id,
            'kind' => $l->type,
            'description' => $l->description,
            'code' => $l->code,
            'quantity' => $fmt($l->quantity),
            'unit' => $l->unit,
            // The crew's OWN entry (or the cost the office corrected it to) — a cost, never a selling price.
            'unit_cost' => ! $labour && $l->unit_cost !== null ? number_format((float) $l->unit_cost, 2, '.', '') : null,
            'cost_total' => ! $labour && $l->cost_total !== null ? number_format((float) $l->cost_total, 2) : null,
            'note' => $l->crew_note,
            'is_extra' => $l->origin === RentalJobCardLine::ORIGIN_CREW_EXTRA,
            'state' => $state,
            'state_label' => $label,
            'reason' => $l->office_status === RentalJobCardLine::OFFICE_REJECTED ? $l->reject_reason : null,
            'editable' => $l->office_status === RentalJobCardLine::OFFICE_CREW_DRAFT,
            'photos' => $own->where('photo_type', RentalJobCardLine::PHOTO_TYPE)->map(fn ($p) => ['url' => $p->storage_path])->values()->all(),
            // AT-459 — the receipt for a part line (the newest one if it was replaced); labour has none.
            'receipt_url' => ! $labour && $receipt ? $receipt->storage_path : null,
        ];
    }
}
