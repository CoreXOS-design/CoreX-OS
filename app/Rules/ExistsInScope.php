<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates that a foreign-key value resolves to an existing model *within the
 * caller's active Eloquent global scopes* — most importantly AgencyScope
 * (multi-tenancy) and SoftDeletes.
 *
 * Why this exists: Laravel's built-in `exists:` rule queries the raw table via
 * the database presence verifier, which BYPASSES Eloquent global scopes. That
 * let cross-agency and soft-deleted IDs pass validation and then be attached as
 * foreign keys (an IDOR / cross-tenant data-join vector). Use this rule for
 * every tenant-owned FK (properties, contacts, deals, …) instead of `exists:`.
 *
 * Usage:
 *   'property_id' => ['required', new ExistsInScope(\App\Models\Property::class)],
 *   'contact_id'  => ['nullable', new ExistsInScope(\App\Models\Contact::class)],
 */
class ExistsInScope implements ValidationRule
{
    /**
     * @param  class-string<Model>  $modelClass  The Eloquent model to resolve through.
     * @param  string  $column  Column to match against (defaults to the primary key `id`).
     */
    public function __construct(
        protected string $modelClass,
        protected string $column = 'id',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Leave emptiness to required/nullable; only validate present values.
        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            return;
        }

        $query = $this->modelClass::query();

        if (is_array($value)) {
            $found = $query->whereIn($this->column, $value)->count();
            if ($found !== count(array_unique($value))) {
                $fail('The selected :attribute is invalid.');
            }

            return;
        }

        if (! $query->where($this->column, $value)->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
