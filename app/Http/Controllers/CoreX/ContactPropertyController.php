<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Rules\ExistsInScope;
use Illuminate\Http\Request;

class ContactPropertyController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesContactAccess;

    /** Search properties (AJAX JSON) for the link picker — by address/title/suburb. */
    public function search(Request $request, Contact $contact)
    {
        $q = trim($request->query('q', ''));

        $query = Property::whereNotIn('id', $contact->properties()->pluck('properties.id'))
            ->with('agent')
            ->latest() // newest-first (CoreX never deletes — current listings on top)
            ->limit(10);

        if ($q !== '') {
            $query->searchAddress($q);
        }

        return response()->json(
            $query->get()->map(fn ($p) => $p->toSearchResult([
                'title'   => $p->title,
                'address' => $p->buildDisplayAddress(),
                'price'   => $p->formattedPrice(),
            ]))
        );
    }

    /** Link a property to the contact. */
    public function link(Request $request, Contact $contact)
    {
        $this->authorizeContact($contact);
        $data = $request->validate([
            // ExistsInScope (not `exists:`) so AgencyScope is enforced — a property
            // id from another agency must NOT be linkable to this contact.
            'property_id' => ['required', new ExistsInScope(Property::class)],
            'role'        => 'nullable|string|max:50',
        ]);

        // If no explicit role, derive from contact type's esign_role
        $role = $data['role'] ?? null;
        if (empty($role)) {
            $esignRole = $contact->type?->esign_role;
            $roleMap = [
                'seller' => 'owner',
                'lessor' => 'lessor',
                'buyer' => 'buyer',
                'lessee' => 'tenant',
            ];
            $role = $roleMap[$esignRole] ?? null;
        }

        $alreadyLinked = $contact->properties()->where('properties.id', (int) $data['property_id'])->exists();

        $contact->properties()->syncWithoutDetaching([
            $data['property_id'] => ['role' => $role],
        ]);

        // Auto-create seller live link if seller role
        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
            \App\Models\PropertySellerLink::ensureExists((int) $data['property_id'], $contact->id);
        }

        // Domain event — only on new link (not on no-op re-attach).
        // Spec: .ai/specs/corex-domain-events-spec.md
        if (!$alreadyLinked) {
            $property = Property::find((int) $data['property_id']);
            if ($property) {
                event(new \App\Events\Contact\ContactLinkedToProperty(
                    contact: $contact,
                    property: $property,
                    role: (string) ($role ?? 'unknown'),
                    actorUserId: auth()->id(),
                ));
            }
        }

        return back()->with('success', 'Property linked to contact.')->with('tab', 'properties');
    }

    /** Unlink a property from the contact. */
    public function unlink(Contact $contact, Property $property)
    {
        $this->authorizeContact($contact);
        $contact->properties()->detach($property->id);

        return back()->with('success', 'Property unlinked.')->with('tab', 'properties');
    }
}
