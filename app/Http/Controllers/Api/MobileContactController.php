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
            // AT-59: derive the WhatsApp send count from the archive (eager
            // count alias, no N+1) instead of the legacy scalar column.
            ->withCount(['communications as wa_outbound_count' => function ($q) {
                $q->where('channel', \App\Models\Communications\Communication::CHANNEL_WHATSAPP)
                  ->where('direction', \App\Models\Communications\Communication::DIRECTION_OUTBOUND)
                  ->whereNull('communications.purged_at');
            }])
            ->when($agentFilter !== null, fn ($q) => $q->where('created_by_user_id', $agentFilter))
            ->orderBy('last_name')->orderBy('first_name');

        if ($request->filled('search')) {
            // AT-131 canonical contact search — matches name + id_number + ALL
            // identifiers (child phones/emails), closing the AT-125 gap. (The list
            // keeps its alphabetical order above for the mobile UX.)
            $query->search($request->search);
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
            // AT-125 — single phone/email kept for back-compat; the app may post
            // the multi-identifier phones[]/emails[] arrays instead.
            'last_name'       => 'required|string|max:100',
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'phones'              => 'nullable|array',
            'phones.*.value'      => 'nullable|string|max:30',
            'phones.*.label'      => 'nullable|string|max:60',
            'phones.*.is_primary' => 'nullable|boolean',
            'emails'              => 'nullable|array',
            'emails.*.value'      => 'nullable|email|max:150',
            'emails.*.label'      => 'nullable|string|max:60',
            'emails.*.is_primary' => 'nullable|boolean',
            'id_number'       => 'nullable|string|max:20',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $phones = $this->identifierList($request->input('phones', []), $data['phone'] ?? null);
        $emails = $this->identifierList($request->input('emails', []), $data['email'] ?? null);
        if ($phones === [] && $emails === []) {
            return response()->json(['message' => 'A contact needs at least one phone number or email address.'], 422);
        }

        // Duplicate check across ALL identifiers (child tables + mirror), AT-125.
        $duplicate = app(\App\Services\ContactDuplicateService::class)
            ->findDuplicatesForIdentifiers(
                array_column($phones, 'value'),
                array_column($emails, 'value'),
                $data['id_number'] ?? null,
                (int) $user->agency_id
            )->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'Duplicate contact (an identifier already exists).',
                'duplicate_id' => $duplicate->id,
            ], 422);
        }

        unset($data['phone'], $data['email'], $data['phones'], $data['emails']);
        $data['created_by_user_id'] = $user->id;
        $data['agency_id']          = $user->agency_id;
        $data['branch_id']          = $user->effectiveBranchId();

        $contact = \DB::transaction(function () use ($data, $phones, $emails) {
            $contact = Contact::create($data);
            app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers($contact, $phones, $emails);
            return $contact;
        });

        return response()->json(['contact' => $this->shape($contact->fresh(['type']), full: true)], 201);
    }

    /**
     * AT-125 — normalise an incoming phones/emails payload (array of
     * {value,label,is_primary} or plain strings) into a clean list, falling back
     * to a single legacy value. Guarantees one primary when any rows exist.
     *
     * @return array<int,array{value:string,label:?string,is_primary:bool}>
     */
    private function identifierList($input, ?string $single): array
    {
        $out = [];
        if (is_array($input)) {
            foreach ($input as $row) {
                $value = is_array($row) ? trim((string) ($row['value'] ?? '')) : trim((string) $row);
                if ($value === '') {
                    continue;
                }
                $label = is_array($row) ? trim((string) ($row['label'] ?? '')) : '';
                $out[] = [
                    'value'      => $value,
                    'label'      => $label !== '' ? $label : null,
                    'is_primary' => is_array($row) && filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
        }
        if ($out === [] && !empty($single)) {
            $out[] = ['value' => trim((string) $single), 'label' => null, 'is_primary' => true];
        }
        if ($out !== [] && !collect($out)->contains(fn ($r) => $r['is_primary'])) {
            $out[0]['is_primary'] = true;
        }
        return $out;
    }

    // PUT /api/mobile/contacts/{contact}  — limited fields only
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize($request->user(), $contact);

        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:100',
            'last_name'  => 'sometimes|required|string|max:100',
            'phone'      => 'sometimes|nullable|string|max:30',
            'email'      => 'sometimes|nullable|email|max:150',
            'phones'              => 'sometimes|array',
            'phones.*.value'      => 'nullable|string|max:30',
            'phones.*.label'      => 'nullable|string|max:60',
            'phones.*.is_primary' => 'nullable|boolean',
            'emails'              => 'sometimes|array',
            'emails.*.value'      => 'nullable|email|max:150',
            'emails.*.label'      => 'nullable|string|max:60',
            'emails.*.is_primary' => 'nullable|boolean',
            'id_number'  => 'sometimes|nullable|string|max:20',
        ]);

        // AT-125 — sync identifiers only when the payload carries them.
        $hasIdentifierInput = $request->has('phones') || $request->has('emails')
            || $request->filled('phone') || $request->filled('email');
        $phones = $this->identifierList($request->input('phones', []), $data['phone'] ?? null);
        $emails = $this->identifierList($request->input('emails', []), $data['email'] ?? null);
        if ($hasIdentifierInput && $phones === [] && $emails === []) {
            return response()->json(['message' => 'A contact needs at least one phone number or email address.'], 422);
        }
        unset($data['phone'], $data['email'], $data['phones'], $data['emails']);

        \DB::transaction(function () use ($contact, $data, $hasIdentifierInput, $phones, $emails) {
            $contact->update($data);
            if ($hasIdentifierInput) {
                app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers($contact, $phones, $emails);
            }
        });

        return response()->json(['contact' => $this->shape($contact->fresh(['type']), full: true)]);
    }

    // POST /api/mobile/contacts/{contact}/whatsapp
    // Records the touch and returns a wa.me link the app can launch.
    public function whatsapp(Request $request, Contact $contact, \App\Services\Communications\OutboundProvisionalLogger $logger): JsonResponse
    {
        $this->authorize($request->user(), $contact);

        // AT-59: record a PROVISIONAL outbound WhatsApp communication in the
        // archive (reconciled by WA capture later), not a blind scalar bump.
        // The logger advances last_contacted_at on this same instance.
        $logger->log(
            $contact,
            \App\Models\Communications\Communication::CHANNEL_WHATSAPP,
            null,
            null,
            $request->user()->id
        );

        $digits = preg_replace('/\D+/', '', (string) $contact->phone);
        // SA local 0xx -> 27xx
        if (str_starts_with($digits, '0')) {
            $digits = '27' . substr($digits, 1);
        }

        return response()->json([
            'wa_link'        => $digits ? "https://wa.me/{$digits}" : null,
            'whatsapp_count' => $contact->outboundCommCount(\App\Models\Communications\Communication::CHANNEL_WHATSAPP),
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

        // Part 1.5 — manual (mobile) capture rides the SAME observer cascade; tag source.
        app(\App\Services\Buyers\BuyerLeadCascadeService::class)
            ->tagBuyerSource($contact, \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_MANUAL);

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
            // AT-59: archive-derived. Uses the eager count alias from index();
            // falls back to a single scoped count for single-contact responses.
            'whatsapp_count' => (int) ($c->wa_outbound_count
                ?? $c->outboundCommCount(\App\Models\Communications\Communication::CHANNEL_WHATSAPP)),
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
