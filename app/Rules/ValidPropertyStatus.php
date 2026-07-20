<?php

namespace App\Rules;

use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AT-307 — request-layer membership gate for a property status. Any status the
 * client actually sends must be in Property::ALLOWED_STATUSES (case-insensitive);
 * a garbage / typo / empty value fails with a clean 422 instead of a 500 from the
 * PropertyObserver saving-guard. Pair with `nullable`/`sometimes` so an absent
 * status is left to the column default — this rule only judges values that arrive.
 */
class ValidPropertyStatus implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // `nullable` short-circuits a genuine null; a present-but-invalid value
        // (including an empty string) is what we reject.
        if ($value === null) {
            return;
        }

        if (! Property::isValidStatus(is_string($value) ? $value : (string) $value)) {
            $fail('The selected status is not a recognised property status.');
        }
    }
}
