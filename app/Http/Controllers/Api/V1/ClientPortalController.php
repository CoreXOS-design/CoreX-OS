<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Events\Contact\ContactTestimonialSubmitted;
use App\Models\ContactMatch;
use App\Models\ContactMatchFeedback;
use App\Models\ContactTestimonial;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use App\Services\ClientAuthService;
use App\Services\Matching\ClientMatchResolver;
use App\Services\Matching\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authenticated client portal endpoints (Sanctum ability `client`).
 *
 * Spec: .ai/specs/client-auth.md
 */
class ClientPortalController extends Controller
{
    public function __construct(
        private readonly ClientAuthService $service,
        private readonly MatchingService $matching,
        private readonly ClientMatchResolver $resolver,
    ) {}

    /**
     * GET /api/v1/client/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var ClientUser $client */
        $client = $request->user();

        $agencies = $this->service->agenciesFor($client);

        $contact = $client->current_agency_id
            ? $this->service->contactForAgency($client, $client->current_agency_id)
            : null;

        $agent = $contact ? $this->resolveAssignedAgent($contact) : null;

        $payload = [
            'client' => [
                'id'                    => $client->id,
                'email'                 => $client->email,
                'has_password'          => $client->hasPassword(),
                'password_must_change'  => (bool) $client->password_must_change,
                'preferred_agency_id'   => $client->preferred_agency_id,
                'locked_to_agency_id'   => $client->locked_to_agency_id,
                'current_agency_id'     => $client->current_agency_id,
                'last_login_at'         => $client->last_login_at,
            ],
            'agencies' => $agencies,
            'contact'  => $contact ? $this->shapeContact($contact) : null,
        ];

        // Sibling of `contact`: the client's assigned agent in the current
        // agency (the agent who captured them — e.g. at QR signup). Omitted
        // entirely when there is no assigned agent; the app handles absence.
        if ($agent) {
            $payload['agent'] = $this->shapeAgent($agent);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v1/client/matches
     * List of the signed-in client's matches in the currently-selected agency.
     */
    public function matches(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact; // JsonResponse error
        }

        $matches = ContactMatch::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)
            ->where('agency_id', $contact->agency_id)
            ->with('feedback')
            ->latest()
            ->get();

