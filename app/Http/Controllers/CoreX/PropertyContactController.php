<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\Property;
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

        $property->contacts()->syncWithoutDetaching([
            $data['contact_id'] => ['role' => $data['role'] ?? null],
        ]);

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
        ]);

        $role = $data['role'] ?? null;
        unset($data['role']);
        $data['created_by_user_id'] = auth()->id();

        $contact = Contact::create($data);
        $property->contacts()->attach($contact->id, ['role' => $role]);

        return back()->with('success', 'Contact created and linked.')->with('tab', 'contacts');
    }

    /** Unlink a contact from the property. */
    public function unlink(Property $property, Contact $contact)
    {
        $property->contacts()->detach($contact->id);

        return back()->with('success', 'Contact unlinked.')->with('tab', 'contacts');
    }
}
