<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Audit fixes 19 Oct 2026 (C-D6 / A28, spec rentals-faults-work-orders.md section 29): the id must belong to the signed-in user's OWN agency, and an id that does not
 * answers 404 - the same answer for "another agency's record" and "no such record". A bare `exists:table,id` rule is a raw, unscoped query: it says 422 for an id
 * that does not exist and passes one that exists in ANOTHER agency, so the difference between the two answers tells a caller which foreign ids are real (an
 * existence oracle). Use this for every agency-owned id in a rentals request.
 */
final class FoundInAgency implements ValidationRule
{
    public function __construct(private string $table, private string $column = 'id')
    {
    }

    public static function in(string $table, string $column = 'id'): self
    {
        return new self($table, $column);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $agencyId = auth()->user()?->effectiveAgencyId();
        $found = is_scalar($value) && $agencyId
            && DB::table($this->table)->where($this->column, $value)->where('agency_id', $agencyId)
                ->when(\Illuminate\Support\Facades\Schema::hasColumn($this->table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->exists();
        if (! $found) {
            abort(404);
        }
    }
}
