<?php

namespace App\Services\Navigation;

use App\Models\User;
use App\Models\UserNavFavourite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Sidebar Favourites — validation and persistence for a user's pinned pages.
 * Spec: .ai/specs/sidebar-favourites.md
 *
 * The one rule that governs this whole class: a favourite can never grant
 * access. Every path a user submits is resolved against the real route table
 * and checked against the same gates the routes themselves use, so a user can
 * only ever pin a page they can already open.
 */
class NavFavouriteService
{
    /** Per-request memo: path => resolved route or false. */
    private array $routeCache = [];

    /** Per-request memo: "path|userId" => bool. */
    private array $accessCache = [];

    /** uri => Route for every parameterless GET route. Built once per instance. */
    private ?array $pathMap = null;

    /** @var \WeakMap<object, array<string, \Illuminate\Routing\Route>>|null */
    private static ?\WeakMap $pathMaps = null;

    // ── Reading ──────────────────────────────────────────────────────────

    /**
     * The user's live favourites, in their chosen order.
     *
     * A favourite whose page has since been renamed, removed, or put behind a
     * permission the user no longer holds is SKIPPED rather than rendered as a
     * dead link (BUILD_STANDARD §4 — deleted-related-record renders gracefully).
     *
     * @return Collection<int, UserNavFavourite>
     */
    public function forUser(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return UserNavFavourite::query()
            ->forUser($user->id)
            ->ordered()
            ->get()
            ->filter(fn (UserNavFavourite $fav) => $this->isAccessible($fav->path(), $user))
            ->values();
    }

    // ── Writing ──────────────────────────────────────────────────────────

    /**
     * Replace the user's favourites with the submitted list.
     *
     * Returns [savedCount, rejectedCount]. Rejected entries are the ones the
     * user may not open or that point at no page at all — they are dropped and
     * reported, never written and never silently swallowed.
     *
     * @param  array<int, array{key?: mixed, label?: mixed}>  $items  in display order
     * @return array{saved: int, rejected: int}
     */
    public function sync(User $user, array $items): array
    {
        [$clean, $rejected] = $this->normalise($items, $user);

        DB::transaction(function () use ($user, $clean) {
            $keep = [];

            foreach (array_values($clean) as $order => $entry) {
                // withTrashed() is NOT optional. UNIQUE(user_id, nav_key) has no
                // soft-delete awareness, so a page the user un-pinned earlier
                // still owns its row — a plain create() here throws a raw
                // duplicate-key error at the user the second time they pin the
                // same page. BUILD_STANDARD §5a.
                $fav = UserNavFavourite::withTrashed()
                    ->where('user_id', $user->id)
                    ->where('nav_key', $entry['key'])
                    ->first();

                if ($fav) {
                    if ($fav->trashed()) {
                        $fav->restore();
                    }
                    $fav->fill(['label' => $entry['label'], 'sort_order' => $order])->save();
                } else {
                    $fav = UserNavFavourite::create([
                        'user_id' => $user->id,
                        'nav_key' => $entry['key'],
                        'label' => $entry['label'],
                        'sort_order' => $order,
                    ]);
                }

                $keep[] = $fav->id;
            }

            // Anything the user un-ticked is ARCHIVED, never hard-deleted
            // (CLAUDE.md Non-negotiable #1). Re-ticking it restores the row above.
            UserNavFavourite::query()
                ->forUser($user->id)
                ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
                ->delete();
        });

        return ['saved' => count($clean), 'rejected' => $rejected];
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * Trim, de-duplicate, reject anything unusable, and cap the label length.
     *
     * @return array{0: array<int, array{key: string, label: string}>, 1: int}
     */
    private function normalise(array $items, User $user): array
    {
        $clean = [];
        $rejected = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                $rejected++;
                continue;
            }

            $path = $this->normalisePath(is_scalar($item['key'] ?? null) ? (string) $item['key'] : '');

            if ($path === null || ! $this->isAccessible($path, $user)) {
                $rejected++;
                continue;
            }

            $key = 'p:'.$path;

            // A page ticked twice is the same favourite — absorb, don't reject.
            if (isset($clean[$key])) {
                continue;
            }

            $clean[$key] = [
                'key' => $key,
                // label is NOT NULL: it always gets a value, for every input
                // combination, even a tampered post with no label at all.
                'label' => $this->resolveLabel(
                    is_scalar($item['label'] ?? null) ? (string) $item['label'] : '',
                    $path
                ),
            ];
        }

