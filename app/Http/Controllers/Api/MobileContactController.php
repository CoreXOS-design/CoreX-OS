<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileContactController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\ResolvesMobileDataScope;

    // GET /api/mobile/contacts
    //
    // Visibility:
    //   The Contact model's global ContactScope already enforces the role
    //   scope (own/branch/all + agency Data-Isolation). On top of that we
    //   honour an optional `agent_id` filter so the app can show
    //   "Mine / All / specific agent" — but only within what the scope allows
    //   (resolveAgentFilter() 403s on an out-of-scope agent_id).
    //
    //   ?agent_id absent  → the user's own contacts (default, like the web)
    //   ?agent_id=        → everything the scope allows (branch or agency)
    //   ?agent_id=123     → that agent's contacts (if in scope)
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $agentFilter = $this->resolveAgentFilter(
            $user,
            'contacts',
            $request->has('agent_id') ? $request->query('agent_id', '') : null
        );

        $query = Contact::with(['type'])
            ->when($agentFilter !== null, fn ($q) => $q->where('created_by_user_id', $agentFilter))
            ->orderBy('last_name')->orderBy('first_name');

        if ($request->filled('search')) {
            foreach (array_filter(explode(' ', trim($request->search))) as $w) {
                $query->where(function ($q) use ($w) {
                    $q->where('first_name', 'like', "%{$w}%")
                      ->orWhere('last_name',  'like', "%{$w}%")
                      ->orWhere('phone',      'like', "%{$w}%")
                      ->orWhere('email',      'like', "%{$w}%")
                      ->orWhere('id_number',  'like', "%{$w}%");
                });
            }
        }

        $contacts = $query->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'contacts' => collect($contacts->items())->map(fn (Contact $c) => $this->shape($c)),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page'    => $contacts->lastPage(),
                'total'        => $contacts->total(),
            ],
        ]);
    }

    // GET /api/mobile/contacts/{contact}
    public function show(Request $request, Contact $contact): JsonResponse
    {
        // Read is scope-based: visible if the user's role scope (enforced by
        // the Contact global ContactScope) can see this record. Writes below
        // stay stricter (own-only) via authorize().
        abort_unless(
            Contact::whereKey($contact->getKey())->exists(),
            403,
            'That contact is outside your visibility scope.'
        );
        $contact->load(['type', 'matches', 'properties']);

        return response()->json([
            'contact' => $this->shape($contact, full: true),
        ]);
    }

    // POST /api/mobile/contacts
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'id_number'       => 'nullable|string|max:20',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'notes'           => 'nullable|string|max:1000',
        ]);

        // Duplicate check (mirrors web ContactController)
        $duplicate = Contact::where('phone', $data['phone'])
            ->when(!empty($data['email']), fn ($q) => $q->orWhere('email', $data['email']))
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate contact (phone or email already exists).',
                'duplicate_id' => $duplicate->id,
            ], 422);
        }

        $data['created_by_user_id'] = $user->id;
        $data['agency_id']          = $user->agency_id;
        $data['branch_id']          = $user->effectiveBranchId();

        $contact = Contact::create($data);

        return response()->json(['contact' => $this->shape($contact->fresh(['type']), full: true)], 201);
    }

    // PUT /api/mobile/contacts/{contact}  — limited fields only
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize($request->user(), $contact);

        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:100',
            'last_name'  => 'sometimes|required|string|max:100',
            'phone'      => 'sometimes|required|string|max:30',
            'email'      => 'sometimes|nullable|email|max:150',
            'id_number'  => 'sometimes|nullable|string|max:20',
        ]);

        $contact->update($data);

        return response()->json(['contact' => $this->shape($contact->fresh(['type']), full: true)]);
    }

    // POST /api/mobile/contacts/{contact}/whatsapp
    // Records the touch and returns a wa.me link the app can launch.
    public function whatsapp(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize($request->user(), $contact);

        $contact->increment('whatsapp_count');
        $contact->update(['last_contacted_at' => now()]);

        $digits = preg_replace('/\D+/', '', (string) $contact->phone);
        // SA local 0xx -> 27xx
        if (str_starts_with($digits, '0')) {
            $digits = '27' . substr($digits, 1);
        }

        return response()->json([
            'wa_link'        => $digits ? "https://wa.me/{$digits}" : null,
            'whatsapp_count' => $contact->whatsapp_count,
            'last_contacted_at' => $contact->last_contacted_at?->toIso8601String(),
        ]);
    }

    // POST /api/mobile/contacts/{contact}/matches  — create CoreMatch
    public function storeMatch(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize($request->user(), $contact);

        $data = $request->validate([
            'name'          => 'nullable|string|max:120',
            'listing_type'  => 'required|in:sale,rental',
            'category'      => 'nullable|string|max:100',
            'property_type' => 'nullable|string|max:100',
            'price_min'     => 'nullable|integer|min:0',
            'price_max'     => 'nullable|integer|min:0',
            'beds_min'      => 'nullable|integer|min:0|max:20',
            'baths_min'     => 'nullable|integer|min:0|max:20',
            'garages_min'   => 'nullable|integer|min:0|max:20',
            'suburb'        => 'nullable|string|max:150',
            'suburbs'       => 'nullable|array',
            'suburbs.*'     => 'string|max:150',
            'must_have_features'   => 'nullable|array',
            'must_have_features.*' => 'string|max:60',
            'notes'         => 'nullable|string|max:500',
        ]);

        $data['contact_id']         = $contact->id;
        $data['agency_id']          = $contact->agency_id;
        $data['created_by_user_id'] = $request->user()->id;
        $data['status']             = ContactMatch::STATUS_ACTIVE;

        $match = ContactMatch::create($data);

        return response()->json(['match' => $match], 201);
    }

    // GET /api/mobile/contacts/options — dropdown values (types)
    public function options(): JsonResponse
    {
        return response()->json([
            'contact_types' => ContactType::where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────
    private function authorize(User $user, Contact $contact): void
    {
        abort_unless($contact->created_by_user_id === $user->id, 403, 'Not your contact.');
    }

    private function shape(Contact $c, bool $full = false): array
    {
        $base = [
            'id'         => $c->id,
            'first_name' => $c->first_name,
            'last_name'  => $c->last_name,
            'full_name'  => trim($c->first_name . ' ' . $c->last_name),
            'phone'      => $c->phone,
            'email'      => $c->email,
            'id_number'  => $c->id_number,
            'type'       => $c->type?->name,
            'whatsapp_count' => (int) ($c->whatsapp_count ?? 0),
            'last_contacted_at' => $c->last_contacted_at?->toIso8601String(),
        ];

        if (!$full) return $base;

        return $base + [
            'notes'      => $c->notes,
            'address'    => $c->address,
            'birthday'   => $c->birthday?->toDateString(),
            'matches'    => $c->matches->map(fn ($m) => [
                'id'           => $m->id,
                'name'         => $m->name,
                'status'       => $m->status,
                'listing_type' => $m->listing_type,
                'price_min'    => $m->price_min,
                'price_max'    => $m->price_max,
                'suburb'       => $m->suburb,
            ])->values(),
            'properties' => $c->properties->map(fn ($p) => [
                'id'      => $p->id,
                'address' => $p->buildDisplayAddress(),
                'role'    => $p->pivot->role ?? null,
            ])->values(),
        ];
    }
}
