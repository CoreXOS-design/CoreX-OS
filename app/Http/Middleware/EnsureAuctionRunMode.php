<?php

namespace App\Http\Middleware;

use App\Models\AgencyAuctionSettings;
use Closure;
use Illuminate\Http\Request;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md. In advertising-only
 * mode (the default) CoreX does not run the sale: the Sale Room and the Bidder
 * Register are switched off server-side, not just hidden, so a direct URL does
 * not reach them either. Flip Settings → Auctions → "Advertising only" to bring
 * them back.
 */
class EnsureAuctionRunMode
{
    public function handle(Request $request, Closure $next)
    {
        $agencyId = (int) optional($request->user())->effectiveAgencyId();

        if (AgencyAuctionSettings::advertisingOnlyFor($agencyId)) {
            return redirect()->route('corex.auctions.index')
                ->withErrors(['auction' => 'Your agency uses Auctions for advertising only. Turn off "Advertising only" in Settings → Auctions to run the sale from CoreX.']);
        }

        return $next($request);
    }
}
