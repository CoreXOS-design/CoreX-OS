<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Support\Money\Zar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a CoreX developer setting an agency's commercial terms.
 *
 * Spec: .ai/specs/agency-billing.md §10  (AT-11)
 *
 * The `mode` field is what enforces decision D5 — custom amount and discount
 * are MUTUALLY EXCLUSIVE and can never both be set. Rather than validating two
 * optional blocks against each other and hoping, the form declares which ONE of
 * three states it is putting the agency into:
 *
 *   automatic  — price follows headcount. Clears both.
 *   custom     — a fixed amount. Clears any discount.
 *   discount   — a % off the computed price for N months. Clears any custom amount.
 *
 * That makes the invariant structural: there is no shape of valid input that
 * produces both. (The service nulls the counterpart on write anyway — belt and
 * braces, because the invariant protects revenue.)
 */
class UpdateAgencySubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Owner-only — the route also carries `owner_only`, and the controller
        // re-checks. A permission key is deliberately NOT used: it would be
        // grantable via Role Manager, and an agency admin who was handed it
        // would see (and set) every other agency's commercial terms.
        return (bool) $this->user()?->isOwnerRole();
    }

    /**
     * Tolerate what a human types. "R5 000", "5,000.00" and "5000" all mean the
     * same thing, and rejecting the first two would be us being precious about
     * our own formatting. Zar::parse() normalises; validation then judges the
     * NUMBER, not the typing.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('custom_amount_zar')) {
            $parsed = Zar::parse((string) $this->input('custom_amount_zar'));

            // Leave an unparseable value alone so the `numeric` rule rejects it
            // with a message, rather than silently coercing it to null/0.
            if ($parsed !== null) {
                $this->merge(['custom_amount_zar' => $parsed]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:automatic,custom,discount'],

            // Required WHEN in custom mode — and prohibited otherwise, so a
            // stale field left in the POST body cannot leak into a discount.
            'custom_amount_zar' => [
                'exclude_unless:mode,custom',
                'required',
                'numeric',
                'min:0',            // 0 is legal — "free, deliberately". NULL means "no override".
                'max:9999999.99',
            ],
            'custom_amount_note' => ['exclude_unless:mode,custom', 'nullable', 'string', 'max:255'],

            'discount_percent' => [
                'exclude_unless:mode,discount',
                'required',
                'numeric',
                'min:0.01',         // a 0% discount is not a discount — use Automatic
                'max:100',
            ],
            'discount_months' => [
                'exclude_unless:mode,discount',
                'required',
                'integer',
                'min:1',
                'max:120',
            ],
            'discount_starts_on' => ['exclude_unless:mode,discount', 'required', 'date'],
            'discount_note'      => ['exclude_unless:mode,discount', 'nullable', 'string', 'max:255'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Messages a person can act on — not "The discount percent field is
     * required." with no clue as to why it suddenly is.
     */
    public function messages(): array
    {
        return [
            'custom_amount_zar.required' => 'Enter the custom monthly amount, or switch to Automatic pricing.',
            'custom_amount_zar.numeric'  => 'The custom amount must be a number, for example 5000 or R5 000.',
            'custom_amount_zar.min'      => 'The custom amount cannot be negative. Enter 0 to make this agency free.',

            'discount_percent.required' => 'Enter the discount percentage, or switch to Automatic pricing.',
            'discount_percent.min'      => 'A discount must be at least 0.01%. To remove a discount, switch to Automatic.',
            'discount_percent.max'      => 'A discount cannot exceed 100%.',

            'discount_months.required' => 'Enter how many months the discount runs for.',
            'discount_months.min'      => 'A discount must run for at least 1 month.',
            'discount_months.max'      => 'A discount cannot run for more than 120 months (10 years).',

            'discount_starts_on.required' => 'Choose the date the discount starts.',
        ];
    }
}
