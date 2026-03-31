<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use Illuminate\Http\Request;

class ContactPropertyController extends Controller
{
    /** Search properties (AJAX JSON) for the link picker — by address/title/suburb. */
    public function search(Request $request, Contact $contact)
    {
        $q = trim($request->query('q', ''));

        $query = Property::whereNotIn('id', $contact->properties()->pluck('properties.id'))
            ->orderBy('title')
            ->limit(10);

        if ($q !== '') {
            $query->searchAddress($q);
        }

        return response()->json(
            $query->get()->map(fn ($p) => [
                'id'      => $p->id,
                'title'   => $p->title,
                'address' => $p->buildDisplayAddress(),
                'price'   => $p->formattedPrice(),
                'status'  => $p->status,
            ])
        );
    }

    /** Link a property to the contact. */
    public function link(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'property_id' => 'required|exists:properties,id',
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

        $contact->properties()->syncWithoutDetaching([
            $data['property_id'] => ['role' => $role],
        ]);

        return back()->with('success', 'Property linked to contact.')->with('tab', 'properties');
    }

    /** Unlink a property from the contact. */
    public function unlink(Contact $contact, Property $property)
    {
        $contact->properties()->detach($property->id);

        return back()->with('success', 'Property unlinked.')->with('tab', 'properties');
    }
}
