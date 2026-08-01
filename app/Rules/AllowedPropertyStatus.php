<?php

namespace App\Rules;

use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AT-307 — request-layer membership check for `properties.status`.
 *
 * Gives a clean 422 (instead of the PropertyObserver's hard throw) when a web
 * or mobile-API request tries to persist an out-of-vocabulary status. The
 * vocabulary is DERIVED per-agency from Property::allowedStatuses() —
 * systemStatuses() ∪ the agency's active settings-defined property_status list —
 * so it never drifts from Settings. Case-insensitive (properties.status is
 * genuinely mixed-case: the P24 sync writes 'Active'/'Sold'/'Rented').
 *
 * A null/empty value passes here (the field's own `nullable` rule governs
 * emptiness); this rule only judges a provided value.
 */
class AllowedPropertyStatus implements ValidationRule
{
    public function __construct(private ?int $agencyId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $agencyId = $this->agencyId ?? optional(auth()->user())->effectiveAgencyId();

        if (! Property::isAllowedStatus((string) $value, $agencyId ? (int) $agencyId : null)) {
            $fail('The selected status is not a recognised property status. Add it under Settings → Property Status first.');
        }
    }
}
