<?php

namespace App\Services\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionDocument;
use App\Models\AuctionLot;
use App\Models\Document;
use App\Models\Property;
use App\Models\Scopes\BranchScope;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Support\Collection;

/**
 * Everything the PUBLIC auction site (.ai/specs/auctions.md §8.1) is allowed to read.
 *
 * Public requests have no staff user, so the global AgencyScope cannot protect them. Every query here is therefore
 * pinned to the agency named in the URL, explicitly, and only ever returns what is published: drafts, cancelled
 * auctions, draft/withdrawn lots and the reserve price never leave this class.
 */
class PublicAuctionSite
{
    /** The agency for /auctions/{slug}; 404 when unknown or when the agency has not switched Auctions on. */
    public function agency(string $slug): Agency
    {
        $agency = Agency::withoutGlobalScopes()->where('slug', $slug)->first();
        abort_if($agency === null || ! app(AgencyFeatureService::class)->enabled('auctions', $agency), 404);

        return $agency;
    }

    /** @return Collection<int, Auction> upcoming and open first, then finished ones, newest first */
    public function auctions(Agency $agency): Collection
    {
        return $this->auctionQuery($agency)->withCount(['lots as public_lots_count' => fn ($q) => $q->whereIn('status', $this->visibleLotStatuses())])
            ->orderByRaw("FIELD(status, 'open', 'published', 'closed', 'completed')")
            ->orderBy('starts_at')
            ->get();
    }

    public function auction(Agency $agency, int $id): Auction
    {
        return $this->auctionQuery($agency)->findOrFail($id);
    }

    /** @return Collection<int, AuctionLot> */
    public function lots(Auction $auction): Collection
    {
        return AuctionLot::queryWithoutAgencyScope()
            ->where('agency_id', $auction->agency_id)->where('auction_id', $auction->id)
            ->whereIn('status', $this->visibleLotStatuses())
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('lot_number')->get();
    }

    public function lot(Auction $auction, int $id): AuctionLot
    {
        return AuctionLot::queryWithoutAgencyScope()
            ->where('agency_id', $auction->agency_id)->where('auction_id', $auction->id)
            ->whereIn('status', $this->visibleLotStatuses())
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()])
            ->findOrFail($id);
    }

    /** Auction-level documents the visitor may see: public ones for everyone, "registered" ones once signed in. */
    public function auctionDocuments(Auction $auction, bool $signedIn): Collection
    {
        return AuctionDocument::queryWithoutAgencyScope()->where('auction_id', $auction->id)
            ->when(! $signedIn, fn ($q) => $q->where('visibility', 'public'))
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * The property's auction pack (Drive files flagged for the auction page), split by what the visitor may open.
     *
     * @return Collection<int, Document> each with ->pivot->auction_visibility
     */
    public function packDocuments(AuctionLot $lot, bool $signedIn): Collection
    {
        $property = $lot->property;
        if (! $property) {
            return collect();
        }

        return $property->documents()->withoutGlobalScopes()
            ->wherePivotIn('auction_visibility', $signedIn ? ['public', 'registered'] : ['public'])
            ->get();
    }

    /** A single pack document, only if the visitor may open it. */
    public function packDocument(AuctionLot $lot, int $documentId, bool $signedIn): ?Document
    {
        return $this->packDocuments($lot, $signedIn)->firstWhere('id', $documentId);
    }

    /** @return array<int, string> public photo URLs, best first */
    public function photos(?Property $property, int $max = 12): array
    {
        if (! $property) {
            return [];
        }

        $raw = collect($property->gallery_images_json ?: [])
            ->merge($property->images_json ?: [])
            ->filter(fn ($u) => is_string($u) && $u !== '')
            ->unique()->take($max);

        return $raw->map(fn ($u) => Property::publicImageUrl($property->thumbFor($u)))->filter()->values()->all();
    }

    /** What a visitor sees as the price line for a lot: opening bid first, guide range if the agent gave one. */
    public function money(?float $amount, string $currency = 'ZAR'): string
    {
        if ($amount === null) {
            return '';
        }

        return ($currency === 'ZAR' ? 'R ' : $currency . ' ') . number_format($amount, 0, '.', ' ');
    }

    /** Lot statuses that are shown publicly (never draft, never withdrawn). */
    public function visibleLotStatuses(): array
    {
        return [AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_OPEN, AuctionLot::STATUS_SOLD, AuctionLot::STATUS_SOLD_SUBJECT, AuctionLot::STATUS_PASSED_IN];
    }

    private function auctionQuery(Agency $agency)
    {
        return Auction::queryWithoutAgencyScope()->withoutGlobalScope(BranchScope::class)
            ->where('agency_id', $agency->id)
            ->whereIn('status', [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN, Auction::STATUS_CLOSED, Auction::STATUS_COMPLETED]);
    }
}
