<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\SellerInfoShareLink;
use App\Services\PublicLinks\PublicLinkUnavailableResponder;

class SellerInfoPublicController extends Controller
{
    public function show(string $token)
    {
        $link = SellerInfoShareLink::where('token', $token)->firstOrFail();

        // 2026-08-25 (Johan) — a real, resolved link (the token DID exist,
        // hence reaching this line at all) used to get a plain abort(410)
        // with no agency branding and no way back to a human, despite the
        // link's own agency_id being right there. Same shared piece as
        // every other "valid link, dead resource" case fixed today.
        if ($link->isExpired()) {
            return app(PublicLinkUnavailableResponder::class)->respond(
                $link->agency_id,
                'This link has expired',
                'The link you followed is no longer active. Your agent can send you an up-to-date one.',
                $link->sentBy,
            );
        }

        $link->recordAccess();

        $agency = Agency::withoutGlobalScopes()->find($link->agency_id);
        $viewMap = [
            'tier_1' => 'emails.compliance.seller-info.tier1',
            'tier_2' => 'emails.compliance.seller-info.tier2',
            'tier_3' => 'emails.compliance.seller-info.tier3',
        ];

        $viewName = $viewMap[$link->tier] ?? $viewMap['tier_1'];

        return view($viewName, [
            'agency'       => $agency,
            'agentMessage' => $link->agent_message ?? '',
            'sellerName'   => $link->seller_name ?? 'Seller',
        ]);
    }
}
