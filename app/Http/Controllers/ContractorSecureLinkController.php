<?php

namespace App\Http\Controllers;

use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. The contractor's
 * per-job secure link — no login, no CoreX session. Deliberately a
 * TOP-LEVEL controller (App\Http\Controllers, not ...\CoreX), same
 * reasoning as RentalInspectionPublicController: reached by someone with
 * no CoreX session at all. Every lookup goes through
 * RentalSecureAccessToken::findLiveByRawToken() + ->isLive() — never a
 * route-model-bound {token}, never a bare work-order id. Rate-limited at
 * the route level (throttle:30,1 — same middleware every other public
 * token-gated route in this app already uses, e.g. buyer-portal).
 *
 * Expired/revoked/used-up (work order already completed/cancelled) all
 * render the SAME "unavailable" view — never distinguishing which, same
 * principle RentalInspectionPublicController already applies.
 */
class ContractorSecureLinkController extends Controller
{
    private function resolveToken(string $token): ?RentalSecureAccessToken
    {
        // Purpose-checked (§14.27.6): a crew link's token must never open the
        // contractor page, nor the reverse.
        return app(\App\Services\Rentals\RentalSecureAccessTokenService::class)
            ->resolveLive($token, RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER);
    }

    public function show(Request $request, string $token): View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
        }

        $workOrder = $record->workOrder()->with(['property', 'quotes', 'photos'])->first();

        return view('rentals.secure-link.show', [
            'token' => $token,
            'workOrder' => $workOrder,
        ]);
    }

    public function storeQuote(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'quote_date' => ['required', 'date'],
            'detail_text' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ]);

        if (empty($data['detail_text']) && !$request->hasFile('document')) {
            return back()->withErrors(['quote' => 'Attach the quote document, or enter its details — at least one is required.'])->withInput();
        }

        $workOrder = $record->workOrder;
        if ($request->hasFile('document')) {
            $data['document_storage_path'] = $request->file('document')
                ->store("rental-work-order-quotes/{$workOrder->id}", 'local');
        }
        unset($data['document']);
        $data['agency_service_provider_id'] = $record->agency_service_provider_id;

        $workOrder->recordQuote($data, null, 'via contractor link');

        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Quote submitted.');
    }

    public function storePhoto(Request $request, RentalWorkOrderService $service, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
        }

        $data = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
        ]);

        $service->storePhoto($record->workOrder, $request->file('photo'), RentalWorkOrder::PHOTO_COMPLETED, null, null);

        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Photo uploaded.');
    }

    public function markDone(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
        }

        try {
            $record->workOrder->complete(null, [
                'paid_by' => RentalWorkOrder::PAID_BY_NOT_YET_PAID,
            ], 'via contractor link');
        } catch (\Throwable $e) {
            return back()->withErrors(['work_order' => $e->getMessage()]);
        }

        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Marked as done. The agency has been notified.');
    }
}
