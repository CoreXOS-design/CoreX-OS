<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentalPropertyWorkTermChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-work-orders.md §17.6.2 — the owner's work terms per rental property: the no-approval limit (R) and the
 * variation tolerance (%). One place to edit both ("Work terms agreed with the owner" on the property's Rental tab), its own
 * permission (rental_work_orders.manage_work_terms), and an append-only history of every change: old value, new value (blank =
 * "use the agency default"), who, when and how/when it was agreed with the owner. Scoped like every other property write
 * (authorizeProperty — OWN / BRANCH / AGENCY), settled rental properties only.
 */
class RentalPropertyWorkTermsController extends Controller
{
    use AuthorizesPropertyAccess;

    /** field => the properties column it lives in */
    private const COLUMNS = [
        RentalPropertyWorkTermChange::FIELD_NO_APPROVAL_LIMIT => 'rental_no_approval_spend_threshold',
        RentalPropertyWorkTermChange::FIELD_VARIATION_TOLERANCE => 'rental_variation_tolerance_percent',
    ];

    public function update(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeProperty($property);

        abort_if(
            strtolower((string) $property->listing_type) !== 'rental' || $property->listing_type_pending,
            403,
            'This property is not a settled rental listing.'
        );

        $data = $request->validate([
            // A blank box means "use the agency default" — that is a value (null), not a missing field.
            'no_approval_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'variation_tolerance' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'agreed_with' => ['nullable', 'string', 'max:255'],
        ], [
            'no_approval_limit.numeric' => 'The no-approval limit must be an amount in rand (or left blank to use the agency default).',
            'variation_tolerance.numeric' => 'The tolerance must be a percentage (or left blank to use the agency default).',
            'variation_tolerance.max' => 'The tolerance cannot be more than 100 %.',
        ]);

        $agreedWith = isset($data['agreed_with']) ? trim((string) $data['agreed_with']) : null;
        $agreedWith = $agreedWith === '' ? null : $agreedWith;
        $user = $request->user();
        $changed = [];

        DB::transaction(function () use ($property, $data, $agreedWith, $user, &$changed) {
            foreach (self::COLUMNS as $field => $column) {
                // The form posts both boxes together; a request that omits one entirely leaves it untouched.
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $property->{$column} !== null ? (float) $property->{$column} : null;
                $new = $data[$field] !== null && $data[$field] !== '' ? round((float) $data[$field], 2) : null;
                if (($old === null) === ($new === null) && ($old === null || abs($old - $new) < 0.005)) {
                    continue;
                }

                RentalPropertyWorkTermChange::create([
                    'agency_id' => $property->agency_id,
                    'property_id' => $property->id,
                    'field' => $field,
                    'old_value' => $old,
                    'new_value' => $new,
                    'changed_by_user_id' => $user->id,
                    'changed_at' => now(),
                    'agreed_with' => $agreedWith,
                    'note' => null,
                ]);
                $property->forceFill([$column => $new]);
                $changed[] = $field;
            }

            if ($changed) {
                $property->forceFill([
                    'rental_work_terms_updated_at' => now(),
                    'rental_work_terms_updated_by_user_id' => $user->id,
                ])->save();
            }
        });

        return redirect()->to(route('corex.properties.show', $property) . '?tab=rental')
            ->with('success', $changed ? 'Work terms saved.' : 'Nothing changed — the work terms are as they were.');
    }
}
