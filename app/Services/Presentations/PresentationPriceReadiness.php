<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;

/**
 * The ONE "does this presentation have a price?" check.
 *
 * Johan, 2026-10-07: "there should always be a price, that's the whole point.
 * Why would we generate no price." A seller-facing document with a dash or a
 * silently missing valuation section is never acceptable, so every place that
 * confirms, renders, downloads or sends a presentation asks this class first:
 *
 *   - Confirm & Generate            (PresentationController::confirmAndGenerate)
 *   - the seller PDF + Complete Pack (PresentationPdfController)
 *   - the public seller web page     (PublicPresentationController::show)
 *   - Seller Live                    (PresentationController::sellerLive)
 *   - every send / share link        (SnapshotLinkService::createLink, delivery send)
 *   - the Review / Analysis / Overview banners
 *
 * "Has a price" = the CMA Middle (the figure the seller PDF prints as "your
 * home fits best at R…") is a number above zero. A confirmed version is judged
 * on its FROZEN figure (what the seller actually sees); an unconfirmed one on a
 * live compile through the same engine the screens use.
 *
 * When there is no price the result names exactly what is missing and what to do.
 */
final class PresentationPriceReadiness
{
    public const NO_COMPS              = 'no_comps';
    public const NO_PRICED_COMPS       = 'no_priced_comps';
    public const NO_PRICE              = 'no_price';
    public const FROZEN_WITHOUT_PRICE  = 'frozen_without_price';
    public const NO_VERSION            = 'no_version';

    /** Shown when the agent tries to leave nothing ticked on Review. */
    public const NOTHING_TICKED_MESSAGE =
        'No comparable sales selected — tick at least one sale (or widen the price range) so a price can be calculated.';

    /**
     * Judge a compiled analysis (the array AnalysisDataService::compile returns).
     *
     * @param  array<string, mixed>  $analysis
     * @return array{ready: bool, reason: ?string, message: ?string, price: ?int, pool_n: int}
     */
    public static function fromAnalysis(Presentation $presentation, array $analysis): array
    {
        $cma    = $analysis['cma_valuation'] ?? [];
        $poolN  = (int) ($cma['compute_pool_n'] ?? 0);
        $middle = $cma['cma_middle'] ?? null;

        if (is_numeric($middle) && (float) $middle > 0) {
            return self::result(true, null, (int) round((float) $middle), $poolN);
        }

        return self::result(false, self::reasonFor($presentation), null, $poolN);
    }

    /**
     * Judge a version the way the seller will meet it: its frozen figure when
     * confirmed, otherwise a live compile of that version.
     *
     * @return array{ready: bool, reason: ?string, message: ?string, price: ?int, pool_n: int}
     */
    public static function forDocument(Presentation $presentation, ?PresentationVersion $version): array
    {
        if (!$version) {
            return self::result(false, self::NO_VERSION, null, 0);
        }

        $frozen = $version->snapshot_payload;
        if (!(is_array($frozen) && $frozen !== [])) {
            $legacy = $version->getAttribute('computed_json');
            $frozen = is_string($legacy) ? json_decode($legacy, true) : $legacy;
        }

        if (is_array($frozen) && $frozen !== []) {
            $middle = $frozen['cma_valuation']['cma_middle'] ?? null;
            if (is_numeric($middle) && (float) $middle > 0) {
                return self::result(
                    true, null, (int) round((float) $middle),
                    (int) ($frozen['cma_valuation']['compute_pool_n'] ?? 0),
                );
            }
            return self::result(false, self::FROZEN_WITHOUT_PRICE, null, 0);
        }

        return self::forLiveVersion($presentation, $version);
    }

    /**
     * Judge a version by compiling it live (an unconfirmed draft, or the
     * Confirm gate before anything is frozen).
     *
     * @return array{ready: bool, reason: ?string, message: ?string, price: ?int, pool_n: int}
     */
    public static function forLiveVersion(Presentation $presentation, ?PresentationVersion $version): array
    {
        $presentation->loadMissing(['property', 'fields', 'soldComps', 'activeListings']);
        $analysis = (new AnalysisDataService())->compile($presentation, $version);

        return self::fromAnalysis($presentation, $analysis);
    }

    /** The version a share link / send picks when none is named (latest by id). */
    public static function latestVersion(Presentation $presentation): ?PresentationVersion
    {
        return PresentationVersion::query()
            ->withoutGlobalScopes()
            ->where('presentation_id', $presentation->id)
            ->orderByDesc('id')
            ->first();
    }

    /** Plain-language message for a reason code. */
    public static function message(string $reason): string
    {
        return match ($reason) {
            self::NO_COMPS =>
                'This presentation has no comparable sales, so there is no price to show the seller. '
                . 'Add or import comparable sales (upload a CMA report, or widen the comparable-sales radius and regenerate), then try again.',
            self::NO_PRICED_COMPS =>
                'None of this presentation\'s comparable sales has a sold price, so no price can be calculated. '
                . 'Import comparable sales that show a sold price (or regenerate the presentation), then try again.',
            self::FROZEN_WITHOUT_PRICE =>
                'This presentation was confirmed without a price, so it cannot be sent or downloaded. '
                . 'Re-open it for editing, make sure comparable sales are selected, then confirm it again.',
            self::NO_VERSION =>
                'This presentation has not been generated yet — generate it first.',
            default =>
                'A price could not be calculated from the comparable sales on this presentation. '
                . 'Check the comparable sales on the Review screen, then try again.',
        };
    }

    private static function reasonFor(Presentation $presentation): string
    {
        $live = PresentationSoldComp::query()
            ->withoutGlobalScopes()
            ->where('presentation_id', $presentation->id)
            ->whereNull('deleted_at')
            ->get(['id', 'sold_price_inc']);

        if ($live->isEmpty()) {
            return self::NO_COMPS;
        }
        if ($live->filter(fn ($c) => (int) $c->sold_price_inc > 0)->isEmpty()) {
            return self::NO_PRICED_COMPS;
        }
        return self::NO_PRICE;
    }

    /** @return array{ready: bool, reason: ?string, message: ?string, price: ?int, pool_n: int} */
    private static function result(bool $ready, ?string $reason, ?int $price, int $poolN): array
    {
        return [
            'ready'   => $ready,
            'reason'  => $reason,
            'message' => $reason !== null ? self::message($reason) : null,
            'price'   => $price,
            'pool_n'  => $poolN,
        ];
    }
}
