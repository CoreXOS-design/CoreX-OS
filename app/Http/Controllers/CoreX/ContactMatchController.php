<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use Illuminate\Http\Request;

class ContactMatchController extends Controller
{
    public function index()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Load all matches for contacts visible to this user, grouped by contact
        $matches = ContactMatch::with(['contact.type', 'createdBy'])
            ->whereHas('contact')
            ->where('created_by_user_id', $user->id)
            ->latest()
            ->get()
            ->groupBy('contact_id');

        // Build a flat list of contacts with their matches
        $contacts = Contact::whereIn('id', $matches->keys())
            ->with('type')
            ->orderBy('first_name')
            ->get()
            ->map(fn($c) => [
                'contact' => $c,
                'matches' => $matches->get($c->id, collect()),
            ]);

        return view('corex.core-matches.index', compact('contacts'));
    }

    public function store(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'listing_type'   => 'required|in:sale,rental',
            'category'       => 'nullable|string|max:100',
            'property_type'  => 'nullable|string|max:100',
            'price_min'      => 'nullable|integer|min:0',
            'price_max'      => 'nullable|integer|min:0',
            'beds_min'       => 'nullable|integer|min:0|max:20',
            'baths_min'      => 'nullable|integer|min:0|max:20',
            'garages_min'    => 'nullable|integer|min:0|max:20',
            'parking_min'    => 'nullable|integer|min:0|max:20',
            'floor_size_min' => 'nullable|integer|min:0',
            'floor_size_max' => 'nullable|integer|min:0',
            'erf_size_min'   => 'nullable|integer|min:0',
            'erf_size_max'   => 'nullable|integer|min:0',
            'suburb'         => 'nullable|string|max:150',
            'notes'          => 'nullable|string|max:500',
        ]);

        $data['contact_id']         = $contact->id;
        $data['created_by_user_id'] = auth()->id();

        $match = ContactMatch::create($data);

        return redirect()->route('corex.contacts.matches.results', [$contact, $match]);
    }

    public function results(Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        $query = Property::with(['agent', 'branch'])
            ->visibleTo($user)
            ->whereNotIn('status', ['sold', 'withdrawn']);

        if ($match->category) {
            $query->where('category', $match->category);
        }

        if ($match->property_type) {
            $query->where('property_type', $match->property_type);
        }

        if ($match->suburb) {
            $query->where('suburb', 'like', '%' . $match->suburb . '%');
        }

        if ($match->price_min) {
            $query->where('price', '>=', $match->price_min);
        }

        if ($match->price_max) {
            $query->where('price', '<=', $match->price_max);
        }

        if ($match->beds_min) {
            $query->where('beds', '>=', $match->beds_min);
        }

        if ($match->baths_min) {
            $query->where('baths', '>=', $match->baths_min);
        }

        if ($match->garages_min) {
            $query->where('garages', '>=', $match->garages_min);
        }

        if ($match->floor_size_min) {
            $query->where('size_m2', '>=', $match->floor_size_min);
        }

        if ($match->floor_size_max) {
            $query->where('size_m2', '<=', $match->floor_size_max);
        }

        if ($match->erf_size_min) {
            $query->where('erf_size_m2', '>=', $match->erf_size_min);
        }

        if ($match->erf_size_max) {
            $query->where('erf_size_m2', '<=', $match->erf_size_max);
        }

        $properties = $query->orderByDesc('created_at')->get();

        return view('corex.contacts.match-results', compact('contact', 'match', 'properties'));
    }

    public function toggleHide(Contact $contact, ContactMatch $match, int $property)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $match->toggleHiddenProperty($property);

        return back();
    }

    public function destroy(Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $match->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Match removed.')
            ->withFragment('tab-matches');
    }
}
