<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.14 / §17.11 — Build 1's settings: the agency's default markup on parts and on labour
 * (§17.4.3 rule 6) and the estimate wording printed on every owner quote (R6).
 *
 * Two deliberately narrow savers (one concern per endpoint, same discipline as RentalWorkOrderSettingsController — a SIBLING
 * file so the three maintenance-flow builds never edit the same class). Each is has()-guarded: the Setup Wizard posts a
 * SUBSET of the settings (agency-onboarding-setup.md §6.1), so a key that is not in the request is never touched, and a saver
 * never coerces an absent value to a default and writes it. Both are also called by the wizard, which bypasses route
 * middleware — so the permission is checked here, and a 403 is exactly what the wizard reports as "no permission".
 */
class RentalWorkOrderPricingSettingsController extends Controller
{
    private function authorise(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('rental_work_orders.manage_settings'), 403);
    }

    /** Default markup % on part lines / labour lines (§17.4.3 rule 6). Blank = back to the neutral default (0 %, priced at cost). */
    public function updateDefaultMarkups(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('default_parts_markup_percent') && ! $request->has('default_labour_markup_percent')) {
            return redirect()->route('corex.settings.rental-work-orders.edit')
                ->withErrors(['default_parts_markup_percent' => 'That did not save — please try again.']);
        }

        $validated = $request->validate([
            'default_parts_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'default_labour_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ], [
            '*.numeric' => 'A markup must be a number, like 20 or 12.5.',
            '*.min' => 'A markup cannot be negative.',
            '*.max' => 'A markup of more than 1000 % is not allowed.',
        ]);

        $data = [];
        foreach (['default_parts_markup_percent', 'default_labour_markup_percent'] as $key) {
            if ($request->has($key)) {
                $data[$key] = ($validated[$key] ?? null) === null || $validated[$key] === '' ? null : round((float) $validated[$key], 2);
            }
        }
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agencyId], $data);

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Default markups saved.');
    }

    /**
     * The estimate wording on every owner quote (R6). A blank box — or "Restore default" — stores NULL, which reads as the
     * built-in wording; a text identical to the built-in wording is also stored as NULL, so an agency that never changed it keeps
     * following the built-in text rather than freezing a copy of it.
     */
    public function updateQuoteEstimateTerm(Request $request): RedirectResponse
    {
        $this->authorise($request);
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('quote_estimate_term') && ! $request->boolean('restore_default')) {
            return redirect()->route('corex.settings.rental-work-orders.edit')
                ->withErrors(['quote_estimate_term' => 'That did not save — please try again.']);
        }

        $validated = $request->validate([
            'quote_estimate_term' => ['nullable', 'string', 'max:2000'],
        ], ['quote_estimate_term.max' => 'Keep the estimate wording to 2000 characters or fewer.']);

        $text = $request->boolean('restore_default') ? null : trim((string) ($validated['quote_estimate_term'] ?? ''));
        $text = ($text === '' || $text === trim(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM)) ? null : $text;

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agencyId], ['quote_estimate_term' => $text]);

        return redirect()->route('corex.settings.rental-work-orders.edit')
            ->with('success', $text === null ? 'Estimate wording restored to the standard text.' : 'Estimate wording saved.');
    }
}
