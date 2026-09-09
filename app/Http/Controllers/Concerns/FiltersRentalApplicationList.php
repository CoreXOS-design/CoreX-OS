<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Johan's permanent CRUD standard, restated on the 2026-09-09 design-standard
 * audit: "we always need proper crud? search / sort / own / branch / agency
 * levels. that should be the design standard. not me asking for it once we
 * get to that stage." Originally built on RentalApplicationController's
 * index()/returned() (2026-09-08); extracted here so the authoriser queue
 * (RentalApplicationAuthorisationController::index()) reuses the exact same
 * search/sort logic rather than a third hand-rolled copy.
 *
 * Search fields: contact full name/email, the application's own captured
 * full_name/email/id_number (Fill & Review data can differ from — or exist
 * without — a linked Contact), property address (linked Property + the
 * free-text override), the creating agent's name, and the application id
 * itself as the "reference" (an agent quoting "#42"). Agent added
 * 2026-09-09 for the authoriser queue's own search requirement — via
 * whereHas (a subquery), not a join, so it never touches the ambiguous-
 * column problem the sort logic below has to guard against explicitly.
 */
trait FiltersRentalApplicationList
{
    /**
     * @param string $dateColumn column date_from/date_to filter against
     * @param string $defaultSort column (unqualified) used when no ?sort= is given
     * @param string $defaultDirection 'asc'|'desc' used when no ?direction= is given
     *   — defaults to 'desc' (unchanged for index()/returned()'s existing
     *   newest-first behaviour). The authoriser queue passes 'asc': its
     *   whole point is working the OLDEST pending decision first, so
     *   sorting must default to oldest-first, not silently flip to
     *   newest-first the moment this became sortable.
     */
    private function applySearchSortAndDateRange($query, Request $request, string $dateColumn, string $defaultSort, string $defaultDirection = 'desc')
    {
        // 2026-09-08 — every column below is qualified with the
        // rental_applications table explicitly. Found over real HTTP (not
        // by reading the blade — a plain sort=contact/property request
        // 500'd): contacts, users, AND properties all carry their own id,
        // email, id_number, status, created_at, updated_at (and
        // contacts/users also branch_id/created_by_user_id — see
        // RentalApplication::scopeVisibleTo()). The moment this query is
        // combined with a LEFT JOIN to any of those tables (which sorting
        // by Applicant/Property/Agent already does, below), an unqualified
        // column throws SQLSTATE 1052 "ambiguous". Qualifying every
        // reference here fixes all of them at once, in the one place
        // they're all built.
        if ($request->filled('q')) {
            $q = trim((string) $request->string('q'));
            $query->where(function ($w) use ($q) {
                $w->where('rental_applications.id', 'like', "%{$q}%")
                    ->orWhere('rental_applications.full_name', 'like', "%{$q}%")
                    ->orWhere('rental_applications.email', 'like', "%{$q}%")
                    ->orWhere('rental_applications.id_number', 'like', "%{$q}%")
                    ->orWhere('rental_applications.property_address_override', 'like', "%{$q}%")
                    ->orWhereHas('contact', fn ($c) => $c->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%"))
                    ->orWhereHas('property', fn ($p) => $p->where('address', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%"))
                    ->orWhereHas('createdBy', fn ($u) => $u->where('name', 'like', "%{$q}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('rental_applications.status', $request->string('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate("rental_applications.{$dateColumn}", '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate("rental_applications.{$dateColumn}", '<=', $request->date('date_to'));
        }

        $sortable = [
            'contact'  => 'contacts.last_name',
            'property' => 'properties.address',
            'status'   => 'rental_applications.status',
            'date'     => "rental_applications.{$dateColumn}",
            'updated'  => 'rental_applications.updated_at',
            'agent'    => 'users.name',
        ];
        $sort = $sortable[$request->string('sort')->toString()] ?? "rental_applications.{$defaultSort}";
        $direction = $request->filled('direction')
            ? ($request->string('direction')->toString() === 'asc' ? 'asc' : 'desc')
            : $defaultDirection;

        if ($sort === 'users.name') {
            $query->leftJoin('users', 'users.id', '=', 'rental_applications.created_by_user_id')
                ->orderBy('users.name', $direction)
                ->select('rental_applications.*');
        } elseif (in_array($sort, ['contacts.last_name', 'properties.address'], true)) {
            $query->leftJoin($sort === 'contacts.last_name' ? 'contacts' : 'properties', $sort === 'contacts.last_name' ? 'contacts.id' : 'properties.id', '=', $sort === 'contacts.last_name' ? 'rental_applications.contact_id' : 'rental_applications.property_id')
                ->orderBy($sort, $direction)
                ->select('rental_applications.*');
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query;
    }
}
