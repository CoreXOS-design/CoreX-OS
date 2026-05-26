<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchFeedback;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileCoreMatchController extends Controller
{
    public function __construct(protected MatchingService $matching) {}

    // GET /api/mobile/core-matches
    // Grouped by contact, only matches the current user owns.
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $matches = ContactMatch::with(['contact.type', 'feedback'])
            ->whereHas('contact')
            ->where('created_by_user_id', $user->id)
            ->orderByRaw("FIELD(status,'active','paused','fulfilled','expired')")
            ->latest()
            ->get();

        $grouped = $matches->groupBy('contact_id')->map(function ($items) {
            $contact = $items->first()->contact;
            return [
                'contact' => [
                    'id'        => $contact->id,
                    'full_name' => trim($contact->first_name . ' ' . $contact->last_name),
                    'phone'     => $contact->phone,
                    'email'     => $contact->email,
                    'type'      => $contact->type?->name,
                ],
                'matches' => $items->map(fn (ContactMatch $m) => $this->shapeMatch($m))->values(),
            ];
        })->values();

        return response()->json(['groups' => $grouped]);
    }

    // GET /api/mobile/core-matches/{match}
    // Match details + result properties + per-property feedback + hidden flag.
    public function show(Request $request, ContactMatch $match): JsonResponse
    {
        $this->authorizeMatch($request->user(), $match);
        $match->load(['contact.type', 'feedback']);

        $scope = (string) \App\Models\PerformanceSetting::get('matches_visibility_scope', \App\Services\Matching\MatchingService::SCOPE_AGENCY);
        $overrides = ['include_hidden' => true] + \App\Services\Matching\MatchingService::scopeOverridesFor($match);

        $properties = $this->matching->propertiesForMatch($match, $overrides);
        $feedback   = $match->feedback->keyBy('property_id');

        $results = $properties->map(function (Property $p) use ($match, $feedback) {
            $fb = $feedback->get($p->id);
            return [
                'id'            => $p->id,
                'address'       => $p->buildDisplayAddress(),
                'suburb'        => $p->suburb,
                'beds'          => $p->beds,
                'baths'         => $p->baths,
                'garages'       => $p->garages,
                'price'         => $p->price,
                'price_display' => $p->formattedPrice(),
                'thumbnail'     => ($p->gallery_images_json ?? [])[0] ?? null,
                'match_score'   => (int) ($p->match_score ?? 0),
                'match_tier'    => $p->match_tier,        // 'strong' | 'good' | 'fair'
                'hidden'        => $match->isPropertyHidden($p->id),
                'hidden_reason' => $match->hiddenReasonFor($p->id),
                'reaction'      => $fb?->reaction,        // 'interested' | 'not_interested' | 'saved' | null
                'reaction_note' => $fb?->note,
            ];
        })->values();

        return response()->json([
            'match'   => $this->shapeMatch($match, full: true),
            'contact' => [
                'id'        => $match->contact->id,
                'full_name' => trim($match->contact->first_name . ' ' . $match->contact->last_name),
                'phone'     => $match->contact->phone,
                'email'     => $match->contact->email,
                'type'      => $match->contact->type?->name,
            ],
            'results' => $results,
            'scope'   => ['visibility' => $scope],
        ]);
    }

    // PUT /api/mobile/core-matches/{match}
    public function update(Request $request, ContactMatch $match): JsonResponse
    {
        $this->authorizeMatch($request->user(), $match);

        $data = $request->validate([
            'name'          => 'sometimes|nullable|string|max:120',
            'listing_type'  => 'sometimes|required|in:sale,rental',
            'category'      => 'sometimes|nullable|string|max:100',
            'property_type' => 'sometimes|nullable|string|max:100',
            'price_min'     => 'sometimes|nullable|integer|min:0',
            'price_max'     => 'sometimes|nullable|integer|min:0',
            'beds_min'      => 'sometimes|nullable|integer|min:0|max:20',
            'baths_min'     => 'sometimes|nullable|integer|min:0|max:20',
            'garages_min'   => 'sometimes|nullable|integer|min:0|max:20',
            'p24_suburb_ids'   => 'sometimes|nullable|array',
            'p24_suburb_ids.*' => 'integer|exists:p24_suburbs,id',
            'must_have_features'   => 'sometimes|nullable|array',
            'must_have_features.*' => 'string|max:60',
            'notes'         => 'sometimes|nullable|string|max:500',
        ]);

        $match->update($data);

        return response()->json(['match' => $this->shapeMatch($match->fresh(['contact.type', 'feedback']), full: true)]);
    }

    // PATCH /api/mobile/core-matches/{match}/status
    public function setStatus(Request $request, ContactMatch $match): JsonResponse
    {
        $this->authorizeMatch($request->user(), $match);
        $data = $request->validate([
            'status' => 'required|in:active,paused,fulfilled,expired',
        ]);
        $match->update($data);
        return response()->json(['status' => $match->status]);
    }

    // POST /api/mobile/core-matches/{match}/hide/{property}
    // Toggles hidden flag for that property within this match.
    // When hiding a property a `reason` is REQUIRED and stored against the
    // match; when un-hiding, the reason is cleared and no body is needed.
    public function toggleHide(Request $request, ContactMatch $match, int $property): JsonResponse
    {
        $this->authorizeMatch($request->user(), $match);

        $willHide = !$match->isPropertyHidden($property);

        if ($willHide) {
            $data = $request->validate([
                'reason' => 'required|string|min:3|max:500',
            ]);
            $match->hidePropertyWithReason($property, $data['reason']);
        } else {
            $match->unhideProperty($property);
        }

        return response()->json([
            'property_id'   => $property,
            'hidden'        => $match->isPropertyHidden($property),
            'hidden_reason' => $match->hiddenReasonFor($property),
        ]);
    }

    // DELETE /api/mobile/core-matches/{match}
    public function destroy(Request $request, ContactMatch $match): JsonResponse
    {
        $this->authorizeMatch($request->user(), $match);
        $match->delete();
        return response()->json(['deleted' => true]);
    }

    // GET  /api/mobile/core-matches/{match}/share-whatsapp
    // POST /api/mobile/core-matches/{match}/share-whatsapp
    //   GET  → returns the template (rendered with {name}/{link} replaced) so the app can preview/edit it
    //   POST → records the touch (whatsapp_count++, last_contacted_at, last_engaged_at) and returns the
    //          final wa.me URL the client should launch. Optional body: { message?: string } to override.
    public function shareWhatsApp(Request $request, ContactMatch $match): JsonResponse
    {
        $this->authorizeMatch($request->user(), $match);
        $match->loadMissing('contact');

        $contact = $match->contact;
        $template = \App\Models\PerformanceSetting::get(
            'matches_wa_message',
            "Hi {name}! \u{1F44B}\n\nI've put together a personalised selection of properties that match your search criteria.\n\nView your property matches here:\n{link}\n\nFeel free to reach out if you'd like to arrange viewings or have any questions!"
        );

        $shareUrl = $match->sharedUrl();
        $rendered = str_replace(
            ['{name}', '{link}'],
            [$contact->first_name ?? '', $shareUrl],
            $template
        );

        // Allow the mobile UI to override the body before sending
        $message = $request->input('message', $rendered);

        $digits = preg_replace('/\D+/', '', (string) $contact->phone);
        if ($digits && str_starts_with($digits, '0')) {
            $digits = '27' . substr($digits, 1);
        }

        $waLink = $digits
            ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($message)
            : null;

        if ($request->isMethod('post')) {
            $contact->increment('whatsapp_count');
            $contact->update(['last_contacted_at' => now()]);
            $match->update(['last_engaged_at' => now()]);
        }

        return response()->json([
            'wa_link'         => $waLink,
            'phone'           => $digits,
            'message'         => $message,
            'template'        => $template,
            'rendered'        => $rendered,
            'share_url'       => $shareUrl,
            'contact_name'    => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            'first_name'      => $contact->first_name,
            'whatsapp_count'  => $contact->whatsapp_count,
        ]);
    }

    // GET /api/mobile/core-matches/settings
    // Lightweight settings the mobile app needs to render the right UI.
    public function settings(): JsonResponse
    {
        return response()->json([
            'visibility_scope' => (string) \App\Models\PerformanceSetting::get('matches_visibility_scope', \App\Services\Matching\MatchingService::SCOPE_AGENCY),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────
    private function authorizeMatch(User $user, ContactMatch $match): void
    {
        abort_unless($match->created_by_user_id === $user->id, 403, 'Not your match.');
    }

    private function shapeMatch(ContactMatch $m, bool $full = false): array
    {
        $base = [
            'id'            => $m->id,
            'contact_id'    => $m->contact_id,
            'name'          => $m->name,
            'status'        => $m->status,
            'listing_type'  => $m->listing_type,
            'category'      => $m->category,
            'property_type' => $m->property_type,
            'price_min'     => $m->price_min,
            'price_max'     => $m->price_max,
            'beds_min'      => $m->beds_min,
            'baths_min'     => $m->baths_min,
            'garages_min'   => $m->garages_min,
            'p24_suburb_ids' => $m->p24SuburbIdList(),
            'suburbs'        => $m->suburbs,
            'feedback_summary' => [
                'interested'     => $m->feedback->where('reaction', ContactMatchFeedback::REACTION_INTERESTED)->count(),
                'not_interested' => $m->feedback->where('reaction', ContactMatchFeedback::REACTION_NOT_INTERESTED)->count(),
                'saved'          => $m->feedback->where('reaction', ContactMatchFeedback::REACTION_SAVED)->count(),
            ],
            'updated_at'    => $m->updated_at?->toIso8601String(),
        ];

        if (!$full) return $base;

        return $base + [
            'must_have_features' => $m->must_have_features,
            'notes'              => $m->notes,
            'hidden_property_ids'=> $m->hidden_property_ids ?? [],
            'hidden_property_reasons' => $m->hidden_property_reasons ?? (object) [],
            'share_url'          => $m->sharedUrl(),
        ];
    }
}
