<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\Property;
use App\Models\Scopes\ContactScope;
use App\Rules\ExistsInScope;
use App\Services\ContactDuplicateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PropertyContactController extends Controller
{
    use AuthorizesPropertyAccess;

    /** Search contacts globally — used for the property create form (no property ID yet). */
    public function searchGlobal(Request $request)
    {
        $q       = trim($request->query('q', ''));
        $exclude = array_filter(array_map('intval', (array) $request->query('exclude', [])));

        // Link picker searches the whole AGENCY, not the agent's personal workspace.
        // The role-based ContactScope ('own'/'branch') is a read filter for the
        // contacts LIST — applying it here would hide contacts captured by other
        // agents and force duplicate creation, violating Non-Negotiable #10
        // (Universal Match-or-Create). AgencyScope + soft-deletes still apply.
        $query = Contact::withoutGlobalScope(ContactScope::class)->limit(10);

        if ($exclude) {
            $query->whereNotIn('id', $exclude);
        }

        $this->applyNameSearch($query, $q);
        $this->applyRelevanceOrder($query, $q);
        $query->orderBy('last_name')->orderBy('first_name');

        return response()->json($query->get(['id', 'first_name', 'last_name', 'phone', 'email']));
    }

    /** Search contacts (AJAX JSON) for the link picker. */
    public function search(Request $request, Property $property)
    {
        $q = trim($request->query('q', ''));

        // Link picker searches the whole AGENCY, not the agent's personal workspace.
        // ContactScope ('own'/'branch') is bypassed so an agent can link any existing
        // agency contact (Non-Negotiable #10: Match-or-Create) instead of being shown
        // nothing and creating duplicates. AgencyScope + soft-deletes still apply.
        // The linked-contact exclusion list must also bypass ContactScope —
        // otherwise an agent's "already linked" set comes back empty for contacts
        // captured by others, and those contacts wrongly reappear in the picker.
        $linkedIds = $property->contacts()
            ->withoutGlobalScope(ContactScope::class)
            ->pluck('contacts.id');

        $query = Contact::withoutGlobalScope(ContactScope::class)
            ->with('type')
            ->whereNotIn('id', $linkedIds)
            ->limit(10);

        $this->applyNameSearch($query, $q);
        $this->applyRelevanceOrder($query, $q);
        $query->orderBy('last_name')->orderBy('first_name');

        return response()->json($query->get(['id', 'first_name', 'last_name', 'phone', 'email', 'contact_type_id']));
    }

    /**
     * Apply a multi-word name/phone/email filter to a Contact query.
     *
     * The query is split into words and each word must match at least one field
     * (words AND-ed together, fields OR-ed within a word). This is what lets
     * "Andre Test" find first_name="Andre" + last_name="Test" — a single
     * LIKE %Andre Test% never matches because no one column holds both words.
     * Mirrors the Contacts index search (ContactController::index).
     */
    private function applyNameSearch($query, string $q): void
    {
        $words = array_filter(explode(' ', trim($q)));
        foreach ($words as $word) {
            $query->where(function ($qb) use ($word) {
                $qb->where('first_name', 'like', "%{$word}%")
                   ->orWhere('last_name', 'like', "%{$word}%")
                   ->orWhere('phone',     'like', "%{$word}%")
                   ->orWhere('email',     'like', "%{$word}%");
            });
        }
    }

    /**
     * Rank results by match strength so the closest names surface first:
     *   tier 0 — exact match (first/last name or full name equals the query)
     *   tier 1 — prefix match (name starts with the query, e.g. "Andrea"/"Andrew" for "Andre")
     *   tier 2 — everything else that still matched the filter (contains)
     * Alphabetical (last, first) breaks ties within a tier. No-op for an empty query.
     */
    private function applyRelevanceOrder($query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        $lower = mb_strtolower($q);
        $prefix = $lower . '%';
        $fullName = "LOWER(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";

        $query->orderByRaw(
            "CASE
                WHEN LOWER(first_name) = ? OR LOWER(last_name) = ? OR {$fullName} = ? THEN 0
                WHEN LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR {$fullName} LIKE ? THEN 1
                ELSE 2
            END",
            [$lower, $lower, $lower, $prefix, $prefix, $prefix]
        );
    }

    /**
     * Canonical property↔contact roles. The seller-side subset
     * (owner/seller/landlord/lessor) is what the compliance gate and the rest
     * of CoreX treat as "seller" — a role MUST be one of these so the pivot is
     * never NULL/free-text again (root cause of the FICA "no sellers linked"
     * bug). buyer/tenant are valid non-seller roles.
     */
    public const LINK_ROLES = ['seller', 'buyer', 'owner', 'landlord', 'tenant', 'lessor'];

    /**
     * Defensive normalisation: trim + lowercase the role before validation so
     * a stray " Seller " can never land off-canon in the pivot. Off-list or
     * blank values then fail validation with a clear message rather than
     * writing a NULL/variant role the compliance gate can't read.
     */
    private function normalizeRole(Request $request): void
    {
        $role = $request->input('role');
        if (is_string($role)) {
            $request->merge(['role' => strtolower(trim($role))]);
        }
    }

    /** Link an existing contact to the property. */
    public function link(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $this->normalizeRole($request);
        $data = $request->validate([
            'contact_id' => 'required|integer',
            'role'       => ['required', 'string', Rule::in(self::LINK_ROLES)],
        ]);

        // Resolve through the scoped model so the contact must be a live,
        // in-agency record. `exists:contacts,id` matched soft-deleted or
        // cross-agency rows (the rule ignores the SoftDeletes + AgencyScope
        // global scopes), which let an invalid contact get linked and then
        // crashed contactPayload() with a null contact.
        $contact = Contact::with('type')->find($data['contact_id']);
        if (! $contact) {
            return ($request->expectsJson() || $request->wantsJson())
                ? response()->json(['ok' => false, 'message' => 'Contact not found.'], 422)
                : back()->withErrors(['contact_id' => 'Contact not found.'])->with('tab', 'contacts');
        }

        $role = $data['role'];
        $property->contacts()->syncWithoutDetaching([
            $contact->id => ['role' => $role],
        ]);

        // Auto-create seller live link if seller role
        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
            \App\Models\PropertySellerLink::ensureExists($property->id, $contact->id);
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'ok'      => true,
                'count'   => $property->contacts()->count(),
                'contact' => $this->contactPayload($property, $contact, $role),
            ]);
        }

        return back()->with('success', 'Contact linked to property.')->with('tab', 'contacts');
    }

    /** Create a new contact AND link it to the property. */
    public function createAndLink(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $this->normalizeRole($request);
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'role'            => ['required', 'string', Rule::in(self::LINK_ROLES)],
            // A.2.5 — optional ID number with SA-format validation.
            'id_number'       => ['nullable', 'string', 'max:20', new \App\Rules\SouthAfricanIdNumber()],
            'bypass_duplicate_check' => 'nullable|boolean',
        ]);

        // A.2.5 — normalise + audit-tag the ID when supplied.
        $idNumber = isset($data['id_number']) ? preg_replace('/\s+/', '', (string) $data['id_number']) : null;
        unset($data['id_number']);  // we'll add it back together with audit fields after the dupe guard

        $role = $data['role'];
        unset($data['role']);

        $user = auth()->user();
        $agencyId = $user->effectiveAgencyId() ?? 1;
        $service = app(ContactDuplicateService::class);

        if (empty($data['bypass_duplicate_check'])) {
            $duplicates = $service->findDuplicates($data, $agencyId);
            if ($duplicates->isNotEmpty()) {
                $mode = $service->resolveMode($agencyId);
                if ($mode === 'auto_link') {
                    $existing = $duplicates->first();
                    $wasLinked = $property->contacts()->where('contacts.id', $existing->id)->exists();
                    $property->contacts()->syncWithoutDetaching([$existing->id => ['role' => $role]]);
                    if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
                        \App\Models\PropertySellerLink::ensureExists($property->id, $existing->id);
                    }
                    if (!$wasLinked) {
                        event(new \App\Events\Contact\ContactLinkedToProperty(
                            contact: $existing,
                            property: $property,
                            role: (string) ($role ?? 'unknown'),
                            actorUserId: auth()->id(),
                        ));
                    }
                    $match = $service->identifyMatch($data, $existing, $agencyId);
                    $service->logAttempt($agencyId, $user->id, $mode, $match['field'], $match['value'], $existing->id, $data, 'auto_linked');
                    if ($request->expectsJson() || $request->wantsJson()) {
                        return response()->json([
                            'ok'      => true,
                            'count'   => $property->contacts()->count(),
                            'contact' => $this->contactPayload($property, $existing->fresh('type'), $role),
                            'info'    => 'Existing contact found and linked.',
                        ]);
                    }
                    return back()->with('info', 'Existing contact found and linked.')->with('tab', 'contacts');
                }
                $duplicatesPayload = [
                    'duplicates' => $duplicates->map(fn($c) => [
                        'id' => $c->id, 'name' => $c->full_name,
                        'phone' => $mode === 'hard_block_request' ? null : $c->phone,
                        'email' => $mode === 'hard_block_request' ? null : $c->email,
                        'owner' => optional($c->createdBy)->name ?? 'Unknown',
                        'url' => route('corex.contacts.show', $c),
                    ])->toArray(),
                    'mode' => $mode,
                    'can_override' => $mode === 'hard_block_override' && in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner']),
                ];
                if ($request->expectsJson() || $request->wantsJson()) {
                    return response()->json([
                        'ok' => false,
                        'duplicate_detected' => $duplicatesPayload,
                    ], 409);
                }
                return back()->withInput()->with('duplicate_detected', $duplicatesPayload)->with('tab', 'contacts');
            }
        }

        unset($data['bypass_duplicate_check']);
        $data['created_by_user_id'] = $user->id;

        // A.2.5 — re-attach the ID + POPIA audit fields.
        if ($idNumber) {
            $data['id_number']             = $idNumber;
            $data['id_number_captured_at'] = now();
            $data['id_number_source']      = 'property_inline_create';
        }

        $contact = Contact::create($data);
        $property->contacts()->attach($contact->id, ['role' => $role]);
        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
            \App\Models\PropertySellerLink::ensureExists($property->id, $contact->id);
        }
        // Domain event — new contact↔property link.
        // Spec: .ai/specs/corex-domain-events-spec.md
        event(new \App\Events\Contact\ContactLinkedToProperty(
            contact: $contact,
            property: $property,
            role: (string) ($role ?? 'unknown'),
            actorUserId: auth()->id(),
        ));

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'ok'      => true,
                'count'   => $property->contacts()->count(),
                'contact' => $this->contactPayload($property, $contact->fresh('type'), $role),
            ]);
        }

        return back()->with('success', 'Contact created and linked.')->with('tab', 'contacts');
    }

    /** Unlink a contact from the property. */
    public function unlink(Request $request, Property $property, Contact $contact)
    {
        $this->authorizeProperty($property);

        $property->contacts()->detach($contact->id);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'ok'    => true,
                'count' => $property->contacts()->count(),
                'id'    => $contact->id,
            ]);
        }

        return back()->with('success', 'Contact unlinked.')->with('tab', 'contacts');
    }

    /** Shape a contact row for AJAX consumers (matches the Blade row layout). */
    private function contactPayload(Property $property, Contact $contact, ?string $role): array
    {
        $initials = strtoupper(mb_substr($contact->first_name ?? '', 0, 1) . mb_substr($contact->last_name ?? '', 0, 1));
        return [
            'id'         => $contact->id,
            'first_name' => $contact->first_name,
            'last_name'  => $contact->last_name,
            'full_name'  => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'initials'   => $initials !== '' ? $initials : '?',
            'phone'      => $contact->phone,
            'email'      => $contact->email,
            'role'       => $role,
            'type_color' => $contact->type?->color ?? '#334155',
            'show_url'   => route('corex.contacts.show', $contact),
        ];
    }
}
