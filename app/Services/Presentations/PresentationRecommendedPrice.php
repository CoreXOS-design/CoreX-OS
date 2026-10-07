<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Property;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE one answer to "what price did the agent recommend to the seller?".
 *
 * Johan, 2026-10-07: the price on the presentation screen, in the seller's PDF, on the
 * property Intelligence tab and in the client app must always be the same figure — and
 * a property with no presentation must not show a recommended price at all.
 *
 * The figure is the presentation's EVALUATED VALUE (CMA "middle") — exactly what the
 * seller PDF prints as "your home fits best at R…" (PresentationPdfService
 * ::buildSummaryPayload, `$recommendedPrice = $cmaMiddle`). It is read from the version's
 * FROZEN `snapshot_payload.cma_valuation.cma_middle` — the same blob the PDF and the
 * public seller page read — never from the older `presentation_snapshots.computed_json`
 * copy (which disagrees in ~40% of presentations) and never recalculated here.
 *
 * Rules (Johan's rulings):
 *  - "latest presentation for the property" = highest presentation id for that property;
 *  - a DRAFT presentation counts — status is deliberately not consulted;
 *  - within a presentation, the latest version that carries a frozen CMA block;
 *  - no presentation → state `none`; presentation but no calculated price → `no_price`.
 */
final class PresentationRecommendedPrice
{
    public const STATE_NONE     = 'none';
    public const STATE_NO_PRICE = 'no_price';
    public const STATE_PRICE    = 'price';

    /**
     * For a property: its latest presentation and that presentation's price.
     *
     * @return array{state:string, price:?int, presentation_id:?int, as_of:?Carbon}
     */
    public function forProperty(Property $property): array
    {
        $presentationId = DB::table('presentations')
            ->where('property_id', $property->id)
            ->where('agency_id', $property->agency_id)
            ->whereNull('deleted_at')
            ->max('id');

        if (! $presentationId) {
            return ['state' => self::STATE_NONE, 'price' => null, 'presentation_id' => null, 'as_of' => null];
        }

        return $this->forPresentationId((int) $presentationId);
    }

    /**
     * For one presentation (the presentation screen's left panel).
     *
     * @return array{state:string, price:?int, presentation_id:?int, as_of:?Carbon}
     */
    public function forPresentationId(int $presentationId): array
    {
        // JSON_EXTRACT keeps the multi-MB frozen payload out of PHP.
        $row = DB::table('presentation_versions')
            ->where('presentation_id', $presentationId)
            ->whereNull('deleted_at')
            ->whereRaw("JSON_EXTRACT(snapshot_payload, '$.cma_valuation') IS NOT NULL")
            ->orderByDesc('id')
            ->selectRaw("snapshot_taken_at, JSON_UNQUOTE(JSON_EXTRACT(snapshot_payload, '$.cma_valuation.cma_middle')) AS middle")
            ->first();

        $middle = $row && is_numeric($row->middle) && (float) $row->middle > 0 ? (int) round((float) $row->middle) : null;

        return [
            'state'           => $middle !== null ? self::STATE_PRICE : self::STATE_NO_PRICE,
            'price'           => $middle,
            'presentation_id' => $presentationId,
            'as_of'           => $middle !== null && $row->snapshot_taken_at ? Carbon::parse($row->snapshot_taken_at) : null,
        ];
    }
}
