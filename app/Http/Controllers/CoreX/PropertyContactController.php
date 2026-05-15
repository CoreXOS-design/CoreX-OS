<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\Property;
use App\Services\ContactDuplicateService;
use Illuminate\Http\Request;

class PropertyContactController extends Controller
{
    /** Search contacts globally — used for the property create form (no property ID yet). */
    public function searchGlobal(Request $request)
    {
        $q       = trim($request->query('q', ''));
        $exclude = array_filter(array_map('intval', (array) $request->query('exclude', [])));

        $query = Contact::orderBy('last_name')->orderBy('first_name')->limit(10);

        if ($exclude) {
            $query->whereNotIn('id', $exclude);
        }

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('first_name', 'like', "%{$q}%")
                   ->orWhere('last_name',  'like', "%{$q}%")
                   ->orWhere('phone',      'like', "%{$q}%")
                   ->orWhere('email',      'like', "%{$q}%");
            });
        }

        return response()->json($query->get(['id', 'first_name', 'last_name', 'phone', 'email']));
    }

    /** Search contacts (AJAX JSON) for the link picker. */
    public function search(Request $request, Property $property)
    {
        $q = trim($request->query('q', ''));

        $query = Contact::with('type')
            ->whereNotIn('id', $property->contacts()->pluck('contacts.id'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(10);

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('first_name', 'like', "%{$q}%")
                   ->orWhere('last_name',  'like', "%{$q}%")
                   ->orWhere('phone',      'like', "%{$q}%")
                   ->orWhere('email',      'like', "%{$q}%");
            });
        }

        return response()->json($query->get(['id', 'first_name', 'last_name', 'phone', 'email', 'contact_type_id']));
    }

    /** Link an existing contact to the property. */
    public function link(Request $request, Property $property)
    {
        $data = $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'role'       => 'nullable|string|max:50',
        ]);

        $role = $data['role'] ?? null;
        $property->contacts()->syncWithoutDetaching([
            $data['contact_id'] => ['role' => $role],
        ]);

        // Auto-create seller live link if seller role
        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
            \App\Models\PropertySellerLink::ensureExists($property->id, (int) $data['contact_id']);
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            $contact = Contact::with('type')->find($data['contact_id']);
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
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'role'            => 'nullable|string|max:50',
            'bypass_duplicate_check' => 'nullable|boolean',
        ]);

        $role = $data['role'] ?? null;
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
                    $property->contacts()->syncWithoutDetaching([$existing->id => ['role' => $role]]);
                    if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
                        \App\Models\PropertySellerLink::ensureExists($property->id, $existing->id);
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

        $contact = Contact::create($data);
        $property->contacts()->attach($contact->id, ['role' => $role]);
        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
            \App\Models\PropertySellerLink::ensureExists($property->id, $contact->id);
        }

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
