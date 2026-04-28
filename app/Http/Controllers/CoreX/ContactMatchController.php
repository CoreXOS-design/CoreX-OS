<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Deal;
use App\Services\Matching\MatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactMatchController extends Controller
{
    public function __construct(protected MatchingService $matching) {}

    public function index()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $matches = ContactMatch::with(['contact.type', 'createdBy', 'feedback'])
            ->whereHas('contact')
            ->where('created_by_user_id', $user->id)
            ->orderByRaw("FIELD(status,'active','paused','fulfilled','expired')")
            ->latest()
            ->get()
            ->groupBy('contact_id');

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
        $data = $this->validatePayload($request);
        $data['contact_id']         = $contact->id;
        $data['created_by_user_id'] = auth()->id();
        $data['agency_id']          = $contact->agency_id;

        $match = ContactMatch::create($data);

        return redirect()->route('corex.contacts.matches.results', [$contact, $match]);
    }

    public function update(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $match->update($this->validatePayload($request));

        return redirect()->route('corex.contacts.matches.results', [$contact, $match])
            ->with('success', 'Match updated.');
    }

    public function setStatus(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $status = $request->validate([
            'status' => 'required|in:active,paused,fulfilled,expired',
        ])['status'];

        $match->update(['status' => $status]);
        return back()->with('success', "Match marked {$status}.");
    }

    public function results(Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $properties = $this->matching->propertiesForMatch($match, ['include_hidden' => true]);

        $feedback = $match->feedback()->get()->keyBy('property_id');

        return view('corex.contacts.match-results', compact('contact', 'match', 'properties', 'feedback'));
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

    /**
     * Deal bridge — turn a (match, property) pair into a draft Deal.
     */
    public function convertToDeal(Request $request, Contact $contact, ContactMatch $match, int $property)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $deal = DB::transaction(function () use ($contact, $match, $property) {
            $deal = new Deal();
            $deal->property_id = $property;
            $deal->agency_id   = $match->agency_id;
            $deal->branch_id   = $contact->branch_id ?? null;

            // Best-effort fill of the buyer/tenant side from the contact
            $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            if (\Schema::hasColumn('deals', 'buyer_name'))   $deal->buyer_name   = $name;
            if (\Schema::hasColumn('deals', 'buyer_email'))  $deal->buyer_email  = $contact->email;
            if (\Schema::hasColumn('deals', 'buyer_phone'))  $deal->buyer_phone  = $contact->phone;
            if (\Schema::hasColumn('deals', 'deal_type'))    $deal->deal_type    = $match->listing_type === 'rental' ? 'rental' : 'sale';
            if (\Schema::hasColumn('deals', 'accepted_status')) $deal->accepted_status = 'P';
            if (\Schema::hasColumn('deals', 'agent_id'))     $deal->agent_id     = $match->created_by_user_id;
            if (\Schema::hasColumn('deals', 'created_by_user_id')) $deal->created_by_user_id = auth()->id();

            $deal->save();

            if ($request->boolean('mark_fulfilled')) {
                $match->update(['status' => ContactMatch::STATUS_FULFILLED]);
            }

            return $deal;
        });

        return redirect()->route('admin.deals.edit', $deal->id)
            ->with('success', 'Deal created from match. Complete the missing details.');
    }

    protected function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'name'                  => 'nullable|string|max:120',
            'listing_type'          => 'required|in:sale,rental',
            'category'              => 'nullable|string|max:100',
            'property_type'         => 'nullable|string|max:100',
            'price_min'             => 'nullable|integer|min:0',
            'price_max'             => 'nullable|integer|min:0',
            'beds_min'              => 'nullable|integer|min:0|max:20',
            'baths_min'             => 'nullable|integer|min:0|max:20',
            'garages_min'           => 'nullable|integer|min:0|max:20',
            'parking_min'           => 'nullable|integer|min:0|max:20',
            'floor_size_min'        => 'nullable|integer|min:0',
            'floor_size_max'        => 'nullable|integer|min:0',
            'erf_size_min'          => 'nullable|integer|min:0',
            'erf_size_max'          => 'nullable|integer|min:0',
            'suburb'                => 'nullable|string|max:150',
            'suburbs'               => 'nullable|array',
            'suburbs.*'             => 'string|max:150',
            'must_have_features'    => 'nullable|array',
            'must_have_features.*'  => 'string|max:60',
            'nice_to_have_features' => 'nullable|array',
            'nice_to_have_features.*' => 'string|max:60',
            'feat_pool'             => 'nullable|in:yes,no',
            'feat_furnished'        => 'nullable|in:yes,no',
            'feat_pets'             => 'nullable|in:yes,no',
            'notes'                 => 'nullable|string|max:500',
        ]);

        // Normalise multi-suburb input — accept comma-separated string too
        if (isset($data['suburbs']) && is_array($data['suburbs'])) {
            $data['suburbs'] = array_values(array_filter(array_map('trim', $data['suburbs'])));
        }

        // Merge structured Yes/No feature filters into must_have_features.
        $featureMap = [
            'feat_pool'      => ['yes' => 'pool',         'no' => 'no_pool'],
            'feat_furnished' => ['yes' => 'furnished',    'no' => 'unfurnished'],
            'feat_pets'      => ['yes' => 'pet_friendly', 'no' => 'no_pets'],
        ];
        $existing = $data['must_have_features'] ?? [];
        // Drop any prior feature tokens we manage so toggling Any clears them.
        $managed = ['pool', 'no_pool', 'furnished', 'unfurnished', 'pet_friendly', 'no_pets'];
        $existing = array_values(array_filter($existing, fn ($v) => !in_array(strtolower((string) $v), $managed, true)));
        foreach ($featureMap as $field => $tokens) {
            if (!empty($data[$field]) && isset($tokens[$data[$field]])) {
                $existing[] = $tokens[$data[$field]];
            }
            unset($data[$field]);
        }
        $data['must_have_features'] = array_values(array_unique($existing));

        return $data;
    }
}
