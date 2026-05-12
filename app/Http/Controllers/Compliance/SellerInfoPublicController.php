<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\SellerInfoShareLink;

class SellerInfoPublicController extends Controller
{
    public function show(string $token)
    {
        $link = SellerInfoShareLink::where('token', $token)->firstOrFail();

        if ($link->isExpired()) {
            abort(410, 'This link has expired.');
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
