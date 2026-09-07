<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Services\RentalApplications\RentalApplicationMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AT-392 — Rental Applications, Phase 1. Spec: .ai/specs/rental-applications.md
 *
 * Dedicated page, deliberately NOT the e-sign wizard: the agent never signs
 * this document, and every field is optional (contact is the only required
 * link). See the spec's "Why" section for the full reasoning.
 */
class RentalApplicationController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesRentalApplicationAccess;

    /**
     * Search fields (spec §"Agent-side hardening"): contact full name,
     * contact email, property address (both the linked Property's own
     * address and the free-text property_address_override), and the
     * application id itself as the "reference" (an agent quoting "#42").
     * Sortable columns: contact name, property, status, sent date. Default
     * sort: created_at desc (newest first) — unchanged from before this
     * standard, now explicit and user-controllable.
     */
    private function applySearchSortAndDateRange($query, Request $request, string $dateColumn, string $defaultSort)
    {
        if ($request->filled('q')) {
            $q = trim((string) $request->string('q'));
            $query->where(function ($w) use ($q) {
                $w->where('id', 'like', "%{$q}%")
                    ->orWhere('property_address_override', 'like', "%{$q}%")
                    ->orWhereHas('contact', fn ($c) => $c->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%"))
                    ->orWhereHas('property', fn ($p) => $p->where('address', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate($dateColumn, '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate($dateColumn, '<=', $request->date('date_to'));
        }

        $sortable = ['contact' => 'contacts.last_name', 'property' => 'properties.address', 'status' => 'status', 'date' => $dateColumn];
        $sort = $sortable[$request->string('sort')->toString()] ?? $defaultSort;
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (in_array($sort, ['contacts.last_name', 'properties.address'], true)) {
            $query->leftJoin($sort === 'contacts.last_name' ? 'contacts' : 'properties', $sort === 'contacts.last_name' ? 'contacts.id' : 'properties.id', '=', $sort === 'contacts.last_name' ? 'rental_applications.contact_id' : 'rental_applications.property_id')
                ->orderBy($sort, $direction)
                ->select('rental_applications.*');
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query;
    }

    public function index(Request $request): View
    {
        $query = RentalApplication::visibleTo($request->user())
            ->with(['contact', 'property'])
            ->whereNotIn('status', ['returned', 'under_assessment', 'approved', 'declined']);

        $this->applySearchSortAndDateRange($query, $request, 'created_at', 'created_at');

        $applications = $query->paginate(25)->withQueryString();

        $archived = null;
        if ($request->boolean('archived')) {
            $archived = RentalApplication::visibleTo($request->user())
                ->onlyTrashed()
                ->with(['contact', 'property'])
                ->orderByDesc('deleted_at')
                ->paginate(25, ['*'], 'archived_page')
                ->withQueryString();
        }

        return view('corex.rental-applications.index', compact('applications', 'archived'));
    }

    public function returned(Request $request): View
    {
        $query = RentalApplication::visibleTo($request->user())
            ->with(['contact', 'property', 'signatures'])
            ->whereIn('status', ['returned', 'under_assessment', 'approved', 'declined', 'withdrawn']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $this->applySearchSortAndDateRange($query, $request, 'submitted_at', 'submitted_at');

        $applications = $query->paginate(25)->withQueryString();

        return view('corex.rental-applications.returned', compact('applications'));
    }

    public function create(): View
    {
        return view('corex.rental-applications.create');
    }

    /**
     * Lightweight property picker for the create page — deliberately its
     * own endpoint under this feature's own permission, rather than
     * borrowing another feature's search route (e.g. the filing register's,
     * gated on a different permission an agent here may not hold).
     */
    public function searchProperties(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $properties = Property::query()
            ->where('listing_type', 'rental')
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('address', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%");
            }))
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'address', 'title', 'suburb']);

        return response()->json($properties->map(fn (Property $p) => [
            'id' => $p->id,
            'label' => $p->title ?: trim($p->address . ', ' . $p->suburb, ', '),
        ]));
    }

    /**
     * AT-392 spec §1 — contact required, everything else optional. Nothing
     * here may block a send (BUILD_STANDARD §2, the input-space rule).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);

        $contact = Contact::findOrFail($validated['contact_id']);

        $application = RentalApplication::create(array_merge(
            $this->prefillFromContact($contact),
            [
                'contact_id' => $contact->id,
                'property_id' => $validated['property_id'] ?? null,
                'branch_id' => $request->user()->effectiveBranchId(),
                'created_by_user_id' => $request->user()->id,
                'status' => 'sent',
            ],
        ));

        return redirect()
            ->route('corex.rental-applications.show', $application)
            ->with('success', 'Rental application created. Review it, then send.');
    }

    public function show(Request $request, RentalApplication $rentalApplication): View
    {
        $this->guardRentalApplication($rentalApplication);
        $rentalApplication->load(['contact', 'property', 'signatures', 'documents.documentType']);

        return view('corex.rental-applications.show', compact('rentalApplication'));
    }

    public function update(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate(array_merge(RentalApplication::fieldValidationRules(), [
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]));

        $fields = collect($validated)->except(['property_id'])->all();
        $fields = array_map(fn ($v) => $v === '' ? null : $v, $fields);

        $rentalApplication->update(array_merge(
            ['property_id' => $validated['property_id'] ?? $rentalApplication->property_id],
            $fields,
        ));

        return redirect()
            ->route('corex.rental-applications.show', $rentalApplication)
            ->with('success', 'Saved.');
    }

    /**
     * AT-392 spec §4 — one send, two return routes, applicant's choice. The
     * token is generated unconditionally (needed either way: route (a)'s
     * upload-back link and route (b)'s online link are the SAME token) —
     * mirrors SignatureService::generateToken()'s shape (Str::random(64),
     * uniqueness loop) and its 14-day expiry convention.
     */
    public function send(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        if (! $rentalApplication->token) {
            $rentalApplication->token = $this->generateToken();
            $rentalApplication->token_expires_at = now()->addDays(14);
        }
        $rentalApplication->status = 'sent';
        $rentalApplication->save();

        $mailSent = false;
        if ($rentalApplication->contact->email) {
            $mailSent = app(RentalApplicationMailer::class)->sendInvite($rentalApplication);
        }

        $message = $mailSent
            ? 'Sent to ' . $rentalApplication->contact->email . '. Both the download link and the online link are on this page too, if you want to share them another way.'
            : 'This contact has no email on file — share the links below directly (WhatsApp, print, etc).';

        return redirect()
            ->route('corex.rental-applications.show', $rentalApplication)
            ->with('success', $message);
    }

    public function pdf(RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $service = app(\App\Services\RentalApplications\RentalApplicationPdfService::class);
        $path = $service->generate($rentalApplication);

        return response()->download($path, 'Rental Application - ' . ($rentalApplication->full_name ?: $rentalApplication->contact->full_name) . '.pdf')
            ->deleteFileAfterSend(true);
    }

    /**
     * BUILD_STANDARD §1 — full CRUD is the floor. No hard deletes anywhere in
     * CoreX (STANDARDS.md) — RentalApplication already has SoftDeletes, this
     * is the archive action the index/show screens were missing.
     */
    public function destroy(RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $rentalApplication->delete();

        return redirect()
            ->route('corex.rental-applications.index')
            ->with('success', 'Rental application archived.');
    }

    /**
     * Johan, 2026-09-07 — full CRUD includes "restore from archive," not
     * just archive. Route-model-binding on a soft-deleted row 404s by
     * default, so the {rentalApplication} parameter is bound explicitly
     * withTrashed() here — the only action in this controller that needs to.
     */
    public function restore(Request $request, int $rentalApplication)
    {
        $application = RentalApplication::withTrashed()->findOrFail($rentalApplication);
        $this->guardRentalApplication($application);

        $application->restore();

        return redirect()
            ->route('corex.rental-applications.index', ['archived' => 1])
            ->with('success', 'Rental application restored.');
    }

    /**
     * Supporting-document download for the agent side (spec §5). Both
     * $rentalApplication and $document implicitly scope via their own
     * BelongsToAgency global scope (a cross-agency id 404s at route-model-
     * binding, before this method ever runs) — the explicit source_type/
     * source_id check below is defense-in-depth against a same-agency
     * agent guessing a document id that belongs to a DIFFERENT application.
     * The own/branch/agency guard below covers the finer-grained visibility
     * tier on top of that agency-level check.
     */
    public function downloadDocument(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardRentalApplication($rentalApplication);

        abort_unless(
            $document->source_type === 'rental_application' && (int) $document->source_id === $rentalApplication->id,
            404
        );

        return $document->downloadResponse();
    }

    /**
     * A token must be unique ACROSS every agency, not just the acting user's
     * own — two different agencies' applications must never collide. Uses the
     * model's own sanctioned cross-tenant escape hatch (BelongsToAgency::
     * queryWithoutAgencyScope()), never a raw withoutGlobalScope() call in
     * request code (CLAUDE.md Non-negotiable #7).
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (RentalApplication::queryWithoutAgencyScope()->where('token', $token)->exists());

        return $token;
    }

    /**
     * AT-392 spec §1 — prefill wherever a V8 field maps to a real contacts
     * column (see the AT-332 investigation: marital_status, spouse_name,
     * spouse_id, citizenship and a distinct work number do NOT exist on
     * contacts — only these five genuinely map).
     */
    private function prefillFromContact(Contact $contact): array
    {
        return [
            'full_name' => $contact->full_name,
            'id_number' => $contact->id_number,
            'email' => $contact->email,
            'cell' => $contact->phone,
            'current_residential_address' => trim(implode(', ', array_filter([
                $contact->address, $contact->suburb, $contact->city,
            ]))),
        ];
    }

}
