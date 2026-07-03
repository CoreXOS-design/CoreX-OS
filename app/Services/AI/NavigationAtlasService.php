<?php

namespace App\Services\AI;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Navigation Atlas — powers Ellie's "where do I go to…" answers.
 *
 * Reads the curated destination registry (config/corex-navigation-atlas.php),
 * scores it against the user's question with a lightweight keyword/synonym
 * matcher (no embeddings — works locally and offline), resolves each match's
 * live URL, and filters to only destinations the user is permitted to open.
 *
 * The result is formatted the same way KnowledgeSearchService formats training
 * chunks — an excerpt block carrying the destination URL — so EllieController
 * injects it into the model's knowledge context and the URL renders through the
 * existing link mechanism with no Python-service change.
 *
 * Spec: .ai/specs/ellie-navigation-atlas.md
 */
class NavigationAtlasService
{
    /**
     * Phrases that signal the user is asking WHERE to go / HOW to reach a page.
     */
    private const INTENT_PHRASES = [
        'where do i', 'where can i', 'where is', 'where are', 'where to',
        'how do i get to', 'how do i find', 'how do i create', 'how do i make',
        'how do i add', 'how do i start', 'how do i open', 'how do i access',
        'how do i register', 'how do i do',
        'take me to', 'go to', 'navigate to', 'open the', 'show me the',
        'which page', 'what page', 'link to', 'find the', 'get to the',
    ];

    private const STOP_WORDS = [
        'where', 'do', 'i', 'to', 'a', 'an', 'the', 'is', 'are', 'can',
        'how', 'go', 'get', 'find', 'take', 'me', 'show', 'open', 'of',
        'for', 'on', 'in', 'at', 'my', 'want', 'need', 'would', 'like',
        'please', 'and', 'or', 'with', 'new', 'page', 'section', 'place',
        'navigate', 'link', 'which', 'what', 'you', 'ellie', 'hey', 'hi',
    ];