        return response()->json([
            'agency_id' => $contact->agency_id,
            'matches'   => $matches->map(fn (ContactMatch $m) => $this->shapeMatch($m, full: false))->values(),
        ]);
    }

    /**
     * GET /api/v1/client/matches/{match}
     * Match detail including filters + result properties + per-property reactions.
     */
    public function matchShow(Request $request, ContactMatch $match): JsonResponse
    {
        $auth = $this->authorizeMatch($request, $match);
        if ($auth) return $auth;

        $match->load('feedback');

        $properties = collect();
        try {
            // Strict client-only filter. Mirrors mobile filters exactly — no
            // agency-wide scope override, no NULL leniency on filtered cols.
            $properties = $this->resolver->resolve($match);
        } catch (\Throwable $e) {
            report($e);
        }

        $feedback = $match->feedback->keyBy('property_id');

        $results = $properties->map(function (Property $p) use ($match, $feedback) {
            $fb = $feedback->get($p->id);
            return [
                'id'            => $p->id,
                'address'       => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->address ?? null),
                'suburb'        => $p->suburb,
                'beds'          => $p->beds,
                'baths'         => $p->baths,
                'garages'       => $p->garages,
                'price'         => $p->price,
                'price_display' => method_exists($p, 'formattedPrice') ? $p->formattedPrice() : null,
                'thumbnail'     => ($p->gallery_images_json ?? [])[0] ?? null,
                'match_score'   => $p->match_score ?? null,
                'match_tier'    => $p->match_tier ?? null,   // 'strong' | 'good' | 'fair'
                'listing_type'  => $p->listing_type ?? null,
                'status'        => $p->status ?? null,
                'hidden'        => $match->isPropertyHidden($p->id),
                'reaction'      => $fb?->reaction,
                'reaction_note' => $fb?->note,
            ];
        })->values();

        return response()->json([
            'match'   => $this->shapeMatch($match, full: true),
            'results' => $results,
        ]);
    }

    /**
     * POST /api/v1/client/matches
     * Create a new core match for the signed-in client (in current agency).
     */
    public function matchCreate(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $data = $this->validateMatchPayload($request, true);

        $match = new ContactMatch($data);
        $match->contact_id          = $contact->id;
        $match->agency_id           = $contact->agency_id;
        $match->created_by_user_id  = $contact->created_by_user_id;
        $match->status              = 'active';
        $match->save();

        $this->service->log($request->user(), $contact->agency_id, $contact->id, 'match_created', $request, [
            'match_id' => $match->id,
        ]);

        return response()->json(['match' => $this->shapeMatch($match->fresh('feedback'), full: true)], 201);
    }

    /**
     * PUT /api/v1/client/matches/{match}
     * Edit filters on the client's own match.
     */
    public function matchUpdate(Request $request, ContactMatch $match): JsonResponse
    {
        $auth = $this->authorizeMatch($request, $match);
        if ($auth) return $auth;

        $data = $this->validateMatchPayload($request, false);
        $match->update($data);

        $this->service->log($request->user(), $match->agency_id, $match->contact_id, 'match_updated', $request, [
            'match_id' => $match->id,
        ]);

        return response()->json(['match' => $this->shapeMatch($match->fresh('feedback'), full: true)]);
    }

    /**
     * POST /api/v1/client/matches/{match}/feedback/{property}
     * Body: { reaction: interested|not_interested|saved, note?: string }
     */
    public function matchFeedback(Request $request, ContactMatch $match, int $property): JsonResponse
    {
        $auth = $this->authorizeMatch($request, $match);
        if ($auth) return $auth;

        $data = $request->validate([
            'reaction' => 'required|in:interested,not_interested,saved',
            'note'     => 'nullable|string|max:500',
        ]);

        $fb = ContactMatchFeedback::updateOrCreate(
            ['contact_match_id' => $match->id, 'property_id' => $property],
            ['reaction' => $data['reaction'], 'note' => $data['note'] ?? null]
        );

        $match->forceFill(['last_engaged_at' => now()])->save();

        $this->service->log($request->user(), $match->agency_id, $match->contact_id, 'match_feedback', $request, [
            'match_id'    => $match->id,
            'property_id' => $property,
            'reaction'    => $data['reaction'],
            'has_note'    => !empty($data['note']),
        ]);

        return response()->json([
            'feedback' => [
                'property_id' => $property,
                'reaction'    => $fb->reaction,
                'note'        => $fb->note,
            ],
        ]);
    }

    /**
     * POST /api/v1/client/matches/{match}/view/{property}
     * Increments per-property view counter on the match.
     */
    public function matchView(Request $request, ContactMatch $match, int $property): JsonResponse
    {
        $auth = $this->authorizeMatch($request, $match);
        if ($auth) return $auth;

        $match->incrementPropertyView($property);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v1/client/properties/{property}
     * Full property detail. Authorized only if the property appears in one of
     * the client's own matches in the current agency.
     */
    public function propertyShow(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        // Authorization: this property must be a result on at least one of the
        // client's own matches OR belong to the client's agency.
        $matches = ContactMatch::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)
            ->where('agency_id', $contact->agency_id)
            ->get();

        $authorized = false;
        foreach ($matches as $m) {
            try {
                $ids = $this->resolver->resolve($m)->pluck('id')->all();
                if (in_array($property, $ids, true)) {
                    $authorized = true;
                    break;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (!$authorized) {
            // Fallback: allow if property is in same agency (so client can browse links from agent)
            $exists = Property::query()
                ->withoutGlobalScope(AgencyScope::class)
                ->where('id', $property)
                ->where('agency_id', $contact->agency_id)
                ->exists();
            if (!$exists) {
                return response()->json(['message' => 'Property not available.'], 404);
            }
        }

        /** @var Property|null $p */
        $p = Property::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->with(['agent:id,name,phone,email', 'branch:id,name'])
            ->find($property);

        if (!$p) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json([
            'property' => [
                'id'              => $p->id,
                'title'           => $p->title ?? null,
                'address'         => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->address ?? null),
                'suburb'          => $p->suburb,
                'beds'            => $p->beds,
                'baths'           => $p->baths,
                'garages'         => $p->garages,
                'parking'         => $p->parking ?? null,
                'floor_size'      => $p->floor_size ?? null,
                'erf_size'        => $p->erf_size ?? null,
                'property_type'   => $p->property_type ?? null,
                'category'        => $p->category ?? null,
                'listing_type'    => $p->listing_type ?? null,
                'status'          => $p->status ?? null,
                'price'           => $p->price,
                'price_display'   => method_exists($p, 'formattedPrice') ? $p->formattedPrice() : null,
                'description'     => $p->description ?? null,
                'features'        => $p->features ?? null,
                'images'          => $p->gallery_images_json ?? [],
                'thumbnail'       => ($p->gallery_images_json ?? [])[0] ?? null,
                'agent'           => $p->agent ? [
                    'name'  => $p->agent->name,
                    'phone' => $p->agent->phone,
                    'email' => $p->agent->email,
                ] : null,
                'branch'          => $p->branch?->name,
                'web_preview_url' => route('corex.properties.preview', $p->id),
            ],
        ]);
    }

    /**
     * GET /api/v1/client/match-options
     * Returns enums + suburb suggestions used by the create/edit-filters form.
     */
    public function matchOptions(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $suburbs = Property::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $contact->agency_id)
            ->whereNotNull('suburb')
            ->select('suburb')
            ->distinct()
            ->orderBy('suburb')
            ->limit(500)
            ->pluck('suburb')
            ->values();

        return response()->json([
            'listing_types'   => ['sale', 'rental'],
            'property_types'  => ['House', 'Townhouse', 'Apartment', 'Vacant Land', 'Farm', 'Commercial'],
            'categories'      => ['Residential', 'Commercial', 'Agricultural'],
            'suburbs'         => $suburbs,
        ]);
    }

    /**
     * GET /api/v1/client/testimonials
     * The signed-in client's own testimonials in the current agency, newest
     * first, each carrying its current website-publish status so the app can
     * show "Submitted" vs "Live on website".
     */
    public function testimonials(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $testimonials = ContactTestimonial::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)
            ->where('agency_id', $contact->agency_id)
            ->latest()
            ->get();

        return response()->json([
            'agency_id'    => $contact->agency_id,
            'testimonials' => $testimonials->map(fn (ContactTestimonial $t) => $this->shapeTestimonial($t))->values(),
        ]);
    }

    /**
     * POST /api/v1/client/testimonials
     * The signed-in client leaves a testimonial about their agent.
     *
     * Captured unpublished (published = false) — identical to agent-side
     * capture (.ai/specs/testimonials.md §1): the agency curates what reaches
     * the public website via Company Settings. It is immediately visible to the
     * agent on the Contact's "Notes & Testimonials" tab (the web sync), and the
     * connected agent is notified (in-app + email) via the domain event.
     *
     * Body: { body: string (required), rating?: 1..5, display_name?: string }
     */
    public function testimonialCreate(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $data = $request->validate([
            'body'         => ['required', 'string', 'max:5000'],
            'rating'       => ['nullable', 'integer', 'min:1', 'max:5'],
            'display_name' => ['nullable', 'string', 'max:150'],
        ]);

        // The testimonial is about the agent connected to this contact (the
        // agent who captured them). May be null for an unassigned contact.
        $agent = $this->resolveAssignedAgent($contact);

        $testimonial = new ContactTestimonial();
        $testimonial->agency_id    = $contact->agency_id;
        $testimonial->contact_id   = $contact->id;
        $testimonial->user_id      = null; // submitted by the client, not a User
        $testimonial->agent_id     = $agent?->id;
        $testimonial->body         = trim($data['body']);
        $testimonial->display_name = $this->resolveTestimonialDisplayName($data['display_name'] ?? null, $contact);
        $testimonial->rating       = $data['rating'] ?? null;
        $testimonial->published    = false; // capture never publishes
        $testimonial->save();

        // Cross-pillar Contact → Agent: notify the connected agent (in-app +
        // email). Failure-isolated in the listener; never breaks this response.
        ContactTestimonialSubmitted::dispatch($testimonial, $contact, $agent?->id);

        $this->service->log($request->user(), $contact->agency_id, $contact->id, 'testimonial_created', $request, [
            'testimonial_id' => $testimonial->id,
            'rating'         => $testimonial->rating,
        ]);

        return response()->json(['testimonial' => $this->shapeTestimonial($testimonial)], 201);
    }

    /**
     * GET /api/v1/client/consent
     * The signed-in client's own consent decisions for every type, so the app
     * can show what they've agreed to and what they've refused.
     * Spec: .ai/specs/contact-consent.md §6.
     */
    public function consentIndex(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        return response()->json([
            'agency_id' => $contact->agency_id,
            'consents'  => $contact->consentStates(),
        ]);
    }

    /**
     * POST /api/v1/client/consent
     * The client sets their own decision for one consent type.
     * Body: { type: <one of the 7>, decision: given|declined|clear }
     *
     * A channel decline immediately flips the matching opt_out_* flag (via the
     * ContactConsentRecord observer), so the agency stops contacting them that
     * way the moment they tap "No". Written with source=client_app and no User
     * actor — the honest record that the client set it themselves.
     */
    public function consentUpdate(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $data = $request->validate([
            'type'     => ['required', 'in:' . implode(',', array_keys(Contact::CONSENT_TYPES))],
            'decision' => ['required', 'in:given,declined,clear'],
        ]);

        if ($data['decision'] === 'clear') {
            $contact->clearConsent($data['type'], null, 'Cleared by client');
        } else {
            $contact->setConsent($data['type'], $data['decision'], 'electronic', null, 'client_app');
        }

        $this->service->log($request->user(), $contact->agency_id, $contact->id, 'consent_updated', $request, [
            'consent_type' => $data['type'],
            'decision'     => $data['decision'],
        ]);

        return response()->json([
            'agency_id' => $contact->agency_id,
            'consents'  => $contact->consentStates(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Returns the Contact for the signed-in client in their current agency,
     * or a 409/401-style JsonResponse if not resolvable.
     */
    private function resolveContact(Request $request): Contact|JsonResponse
    {
        /** @var ClientUser $client */
        $client = $request->user();

        $agencyId = $client->current_agency_id;
        if (!$agencyId) {
            return response()->json([
                'message' => 'Select an agency first.',
            ], 409);
        }

        $contact = $this->service->contactForAgency($client, $agencyId);
        if (!$contact) {
            return response()->json(['message' => 'No contact record in this agency.'], 404);
        }

        return $contact;
    }

    /**
     * Returns null if authorized, or a 403 JsonResponse otherwise.
     */
    private function authorizeMatch(Request $request, ContactMatch $match): ?JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        if ($match->contact_id !== $contact->id || $match->agency_id !== $contact->agency_id) {
            return response()->json(['message' => 'Match not available.'], 403);
        }

        return null;
    }

    private function validateMatchPayload(Request $request, bool $isCreate): array
    {
        $rule = $isCreate ? 'required' : 'sometimes|required';

        return $request->validate([
            'name'          => 'sometimes|nullable|string|max:120',
            'listing_type'  => $rule . '|in:sale,rental',
            'category'      => 'sometimes|nullable|string|max:100',
            'property_type' => 'sometimes|nullable|string|max:100',
            'price_min'     => 'sometimes|nullable|integer|min:0',
            'price_max'     => 'sometimes|nullable|integer|min:0',
            'beds_min'      => 'sometimes|nullable|integer|min:0|max:20',
            'baths_min'     => 'sometimes|nullable|integer|min:0|max:20',
            'garages_min'   => 'sometimes|nullable|integer|min:0|max:20',
            'suburb'        => 'sometimes|nullable|string|max:150',
            'suburbs'       => 'sometimes|nullable|array',
            'suburbs.*'     => 'string|max:150',
            'must_have_features'   => 'sometimes|nullable|array',
            'must_have_features.*' => 'string|max:60',
            'notes'         => 'sometimes|nullable|string|max:500',
        ]);
    }

    private function shapeMatch(ContactMatch $m, bool $full = false): array
    {
        $base = [
            'id'           => $m->id,
            'name'         => $m->name,
            'status'       => $m->status,
            'listing_type' => $m->listing_type,
            'created_at'   => $m->created_at,
            'updated_at'   => $m->updated_at,
            'last_engaged_at' => $m->last_engaged_at,
            'feedback_summary' => $this->feedbackSummary($m),
        ];

        if (!$full) {
            return $base;
        }

        return $base + [
            'category'           => $m->category,
            'property_type'      => $m->property_type,
            'price_min'          => $m->price_min,
            'price_max'          => $m->price_max,
            'beds_min'           => $m->beds_min,
            'baths_min'          => $m->baths_min,
            'garages_min'        => $m->garages_min,
            'suburb'             => $m->suburb,
            'suburbs'            => $m->suburbs,
            'must_have_features' => $m->must_have_features,
            'notes'              => $m->notes,
        ];
    }

    private function feedbackSummary(ContactMatch $m): array
    {
        $loaded = $m->relationLoaded('feedback')
            ? $m->feedback
            : $m->feedback()->get();

        return [
            'interested'     => $loaded->where('reaction', 'interested')->count(),
            'not_interested' => $loaded->where('reaction', 'not_interested')->count(),
            'saved'          => $loaded->where('reaction', 'saved')->count(),
        ];
    }

    private function shapeContact(Contact $contact): array
    {
        return [
            'id'         => $contact->id,
            'first_name' => $contact->first_name,
            'last_name'  => $contact->last_name,
            'full_name'  => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'email'      => $contact->email,
            'phone'      => $contact->phone,
            'agency_id'  => $contact->agency_id,
        ];
    }

    /**
     * Public shape of a client's own testimonial. `published` reflects the
     * agency's website-publish tick so the app can show "Live on website".
     */
    private function shapeTestimonial(ContactTestimonial $t): array
    {
        return [
            'id'           => $t->id,
            'body'         => $t->body,
            'rating'       => $t->rating !== null ? (int) $t->rating : null,
            'display_name' => $t->display_name,
            'published'    => (bool) $t->published,
            'created_at'   => $t->created_at,
        ];
    }

    /**
     * Resolve the public author name for a client-submitted testimonial. Trims
     * a supplied name; falls back to the contact's full name, then "Client".
     * The column is NOT NULL, so a value is always produced. Mirrors the
     * agent-side ContactTestimonialController::resolveDisplayName.
     */
    private function resolveTestimonialDisplayName(?string $supplied, Contact $contact): string
    {
        $name = trim((string) $supplied);
        if ($name !== '') {
            return $name;
        }

        $full = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));

        return $full !== '' ? $full : 'Client';
    }

    /**
     * Resolve the agent assigned to this contact in its (current) agency.
     *
     * The link is `created_by_user_id` — the agent who captured the contact,
     * e.g. the agent whose QR code the client scanned at signup (see
     * AgentQrController::upsertAgencyContact). Scope is bypassed and the query
     * is pinned to the contact's own agency so we never leak an agent from
     * another tenant, and so it resolves while authed as a ClientUser (which
     * carries no User-agency context). SoftDeletes excludes departed agents.
     */
    private function resolveAssignedAgent(Contact $contact): ?User
    {
        if (!$contact->created_by_user_id) {
            return null;
        }

        return User::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('id', $contact->created_by_user_id)
            ->where('agency_id', $contact->agency_id)
            ->first();
    }

    /**
     * Shape an agent for the mobile "Your agent" contact card. Empty channels
     * are dropped so the app only renders a button per populated field. Phone
     * numbers are emitted in E.164 (+27…) so wa.me carries the country code.
     *
     * Number sourcing is mobile-first (`cell` then `phone`) to match the
     * app-wide convention (WebTemplateDataService, PresentationPdfService, the
     * P24 syndication mapping all use `cell ?? phone`): the agent's mobile is
     * the most reachable line and the only one that can receive WhatsApp. Both
     * `phone` (tap-to-call) and `whatsapp` resolve to it, so the app always has
     * a Call/WhatsApp number whenever the agent has one on file in either column.
     */
    private function shapeAgent(User $agent): array
    {
        $name  = trim((string) $agent->name);
        $first = trim(explode(' ', $name)[0] ?? '');
        $last  = trim((string) str_replace($first, '', $name));

        $mobile   = $this->toE164($agent->cell ?: $agent->phone);
        $whatsapp = $this->toE164($agent->cell ?: $agent->phone);

        return array_filter([
            'id'         => $agent->id,
            'first_name' => $first ?: null,
            'last_name'  => $last ?: null,
            'full_name'  => $name ?: null,
            'title'      => $agent->designation ?: null,
            'phone'      => $mobile,
            'whatsapp'   => $whatsapp,
            'email'      => $agent->email ?: null,
            'photo_url'  => method_exists($agent, 'profilePhotoUrl') ? $agent->profilePhotoUrl() : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Convert a stored SA number (canonicalised local form, e.g. 0821234567)
     * to E.164 (+27821234567). Returns null for blanks. Best-effort: anything
     * that isn't a recognisable SA shape is returned as a `+`-prefixed digit
     * string rather than discarded.
     */
    private function toE164(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '27')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+27' . substr($digits, 1);
        }
        return '+' . $digits;
    }
}
