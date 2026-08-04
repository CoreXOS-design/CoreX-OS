<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\BuyerActivityLog;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Services\BuyerIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BuyerDetailController extends Controller
{
    /**
     * AT-363 — inline accordion page size. The agent sees the matches in
     * place, capped per fetch with a "Load more" append rather than one
     * unbounded render (some wishlists run 200+) or a navigate-away link.
     */
    private const MATCHES_PER_PAGE = 50;

    public function show(Request $request, Contact $contact)
    {
        if (!$contact->is_buyer) {
            abort(404, 'This contact is not in the buyer pipeline.');
        }

        $service = app(BuyerIntelligenceService::class);
        $tab = $request->get('tab', 'wishlists');

        // Eager-load the contact's wishlists for the new tab. Sort primary
        // first so the card layout naturally puts the primary at the top.
        $contact->load('agent');
        $contact->setRelation(
            'matches',
            $contact->matches()->orderByDesc('is_primary')->orderByDesc('updated_at')->get()
        );

        // AT-363 — per-wishlist match count badge + the default-expanded
        // wishlist's (first in the primary-first ordering above) FIRST PAGE
        // of matches, now rendered with the SAME rich match-card component
        // Core Matches results uses (Johan's review) — so resolve WITH
        // hidden properties included (matching that screen's convention:
        // visible first, hidden grouped at the bottom, still viewable/
        // un-hideable inline) and derive the visible-only badge count by
        // filtering in PHP rather than a second resolve() call. One
        // resolve() per wishlist (bounded to this buyer's own handful of
        // wishlists), reused for both the count AND, for the default-
        // expanded one, the ordered property list — never resolved twice.
        // Every OTHER wishlist's full property set is intentionally NOT
        // built here; it's fetched lazily via wishlistMatches() only if the
        // agent expands it (page 1, then "Load more" appends further pages).
        $resolver = app(\App\Services\Matching\ClientMatchResolver::class);
        $defaultExpandedId = $contact->matches->first()?->id;
        $wishlistMatchCounts = [];
        $expandedWishlistMatches = collect();
        $expandedWishlistFeedback = collect();
        $defaultExpandedHasMore = false;
        foreach ($contact->matches as $wishlist) {
            $ordered = $this->orderedPropertiesFor($resolver, $wishlist);
            $wishlistMatchCounts[$wishlist->id] = $ordered->reject(fn ($p) => $wishlist->isPropertyHidden($p->id))->count();
            if ($wishlist->id === $defaultExpandedId) {
                $expandedWishlistMatches = $ordered->forPage(1, self::MATCHES_PER_PAGE)->values();
                $expandedWishlistFeedback = $wishlist->feedback()->get()->keyBy('property_id');
                $defaultExpandedHasMore = $ordered->count() > self::MATCHES_PER_PAGE;
            }
        }

        // The match-form partial needs these collections to render its dropdowns
        // and chip options. Same source as the contact-page Core Matches tab.
        $matchCategories = \App\Models\PropertySettingItem::group('category')->get();
        $matchTypes      = \App\Models\PropertySettingItem::group('property_type')->where('active', true)->get();
        $featureOptions  = \App\Http\Controllers\CoreX\ContactMatchController::FEATURE_OPTIONS;

        return view('command-center.buyers.detail', [
            'buyer'                   => $contact,
            'tab'                     => $tab,
            'risk'                    => $service->getLostRiskScore($contact->id),
            'propertiesViewed'        => $service->getPropertiesViewed($contact->id),
            'matched'                 => $service->getMatchedProperties($contact->id),
            'preferences'             => $service->getPreferencePatterns($contact->id),
            'timeline'                => $service->getActivityTimeline($contact->id),
            'playbook'                => $service->getRetentionPlaybook($contact->id),
            'matchCategories'         => $matchCategories,
            'matchTypes'              => $matchTypes,
            'featureOptions'          => $featureOptions,
            'wishlistMatchCounts'     => $wishlistMatchCounts,
            'defaultExpandedWishlistId' => $defaultExpandedId,
            'expandedWishlistMatches' => $expandedWishlistMatches,
            'expandedWishlistFeedback' => $expandedWishlistFeedback,
            'defaultExpandedHasMore'  => $defaultExpandedHasMore,
        ]);
    }

    /**
     * AT-363 — paginated per-wishlist matches for the Wishlists tab's inline
     * accordion (page 1 on first expand, further pages via "Load more" —
     * appended in place client-side; the agent never leaves this page).
     * Reuses the exact same resolver as the show() count above and the
     * AT-360 "View Matches" route's underlying data (ClientMatchResolver)
     * — display only, no matching-logic change. Always JSON: the cards HTML
     * (rendered server-side) plus pagination metadata, so the client can
     * either fill the (empty, static) grid on first expand or append to it
     * on "Load more" — one response shape for both.
     */
    public function wishlistMatches(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $page = max(1, (int) $request->query('page', 1));
        $ordered = $this->orderedPropertiesFor(app(\App\Services\Matching\ClientMatchResolver::class), $match);
        $total = $ordered->count();
        $pageItems = $ordered->forPage($page, self::MATCHES_PER_PAGE)->values();
        $feedback = $match->feedback()->get()->keyBy('property_id');

        return response()->json([
            'html'     => view('command-center.buyers._wishlist-match-cards', [
                'matches'  => $pageItems,
                'match'    => $match,
                'contact'  => $contact,
                'feedback' => $feedback,
            ])->render(),
            'hasMore'  => ($page * self::MATCHES_PER_PAGE) < $total,
            'nextPage' => $page + 1,
            'total'    => $total,
        ]);
    }

    /**
     * Resolve a wishlist's matching properties — INCLUDING hidden ones,
     * visible first then hidden grouped at the end — matching the exact
     * ordering convention the Core Matches results screen uses (so the
     * shared <x-match-card> component renders identically and an agent can
     * still see/un-hide a hidden property inline). Display only: this is
     * the same ClientMatchResolver call every other Core Matches / AT-360
     * surface uses, just with includeHidden=true and a stable sort.
     */
    private function orderedPropertiesFor(\App\Services\Matching\ClientMatchResolver $resolver, ContactMatch $wishlist): \Illuminate\Support\Collection
    {
        $resolved = $resolver->resolve($wishlist, true);
        $visible = $resolved->reject(fn ($p) => $wishlist->isPropertyHidden($p->id))->values();
        $hidden  = $resolved->filter(fn ($p) => $wishlist->isPropertyHidden($p->id))->values();

        return $visible->concat($hidden);
    }

    /**
     * Backward-compat alias for the legacy command-center.buyers.preferences
     * route. Now delegates to addWishlist() when the contact has no matches
     * yet, otherwise updates the existing primary wishlist. Prompt 11's
     * Wishlists tab uses the explicit add/update endpoints below.
     */
    public function saveWishlist(Request $request, Contact $contact)
    {
        $validated = $this->validateWishlistPayload($request);

        DB::transaction(function () use ($contact, $validated) {
            $this->applyPreapproval($contact, $validated);
            $match = $contact->matches()->primary()->first()
                  ?? $contact->matches()->orderByDesc('updated_at')->first();
            $matchFields = $this->extractMatchFields($validated);

            if (!$match) {
                $contact->matches()->create(array_merge([
                    'agency_id'          => $contact->agency_id,
                    'created_by_user_id' => Auth::id(),
                    'status'             => ContactMatch::STATUS_ACTIVE,
                    'is_primary'         => true,
                    'listing_type'       => $matchFields['listing_type'] ?? 'sale',
                ], $matchFields));
            } else {
                $match->update($matchFields);
            }
        });

        return back()->with('success', 'Wishlist saved.');
    }

    /**
     * Create a new ContactMatch for this contact via the buyer-pipeline UI.
     * Always creates — never updates. Use updateWishlist() for edits.
     */
    public function addWishlist(Request $request, Contact $contact)
    {
        $validated = $this->validateWishlistPayload($request);

        DB::transaction(function () use ($contact, $validated) {
            $this->applyPreapproval($contact, $validated);
            $matchFields = $this->extractMatchFields($validated);

            // Observer auto-flags is_primary=true when this is the contact's
            // first match. If is_primary=true was explicitly submitted on a
            // subsequent match, the observer's saved() handler demotes others.
            $contact->matches()->create(array_merge([
                'agency_id'          => $contact->agency_id,
                'created_by_user_id' => Auth::id(),
                'status'             => ContactMatch::STATUS_ACTIVE,
                'listing_type'       => $matchFields['listing_type'] ?? 'sale',
            ], $matchFields));

            // Part 1.5 — manual buyer capture rides the SAME observer cascade (land +
            // MIC demand); tag the source so MIC demand stays attributable.
            app(\App\Services\Buyers\BuyerLeadCascadeService::class)
                ->tagBuyerSource($contact, \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_MANUAL);
        });

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Wishlist added.');
    }

    /**
     * Update an existing ContactMatch from the buyer-pipeline UI.
     */
    public function updateWishlist(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $validated = $this->validateWishlistPayload($request);

        DB::transaction(function () use ($contact, $match, $validated) {
            $this->applyPreapproval($contact, $validated);
            $match->update($this->extractMatchFields($validated));
        });

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Wishlist updated.');
    }

    /**
     * Promote a wishlist to primary. ContactMatchObserver auto-demotes the
     * previous primary via its saved() handler (spec D1).
     */
    public function setWishlistPrimary(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $match->setAsPrimary();

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Primary wishlist updated.');
    }

    /**
     * Archive (soft-delete) a wishlist. CoreX rule #1: no hard deletes.
     * If the archived row was the primary, the observer's deleted() handler
     * auto-promotes the next-most-recently-updated sibling.
     */
    public function archiveWishlist(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $match->delete(); // soft-delete via SoftDeletes trait

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Wishlist archived.');
    }

    /* =========================================================
     |  Shared wishlist payload helpers
     * ========================================================= */

    /** @return array<string,mixed> */
    private function validateWishlistPayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            // Wishlist criteria → ContactMatch.
            'listing_type'              => 'nullable|in:sale,rental',
            'category'                  => 'nullable|string|max:100',
            'property_types'            => 'nullable|array',
            'property_types.*'          => 'string|max:100',
            'p24_suburb_ids'            => 'nullable|array',
            'p24_suburb_ids.*'          => 'integer|exists:p24_suburbs,id',
            'price_min'                 => 'nullable|integer|min:0',
            'price_max'                 => 'nullable|integer|min:0',
            'beds_min'                  => 'nullable|integer|min:0|max:20',
            'bedrooms_max'              => 'nullable|integer|min:0|max:20',
            'must_have_features'        => 'nullable|array',
            'must_have_features.*'      => 'string|max:60',
            'nice_to_have_features'     => 'nullable|array',
            'nice_to_have_features.*'   => 'string|max:60',
            'deal_breakers'             => 'nullable|array',
            'deal_breakers.*'           => 'string|max:60',
            'notes'                     => 'nullable|string|max:500',
            'is_primary'                => 'nullable|boolean',
            // Preapproval block → Contact pillar (spec D3).
            'preapproval_amount'        => 'nullable|numeric|min:0',
            'preapproval_expires_at'    => 'nullable|date',
            'preapproval_institution'   => 'nullable|string|max:100',
            'name'                      => 'nullable|string|max:120',
        ]);

        // Cross-field: bedrooms_max must be >= beds_min when both present (spec D4).
        $validator->after(function ($v) {
            $bedsMin = $v->getData()['beds_min'] ?? null;
            $bedsMax = $v->getData()['bedrooms_max'] ?? null;
            if ($bedsMin !== null && $bedsMax !== null && (int) $bedsMax < (int) $bedsMin) {
                $v->errors()->add('bedrooms_max', 'Maximum bedrooms cannot be less than minimum bedrooms.');
            }

            // A feature can be in only ONE bucket (must/nice/deal-breaker). The form enforces this;
            // this is a server backstop for any bypassed submission — keeps the three arrays disjoint.
            $conflicts = \App\Models\ContactMatch::conflictingFeatureTokens(
                $v->getData()['must_have_features'] ?? [],
                $v->getData()['nice_to_have_features'] ?? [],
                $v->getData()['deal_breakers'] ?? [],
            );
            if ($conflicts) {
                $v->errors()->add('must_have_features', 'Each feature can be in only one category (Must-have, Nice, or Deal-breaker). In two: ' . implode(', ', $conflicts) . '.');
            }
        });

        return $validator->validate();
    }

    private function applyPreapproval(Contact $contact, array $validated): void
    {
        $keys = ['preapproval_amount', 'preapproval_expires_at', 'preapproval_institution'];
        $updates = array_intersect_key($validated, array_flip($keys));
        if (!empty($updates)) {
            $contact->update($updates);
        }
    }

    /**
     * Pluck only the ContactMatch-bound fields out of the validated payload.
     * Mirrors legacy property_type column (spec D2 deprecation window).
     *
     * @return array<string,mixed>
     */
    private function extractMatchFields(array $validated): array
    {
        if (isset($validated['property_types']) && !empty($validated['property_types'])) {
            $validated['property_type'] = $validated['property_types'][0] ?? null;
        }
        if (isset($validated['p24_suburb_ids']) && is_array($validated['p24_suburb_ids'])) {
            $validated['p24_suburb_ids'] = array_values(array_unique(array_filter(
                array_map('intval', $validated['p24_suburb_ids'])
            )));
        }
        return array_intersect_key($validated, array_flip([
            'listing_type', 'category', 'property_type', 'property_types',
            'p24_suburb_ids', 'price_min', 'price_max', 'beds_min', 'bedrooms_max',
            'must_have_features', 'nice_to_have_features', 'deal_breakers',
            'notes', 'is_primary', 'name',
        ]));
    }

    public function markLost(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'reason_code' => 'required|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'outcome' => 'nullable|string|max:2000',
        ]);

        $reason = DB::table('agency_lost_deal_reasons')
            ->where('code', $data['reason_code'])
            ->where('agency_id', (int) ($contact->agency_id ?: 0))   // AT-253 Rule 17: derive from the CONTACT
            ->first();

        // AT-253 Rule 17 — buyer history belongs to the CONTACT's tenant. A contact with none
        // has no history to file; refuse rather than file it under agency 1.
        if (! $contact->agency_id) {
            throw new \App\Exceptions\MissingAgencyContextException('this buyer record');
        }

        DB::table('buyer_lost_records')->insert([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id,   // AT-253 Rule 17
            'reason_code' => $data['reason_code'],
            'reason_label' => $reason->label ?? $data['reason_code'],
            'notes' => $data['notes'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'recorded_by_user_id' => auth()->id(),
            'recorded_at' => now(),
            'source' => 'manual',
            'buyer_state_at_loss' => $contact->buyer_state,
            'days_in_pipeline_at_loss' => $contact->buyer_pipeline_entered_at ? (int) $contact->buyer_pipeline_entered_at->diffInDays(now()) : null,
            'days_since_last_activity_at_loss' => $contact->last_activity_at ? (int) $contact->last_activity_at->diffInDays(now()) : null,
            'agent_owner_user_id_at_loss' => $contact->agent_id, // AT-159: owner = assigned agent, not capturer
            'branch_id_at_loss' => $contact->branch_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Transition state
        app(\App\Services\BuyerStateService::class)->transitionTo($contact, 'lost', 'manual_override', auth()->id());

        return back()->with('success', 'Buyer marked as lost. Reason recorded.');
    }

    public function reengage(Request $request, Contact $contact)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        // Mark most recent lost record as recovered
        $lastLost = DB::table('buyer_lost_records')
            ->where('contact_id', $contact->id)
            ->whereNull('recovered_at')
            ->orderByDesc('recorded_at')
            ->first();

        if ($lastLost) {
            DB::table('buyer_lost_records')->where('id', $lastLost->id)->update([
                'recovered_at' => now(),
                'recovered_by_user_id' => auth()->id(),
                'recovered_notes' => $data['notes'] ?? null,
            ]);
        }

        app(\App\Services\BuyerStateService::class)->transitionTo($contact, 'warm', 'manual_override', auth()->id());

        // AT-253 Rule 17 — buyer history belongs to the CONTACT's tenant. A contact with none
        // has no history to file; refuse rather than file it under agency 1.
        if (! $contact->agency_id) {
            throw new \App\Exceptions\MissingAgencyContextException('this buyer record');
        }

        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id,   // AT-253 Rule 17
            'activity_type' => 'manual',
            'activity_date' => now(),
            'metadata' => ['action' => 'reengaged', 'notes' => $data['notes'] ?? null],
            'logged_by_user_id' => auth()->id(),
        ]);

        $contact->updateQuietly(['last_activity_at' => now()]);

        return back()->with('success', 'Buyer re-engaged. State set to Warm.');
    }

    public function markPlaybookAction(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'action_code' => 'required|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'outcome' => 'nullable|string|max:50',
        ]);

        // AT-253 Rule 17 — buyer history belongs to the CONTACT's tenant, never agency 1.
        if (! $contact->agency_id) {
            throw new \App\Exceptions\MissingAgencyContextException('this buyer record');
        }

        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id,   // AT-253 Rule 17
            'activity_type' => 'retention_action',
            'activity_date' => now(),
            'metadata' => [
                'action_code' => $data['action_code'],
                'notes' => $data['notes'] ?? null,
                'outcome' => $data['outcome'] ?? null,
            ],
            'logged_by_user_id' => auth()->id(),
        ]);

        // Update last_activity_at
        $contact->updateQuietly(['last_activity_at' => now()]);

        return back()->with('success', 'Action recorded.');
    }

}
