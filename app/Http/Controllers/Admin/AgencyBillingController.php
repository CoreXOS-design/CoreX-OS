<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\UpdateAgencySubscriptionRequest;
use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Services\Billing\BillingQuote;
use App\Services\Billing\SubscriptionPricingService;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * System Developer → Agency Billing. Every agency, what they owe, and the
 * controls to override it.
 *
 * Spec: .ai/specs/agency-billing.md §8.2  (AT-11)
 *
 * OWNER-ONLY, and deliberately NOT behind a permission key. A permission key is
 * grantable through Role Manager — hand it to an agency admin by accident and
 * they can read (and set) every other agency's commercial terms. Same rationale
 * as Dev Settings / Demo Access (routes/web.php).
 *
 * Cross-agency reads use the approved escape hatch from
 * .ai/specs/multi-tenancy.md rule #5: gate the UI on isOwnerRole() and call
 * queryWithoutAgencyScope() explicitly — never withoutGlobalScope() in request
 * code.
 */
class AgencyBillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionPricingService $pricing,
        private readonly SubscriptionReconciler $reconciler,
    ) {
        // `owner_only` middleware applied at the route level; re-checked below.
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()?->isOwnerRole(), 403);

        $agencies = Agency::query()
            ->withCount('branches')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($agencies as $agency) {
            // Reconcile before quoting so the table can never show a plan that
            // disagrees with its own seat count. Idempotent; a no-op unless the
            // headcount actually crossed the threshold since we last looked.
            $this->reconciler->reconcile($agency);

            $quote = $this->pricing->quoteFor($agency->refresh());

            $rows[] = [
                'agency'       => $agency,
                'quote'        => $quote,
                'subscription' => AgencySubscription::forAgency((int) $agency->id),
            ];
        }

        return view('admin.billing.index', [
            'priceList' => \App\Models\Billing\BillingPriceList::asOf(),
            'scheduledLists' => \App\Models\Billing\BillingPriceList::scheduled(),
            'rows'    => $rows,
            'totals'  => $this->totals($rows),
        ]);
    }

    /**
     * Set an agency's commercial terms.
     *
     * The `mode` field carries decision D5 (custom amount XOR discount). We
     * write the ENTIRE term-set every time — nulling the fields the chosen mode
     * does not use — rather than patching individual columns. That is what
     * guarantees a row can never end up holding both a custom amount and a
     * discount: there is no code path that writes one without clearing the
     * other.
     */
    public function update(UpdateAgencySubscriptionRequest $request, Agency $agency): RedirectResponse
    {
        abort_unless($request->user()?->isOwnerRole(), 403);

        $subscription = AgencySubscription::forAgency((int) $agency->id);
        $mode = (string) $request->validated('mode');

        $terms = match ($mode) {
            'custom' => [
                'custom_amount_zar'  => $request->validated('custom_amount_zar'),
                'custom_amount_note' => $request->validated('custom_amount_note'),
                'discount_percent'   => null,
                'discount_months'    => null,
                'discount_starts_on' => null,
                'discount_note'      => null,
            ],
            'discount' => [
                'custom_amount_zar'  => null,
                'custom_amount_note' => null,
                'discount_percent'   => $request->validated('discount_percent'),
                'discount_months'    => $request->validated('discount_months'),
                'discount_starts_on' => $request->validated('discount_starts_on'),
                'discount_note'      => $request->validated('discount_note'),
            ],
            default => [   // automatic — price follows headcount, nothing overrides it
                'custom_amount_zar'  => null,
                'custom_amount_note' => null,
                'discount_percent'   => null,
                'discount_months'    => null,
                'discount_starts_on' => null,
                'discount_note'      => null,
            ],
        };

        $terms['notes'] = $request->validated('notes');

        $subscription->forceFill($terms)->save();

        return redirect()
            ->route('admin.billing.index')
            ->with('success', $this->confirmation($mode, $agency, $subscription));
    }

    /** Say what actually changed, in words, rather than "Saved!". */
    private function confirmation(string $mode, Agency $agency, AgencySubscription $subscription): string
    {
        return match ($mode) {
            'custom' => "{$agency->name} is now on a custom amount of "
                . \App\Support\Money\Zar::format((float) $subscription->custom_amount_zar) . '/month.',
            'discount' => "{$agency->name} is now on a {$subscription->discount_percent}% discount for "
                . $subscription->discount_months . ' month(s), from '
                . $subscription->discount_starts_on->format('j M Y') . '.',
            default => "{$agency->name} is back on automatic pricing — the price now follows their headcount.",
        };
    }

    /**
     * @param  list<array{agency:Agency,quote:BillingQuote,subscription:AgencySubscription}>  $rows
     */
    private function totals(array $rows): array
    {
        $quotes = array_column($rows, 'quote');

        return [
            'mrr_zar'       => round(array_sum(array_map(fn (BillingQuote $q) => $q->payableZar, $quotes)), 2),
            'list_zar'      => round(array_sum(array_map(fn (BillingQuote $q) => $q->computedZar, $quotes)), 2),
            'discount_zar'  => round(array_sum(array_map(fn (BillingQuote $q) => $q->savingZar(), $quotes)), 2),
            'seats'         => array_sum(array_map(fn (BillingQuote $q) => $q->seats, $quotes)),
            'on_team'       => count(array_filter($quotes, fn (BillingQuote $q) => $q->derivedPlan === AgencySubscription::PLAN_TEAM)),
            'on_agency'     => count(array_filter($quotes, fn (BillingQuote $q) => $q->derivedPlan === AgencySubscription::PLAN_AGENCY)),
            'on_bands'      => count(array_filter($quotes, fn (BillingQuote $q) => $q->usesBands)),
            'on_quotation'  => count(array_filter($quotes, fn (BillingQuote $q) => $q->onQuotation)),
        ];
    }

    /** A new price list with an effective-from date (today or later). Earlier lists stay as they were: history is never rewritten. */
    public function savePrices(Request $request)
    {
        abort_unless($request->user()?->isOwnerRole(), 403);
        $data = $request->validate([
            'effective_from' => 'required|date|after_or_equal:today',
            'bands' => 'required|array|min:1|max:20', 'bands.*.from' => 'required|integer|min:1', 'bands.*.to' => 'required|integer|min:1|gte:bands.*.from',
            'bands.*.amount' => 'required|numeric|min:0|max:100000000', 'branch_rate' => 'required|numeric|min:0|max:100000000',
        ]);
        $bands = collect($data['bands'])->map(fn ($b) => ['from' => (int) $b['from'], 'to' => (int) $b['to'], 'amount' => (float) $b['amount']])->sortBy('from')->values()->all();
        $expected = 1;
        foreach ($bands as $b) {
            if ($b['from'] !== $expected) {
                return back()->withInput()->withErrors(['bands' => 'The bands must follow on from each other starting at 1 agent, with no gaps or overlaps.']);
            }
            $expected = $b['to'] + 1;
        }
        \App\Models\Billing\BillingPriceList::create(['effective_from' => $data['effective_from'], 'bands' => $bands, 'branch_rate' => $data['branch_rate'], 'created_by' => $request->user()->id]);

        return redirect()->route('admin.billing.index')->with('success', 'Price list saved. It applies from ' . \Illuminate\Support\Carbon::parse($data['effective_from'])->format('j M Y') . '; earlier months keep the price they had.');
    }
}
