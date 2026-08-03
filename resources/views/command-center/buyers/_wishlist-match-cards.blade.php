{{-- AT-363 — one page of a wishlist's match cards, using the SAME rich
     match-card component as the Core Matches results screen (Johan's
     review) — <x-match-card>: photo, beds/baths/m², agent, View Property
     (new tab) + Hide/Unhide. NO "Convert to Deal" (removed from every
     agent-facing match surface). Fed either from BuyerDetailController::
     show() (the default-expanded wishlist's first page, pre-rendered
     directly into the static list wrapper in detail.blade.php) or
     ::wishlistMatches() (every page after that, fetched as JSON and
     appended into that same wrapper client-side — "Load more" never
     replaces it, only adds to it).

     $matches — ordered Property collection (visible first, hidden last),
     annotated with match_score/match_tier by ClientMatchResolver.
     $match, $contact — required by <x-match-card> for hide/unhide + view
     counts. $feedback — keyed-by-property_id collection, optional.

     No wrapping element here on purpose — the list + its scroll container
     are STATIC markup in detail.blade.php so "Load more" can
     insertAdjacentHTML into it without ever replacing what's already there. --}}
@forelse($matches as $property)
    <x-match-card :property="$property" :match="$match" :contact="$contact" :feedback="$feedback[$property->id] ?? null" />
@empty
    <div class="text-xs py-4 text-center" style="color: var(--text-muted);">No matching properties yet for this wishlist.</div>
@endforelse
