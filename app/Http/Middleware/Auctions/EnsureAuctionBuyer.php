<?php

namespace App\Http\Middleware\Auctions;

use App\Models\AgencyApiKey;
use App\Models\AuctionBuyerToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Identifies the signed-in BUYER behind an auction-website request from the `X-Buyer-Token` header (.ai/specs/auctions.md §24). The token must
 * be live and belong to the same agency as the API key. Optional mode (`auction.buyer:optional`) lets guests through for endpoints that only
 * personalise for a buyer. The buyer is also set as the `client-web` user for this request so every shared auction service sees them.
 */
class EnsureAuctionBuyer
{
    public function handle(Request $request, Closure $next, string $mode = 'required')
    {
        $key = Auth::guard('agency-api')->user();
        $plain = (string) $request->header('X-Buyer-Token', '');

        if ($key instanceof AgencyApiKey && $plain !== '') {
            $token = AuctionBuyerToken::find($plain, (int) $key->agency_id);
            if ($token && ($buyer = $token->buyer)) {
                if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
                    $token->forceFill(['last_used_at' => now()])->saveQuietly();
                }
                Auth::guard('client-web')->setUser($buyer);
                $request->attributes->set('auction_buyer', $buyer);
                $request->attributes->set('auction_buyer_token', $token);

                return $next($request);
            }
        }

        if ($mode === 'optional' && $plain === '') {
            return $next($request);
        }

        return response()->json(['message' => 'Please log in again.', 'code' => 'buyer_token_invalid'], 401);
    }
}