    /**
     * Does this message look like a navigation ("where do I go") question?
     */
    public function isNavigationQuery(string $message): bool
    {
        $lower = mb_strtolower(trim($message));

        foreach (self::INTENT_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        // Also treat a leading "where …" as navigation intent.
        return str_starts_with($lower, 'where ');
    }

    /**
     * Search the atlas for destinations matching the query, filtered to what
     * the given user can access.
     *
     * @return array<int, array{route:string,label:string,category:string,blurb:string,url:string,score:float}>
     */
    public function search(string $query, ?User $user, int $limit = 3): array
    {
        $registry = config('corex-navigation-atlas', []);
        if (empty($registry)) {
            return [];
        }

        $queryWords = $this->tokenize($query);
        $rawLower = mb_strtolower($query);

        if (empty($queryWords) && trim($rawLower) === '') {
            return [];
        }

        $scored = [];

        foreach ($registry as $routeName => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $score = $this->score($rawLower, $queryWords, $entry);
            if ($score <= 0) {
                continue;
            }

            $resolved = $this->resolve($routeName, $user, $entry);
            if ($resolved === null) {
                continue; // unroutable, or user lacks permission
            }

            $scored[] = [
                'route'    => $routeName,
                'label'    => (string) ($entry['label'] ?? $routeName),
                'category' => (string) ($entry['category'] ?? ''),
                'blurb'    => (string) ($entry['blurb'] ?? ''),
                'url'      => $resolved,
                'score'    => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Build a knowledge-context block (and matching source rows) for Ellie.
     *
     * @return array{context:string, sources:array}
     */
    public function buildContext(string $query, ?User $user, int $limit = 3): array
    {
        $matches = $this->search($query, $user, $limit);
        if (empty($matches)) {
            return ['context' => '', 'sources' => []];
        }

        // Dominance filter: keep only destinations close to the best match, so a
        // clear winner (e.g. the property-based presentation flow) isn't diluted
        // by weak also-rans (e.g. the Presentations browse page) that make the
        // model blend answers and invent steps. Always keep at least the top one.
        $topScore = $matches[0]['score'];
        $matches = array_values(array_filter(
            $matches,
            fn ($m) => $m['score'] >= $topScore * 0.6
        ));

        $parts = [];
        $sources = [];

        foreach ($matches as $m) {
            $header = "--- CoreX navigation: {$m['label']}";
            if ($m['category'] !== '') {
                $header .= " ({$m['category']})";
            }
            $header .= " → {$m['url']} ---";

            $parts[] = $header . "\n" . $m['blurb']
                . "\nDirect link: {$m['url']}";

            $sources[] = [
                'title'    => $m['label'],
                'url'      => $m['url'],
                'category' => $m['category'],
                'is_navigation' => true,
            ];
        }

        return [
            'context' => implode("\n\n", $parts),
            'sources' => $sources,
        ];
    }

    /**
     * Score an entry against the query. Phrase (synonym) matches weigh heaviest,
     * then label word matches, then any-field word matches.
     */
    private function score(string $rawLower, array $queryWords, array $entry): float
    {
        $keywords = array_map('mb_strtolower', (array) ($entry['keywords'] ?? []));
        $label    = mb_strtolower((string) ($entry['label'] ?? ''));
        $blurb    = mb_strtolower((string) ($entry['blurb'] ?? ''));
        $haystack = trim($label . ' ' . implode(' ', $keywords) . ' ' . $blurb);

        $score = 0.0;

        // Strongest signal: a full multi-word synonym appears verbatim in the query.
        foreach ($keywords as $kw) {
            if ($kw === '') {
                continue;
            }
            if (str_contains($kw, ' ') && str_contains($rawLower, $kw)) {
                $score += 5.0;
            }
        }

        if (empty($queryWords)) {
            return $score;
        }

        $labelWords = $this->tokenize($label);

        foreach ($queryWords as $word) {
            // Exact single-word synonym.
            if (in_array($word, $keywords, true)) {
                $score += 3.0;
                continue;
            }
            // Word appears in the label.
            if (in_array($word, $labelWords, true)) {
                $score += 2.0;
                continue;
            }
            // Word appears anywhere (keywords list or blurb).
            if ($word !== '' && str_contains($haystack, $word)) {
                $score += 1.0;
            }
        }

        return $score;
    }

    /**
     * Public helper: the relative URL for a route IF the user may open it,
     * else null. Used by other AI services (e.g. TourKnowledgeService) to add a
     * link to a destination without re-implementing the permission logic.
     */
    public function urlIfAccessible(string $routeName, ?User $user): ?string
    {
        return $this->resolve($routeName, $user);
    }

    /**
     * Resolve a route name to a relative URL, returning null if the route is
     * missing, needs parameters, or the user cannot access it.
     *
     * Access is enforced the same way the routes themselves are:
     *   - `permission:<key>` middleware → User::hasPermission($key)
     *   - `owner_only` middleware       → User::isOwnerRole()
     *   - optional registry `permission` override → User::hasPermission()
     *     (for routes gated in-controller rather than by middleware).
     */
    private function resolve(string $routeName, ?User $user, array $entry = []): ?string
    {
        $route = Route::getRoutes()->getByName($routeName);
        if ($route === null) {
            return null;
        }

        // Only paramless destinations belong in the atlas; skip if any required.
        if (! empty($route->parameterNames())) {
            return null;
        }

        foreach ($route->middleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'permission:')) {
                $key = trim(substr($middleware, strlen('permission:')));
                if ($key !== '' && ($user === null || ! $user->hasPermission($key))) {
                    return null;
                }
            } elseif ($middleware === 'owner_only') {
                if ($user === null || ! $user->isOwnerRole()) {
                    return null;
                }
            }
        }

        // Optional explicit gate for routes enforced in-controller (no middleware).
        $override = trim((string) ($entry['permission'] ?? ''));
        if ($override !== '' && ($user === null || ! $user->hasPermission($override))) {
            return null;
        }

        try {
            return route($routeName, [], false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $clean = preg_replace('/[^\w\s]/u', ' ', mb_strtolower($text));
        $words = preg_split('/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $words,
            fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, self::STOP_WORDS, true) && ! is_numeric($w)
        ));
    }
}