        return [$clean, $rejected];
    }

    /**
     * Accept 'p:/corex/properties' or '/corex/properties'. Reject everything
     * else — absolute URLs to other hosts, traversal, schemes, empty strings.
     * A favourite can only ever be a same-origin path on this install.
     */
    public function normalisePath(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'p:')) {
            $value = trim(substr($value, 2));
        }

        // An absolute URL is only acceptable if it is this very host; take its
        // path and discard the rest.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) || str_starts_with($value, '//')) {
            $parts = parse_url($value);
            $host = $parts['host'] ?? null;

            if ($host === null || $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                return null;
            }

            $value = $parts['path'] ?? '';
        }

        // Strip any query string / fragment — a favourite is a page, not a view
        // state, and keeping them would fork the unique key per filter combo.
        $value = strtok($value, '?') ?: '';
        $value = strtok($value, '#') ?: $value;
        $value = trim($value);

        if ($value === '' || ! str_starts_with($value, '/')) {
            return null;
        }

        if (str_contains($value, '..') || str_contains($value, "\0")) {
            return null;
        }

        // Normalise a trailing slash so '/corex/properties' and
        // '/corex/properties/' are one favourite, not two.
        if ($value !== '/' && str_ends_with($value, '/')) {
            $value = rtrim($value, '/');
        }

        return strlen($value) > 180 ? null : $value;
    }

    /**
     * Can this user actually open this path?
     *
     * Enforced exactly the way the routes enforce it themselves:
     *   - `permission:<key>[,<key>…]` → hasPermission(), ANY of them (OR)
     *   - `owner_only`                → isOwnerRole()
     *
     * Honest about its limits: a route gated in its controller rather than by
     * middleware passes this check, the same as it does for the Navigation
     * Atlas. That is safe — the destination page still enforces its own access
     * on arrival, so the worst case is a favourite that lands on a refusal,
     * never one that bypasses a gate.
     */
    public function isAccessible(string $path, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $memo = $path.'|'.$user->id;

        if (array_key_exists($memo, $this->accessCache)) {
            return $this->accessCache[$memo];
        }

        $route = $this->matchRoute($path);

        if ($route === null) {
            return $this->accessCache[$memo] = false;
        }

        foreach ($route->middleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'permission:')) {
                $keys = array_filter(array_map('trim', explode(',', substr($middleware, 11))));

                if ($keys !== [] && ! collect($keys)->contains(fn ($k) => $user->hasPermission($k))) {
                    return $this->accessCache[$memo] = false;
                }
            } elseif ($middleware === 'owner_only') {
                if (! $user->isOwnerRole()) {
                    return $this->accessCache[$memo] = false;
                }
            }
        }

        return $this->accessCache[$memo] = true;
    }

    /**
     * The registered PARAMETERLESS GET route serving this path, or null.
     *
     * Deliberately NOT \Route::getRoutes()->match(): that calls Route::bind() on
     * the matched route, which MUTATES the shared Route instance in the global
     * collection — the very objects serving the current request. Re-binding one
     * mid-render from a synthetic request can rewrite the live request's route
     * parameters. This builds a read-only uri => route map instead and never
     * touches a Route object.
     *
     * Parameterless is a hard rule, not an optimisation: a favourite is a PAGE,
     * never a deep link to one record. It also means a crafted post of
     * /corex/properties/5 resolves to nothing and is refused.
     */
    private function matchRoute(string $path): ?\Illuminate\Routing\Route
    {
        if (array_key_exists($path, $this->routeCache)) {
            return $this->routeCache[$path] ?: null;
        }

        $route = $this->pathMap()[$path] ?? false;

        $this->routeCache[$path] = $route;

        return $route ?: null;
    }

    /**
     * uri => Route for every parameterless GET route, built once per instance.
     * Not static: the route table is rebuilt per application instance (every
     * test), and a static map would serve a stale one.
     *
     * @return array<string, \Illuminate\Routing\Route>
     */
    private function pathMap(): array
    {
        if ($this->pathMap !== null) {
            return $this->pathMap;
        }

        // Shared across instances (the sidebar resolves this service on every page) but
        // keyed by the live RouteCollection object in a WeakMap, so a rebuilt route table
        // (every test, or route cache reload) can never be served a stale map.
        $collection = Route::getRoutes();
        self::$pathMaps ??= new \WeakMap();

        if (isset(self::$pathMaps[$collection])) {
            return $this->pathMap = self::$pathMaps[$collection];
        }

        $map = [];

        foreach ($collection->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if ($route->parameterNames() !== []) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');

            if ($uri !== '/') {
                $uri = rtrim($uri, '/');
            }

            $map[$uri] ??= $route;
        }

        self::$pathMaps[$collection] = $map;

        return $this->pathMap = $map;
    }

    /**
     * The label to store. The picker sends the sidebar's own label; a tampered
     * or empty post falls back to the last path segment, humanised, so the
     * NOT-NULL column always gets something a human can read.
     */
    private function resolveLabel(string $submitted, string $path): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $submitted) ?? '');

        if ($label === '') {
            $segment = (string) Str::of($path)->afterLast('/')->replace('-', ' ');
            $label = $segment !== '' ? Str::title($segment) : 'Page';
        }

        return Str::limit($label, 100, '');
    }
}
