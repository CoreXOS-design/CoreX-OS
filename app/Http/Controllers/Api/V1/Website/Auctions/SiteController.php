<?php

namespace App\Http\Controllers\Api\V1\Website\Auctions;

use App\Services\Auctions\AuctionRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** What the public auction page SHOWS, as JSON (scope auctions:read). Documents marked "registered" need a buyer token. */
class SiteController extends AuctionApiController
{
    public function site(Request $request): JsonResponse
    {
        return response()->json($this->present->site($this->agency($request)));
    }

    /** The home page's auctions. ?phase=live|upcoming|finished narrows it; drafts and cancelled auctions are never listed. */
    public function auctions(Request $request): JsonResponse
    {
        $agency = $this->agency($request);
        $phase = (string) $request->query('phase', '');
        $rows = $this->site->auctions($agency)->filter(fn ($a) => $a->publicPhase() !== 'draft' && $a->publicPhase() !== 'cancelled');
        if (in_array($phase, ['live', 'upcoming', 'finished'], true)) {
            $rows = $rows->filter(fn ($a) => $a->publicPhase() === $phase);
        }

        return response()->json(['data' => $rows->map(fn ($a) => $this->present->auctionCard($a))->values()->all()]);
    }

    public function auction(Request $request, int $auction): JsonResponse
    {
        $agency = $this->agency($request);

        return response()->json(['data' => $this->present->auction($agency, $this->site->auction($agency, $auction), $this->buyer($request))]);
    }

    public function lot(Request $request, int $auction, int $lot): JsonResponse
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);

        return response()->json(['data' => $this->present->lot($agency, $a, $this->site->lot($a, $lot), $this->buyer($request))]);
    }

    /** The live part only (bidding state, latest bids, my registration, am I watching) - cheap enough to poll every few seconds. */
    public function feed(Request $request, int $auction, int $lot): JsonResponse
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);
        $l = $this->site->lot($a, $lot);
        $buyer = $this->buyer($request);
        $reg = $buyer ? app(AuctionRegistrationService::class)->forBuyer($a, $buyer) : null;

        return response()->json(['data' => $this->present->feed($l, $a, $reg, $buyer)]);
    }

    public function auctionDocument(Request $request, int $auction, int $document)
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);
        $doc = $this->site->auctionDocuments($a, $this->buyer($request) !== null)->firstWhere('id', $document);
        abort_if($doc === null || ! Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->download($doc->path, $doc->name . '.' . pathinfo($doc->path, PATHINFO_EXTENSION));
    }

    public function lotDocument(Request $request, int $auction, int $lot, int $document)
    {
        $agency = $this->agency($request);
        $a = $this->site->auction($agency, $auction);
        $l = $this->site->lot($a, $lot);
        $doc = $this->site->packDocument($l, $document, $this->buyer($request) !== null);
        $disk = $doc?->disk ?: 'local';
        abort_if($doc === null || ! Storage::disk($disk)->exists($doc->storage_path), 404);

        return Storage::disk($disk)->download($doc->storage_path, $doc->original_name);
    }
}
