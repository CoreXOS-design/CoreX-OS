<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\BuyerPropertyExclusion;
use App\Models\Contact;
use App\Services\Matching\BuyerPropertyExclusions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Not for <buyer>" on the MIC Work card (mic-work-tab.md 13.17). Thin: the rule, the cache and the Core Match side live in BuyerPropertyExclusions.
 * The buyer and the property file must both belong to the viewer's agency; anything else is a 404.
 */
class MicBuyerHideController extends Controller
{
    public function store(Request $request, BuyerPropertyExclusions $svc): JsonResponse
    {
        $data = $request->validate([
            'buyer_id' => ['required', 'integer'],
            'file' => ['required', 'integer', 'not_in:0'], // a property file id, or minus an advert id for an advert that is not linked to a file
        ]);
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id);
        $buyer = $this->buyer($agencyId, (int) $data['buyer_id']);

        $file = (int) $data['file'];
        $row = $svc->hide($buyer, $file > 0 ? $file : null, $file < 0 ? -$file : null, null, $user, 'mic');

        return $this->state($svc, $agencyId, $buyer, ['id' => $row->id]);
    }

    public function restore(Request $request, int $exclusion, BuyerPropertyExclusions $svc): JsonResponse
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id);
        $row = BuyerPropertyExclusion::query()->where('agency_id', $agencyId)->findOrFail($exclusion);
        $buyer = $this->buyer($agencyId, (int) $row->contact_id);
        $svc->restore($row, $user);

        return $this->state($svc, $agencyId, $buyer, ['id' => $row->id]);
    }

    private function buyer(int $agencyId, int $contactId): Contact
    {
        return Contact::query()->where('agency_id', $agencyId)->whereKey($contactId)->firstOrFail();
    }

    private function state(BuyerPropertyExclusions $svc, int $agencyId, Contact $buyer, array $extra): JsonResponse
    {
        return response()->json($extra + ['ok' => true, 'hidden_count' => $svc->activeFor($agencyId, (int) $buyer->id)->count()]);
    }
}
