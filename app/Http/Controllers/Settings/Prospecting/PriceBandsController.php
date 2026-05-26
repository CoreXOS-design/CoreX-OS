<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Events\Prospecting\PriceBandConfigured;
use App\Http\Controllers\Controller;
use App\Models\Prospecting\PriceBand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PriceBandsController extends Controller
{
    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $validated = $this->validateInput($request);

        $band = PriceBand::create([
            'agency_id'     => $agencyId,
            'listing_type'  => $validated['listing_type'],
            'name'          => $validated['name'],
            'price_min'     => $validated['price_min'],
            'price_max'     => $validated['price_max'],
            'display_order' => $this->nextDisplayOrder($agencyId, $validated['listing_type']),
        ]);

        event(new PriceBandConfigured(
            band:        $band,
            action:      PriceBandConfigured::ACTION_CREATED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'price-bands'])
            ->with('status', "Price band '{$band->name}' added.");
    }

    public function update(Request $request, PriceBand $band)
    {
        $this->authorizeAgency($request, $band);
        $validated = $this->validateInput($request);

        $band->update([
            'listing_type' => $validated['listing_type'],
            'name'         => $validated['name'],
            'price_min'    => $validated['price_min'],
            'price_max'    => $validated['price_max'],
        ]);

        event(new PriceBandConfigured(
            band:        $band->fresh(),
            action:      PriceBandConfigured::ACTION_UPDATED,
            actorUserId: Auth::id(),
            agencyId:    $band->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'price-bands'])
            ->with('status', 'Price band updated.');
    }

    public function archive(Request $request, PriceBand $band)
    {
        $this->authorizeAgency($request, $band);

        $band->delete();

        event(new PriceBandConfigured(
            band:        $band,
            action:      PriceBandConfigured::ACTION_ARCHIVED,
            actorUserId: Auth::id(),
            agencyId:    $band->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'price-bands'])
            ->with('status', "Price band '{$band->name}' archived.");
    }

    public function reorder(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $validated = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($validated, $agencyId) {
            foreach ($validated['order'] as $position => $id) {
                PriceBand::withoutGlobalScopes()
                    ->where('id', $id)
                    ->where('agency_id', $agencyId)
                    ->update(['display_order' => $position]);
            }
        });

        $first = PriceBand::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
        if ($first) {
            event(new PriceBandConfigured(
                band:        $first,
                action:      PriceBandConfigured::ACTION_UPDATED,
                actorUserId: Auth::id(),
                agencyId:    $agencyId,
            ));
        }

        return redirect()->route('settings.prospecting.index', ['tab' => 'price-bands'])
            ->with('status', 'Price bands reordered.');
    }

    /**
     * @return array{listing_type:string, name:string, price_min:int, price_max:?int}
     */
    private function validateInput(Request $request): array
    {
        $validator = validator($request->all(), [
            'listing_type' => 'required|in:sale,rental',
            'name'         => 'required|string|max:100',
            'price_min'    => 'required|integer|min:0',
            'price_max'    => 'nullable|integer|min:0',
        ]);

        $validator->after(function ($v) {
            $data = $v->getData();
            $min = $data['price_min'] ?? null;
            $max = $data['price_max'] ?? null;
            if ($min !== null && $max !== null && $max !== '' && (int) $max <= (int) $min) {
                $v->errors()->add('price_max', 'Maximum price must be greater than minimum.');
            }
        });

        $validated = $validator->validate();
        $validated['price_min'] = (int) $validated['price_min'];
        $validated['price_max'] = isset($validated['price_max']) && $validated['price_max'] !== ''
            ? (int) $validated['price_max']
            : null;

        return $validated;
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($id === null, 403, 'No agency context.');
        return (int) $id;
    }

    private function nextDisplayOrder(int $agencyId, string $listingType): int
    {
        return (int) (PriceBand::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('listing_type', $listingType)
            ->max('display_order') ?? 0) + 1;
    }

    private function authorizeAgency(Request $request, PriceBand $band): void
    {
        if ($band->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
