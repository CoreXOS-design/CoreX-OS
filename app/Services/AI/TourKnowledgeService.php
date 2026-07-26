<?php

namespace App\Services\AI;

use App\Models\User;
use App\Support\Tours\TourRegistry;

/**
 * Tour Knowledge — turns the 88 guided-tour definitions (app/Support/Tours) into
 * "how do I do X" knowledge for Ellie.
 *
 * Each tour is an ordered, plain-language, agent-facing walkthrough of a real
 * feature (title + description + steps). That is exactly the step-by-step "how"
 * Ellie lacked. This service reads the live TourRegistry (never a stale copy),
 * scores tours against the user's question with a lightweight keyword matcher
 * (no embeddings — works offline/locally), filters to tours the user may see
 * (TourRegistry::visibleTo), and formats the matching walkthrough — with a link
 * to the page when one can be resolved — for injection into Ellie's context.
 *
 * Spec: .ai/specs/ellie-tour-knowledge.md
 */
class TourKnowledgeService
{
    private NavigationAtlasService $nav;

    public function __construct(NavigationAtlasService $nav)
    {
        $this->nav = $nav;
    }

    /**
     * Minimum share of the question a tour must account for to be offered.
     * See score() for why this is a ratio and not an absolute point total.
     */
    private const MIN_COVERAGE = 0.70;

    private const STOP_WORDS = [
        'where', 'do', 'i', 'to', 'a', 'an', 'the', 'is', 'are', 'can', 'how',
        'go', 'get', 'find', 'take', 'me', 'show', 'open', 'of', 'for', 'on',
        'in', 'at', 'my', 'want', 'need', 'would', 'like', 'please', 'and', 'or',
        'with', 'new', 'page', 'you', 'ellie', 'hey', 'hi', 'what', 'add', 'set',
        'up', 'does', 'this', 'that', 'from', 'have', 'has', 'it', 'be', 'use',
        // Generic filler that carries no feature meaning. These were diluting
        // coverage and pushing correct tours under the floor — "how does the
        // commercial evaluation work in the system" spent half its tokens on
        // "work" and "system" and lost the Commercial Evaluations walkthrough.
        'work', 'works', 'system', 'program', 'thing', 'things', 'there', 'here',
        'step', 'steps', 'way', 'should', 'could', 'will', 'make', 'made', 'doing',
        'about', 'into', 'out', 'when', 'which', 'who', 'why', 'been', 'was',
    ];

    /**
     * Score and rank the tour catalogue against the query for this user.
     *
     * @return array<int, array{key:string,title:string,description:string,steps:array,url:?string,score:float}>
     */
    public function search(string $query, ?User $user, int $limit = 2): array
    {
        $words = $this->tokenize($query);
        if (empty($words)) {
            return [];
        }

        $scored = [];

        foreach (TourRegistry::all() as $key => $tour) {
            if (! is_array($tour) || empty($tour['steps'])) {
                continue;
            }
            if (! TourRegistry::visibleTo($tour, $user)) {
                continue;
            }

            [$score, $titleHits] = $this->score($words, $tour);

            // Require a hit in the title/description (not just a passing mention
            // deep in a step body) AND enough of the question to be accounted for.
            //
            // The old bar was an ABSOLUTE `score < 4`, which a long question could
            // clear on noise alone: "step by step how to make a viewing pack"
            // returned the "Document packs" walkthrough (score 9) — a different
            // feature — and "Client want to leave me a review where does he do it"
            // returned "Reviewing & assigning a split pack", matched purely on
            // review/Reviewing. Injecting the wrong tour is worse than injecting
            // none, because the system prompt tells the model to follow the steps
            // exactly, so Ellie confidently describes the wrong feature.
            //
            // Coverage is normalised by the number of meaningful query tokens, so
            // a precise short match can no longer be beaten by a long noisy one.
            // Spec: .ai/specs/ellie-v2.md §5.2.
            if ($titleHits < 1 || $score < self::MIN_COVERAGE) {
                continue;
            }

            $scored[] = [
                'key'         => (string) $key,
                'title'       => (string) ($tour['title'] ?? $key),
                'description' => (string) ($tour['description'] ?? ''),
                'steps'       => $tour['steps'],
                'url'         => isset($tour['route']) ? $this->nav->urlIfAccessible((string) $tour['route'], $user) : null,
                'score'       => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Dominance filter — keep only tours close to the best match so a clear
        // winner isn't diluted by weak also-rans.
        if (! empty($scored)) {
            $top = $scored[0]['score'];
            $scored = array_values(array_filter($scored, fn ($t) => $t['score'] >= $top * 0.6));
        }

        return array_slice($scored, 0, $limit);
    }

    /**
     * Build a knowledge-context block (and source rows) of how-to steps for Ellie.
     *
     * @return array{context:string, sources:array}
     */
    public function buildContext(string $query, ?User $user, int $limit = 2): array
    {
        $matches = $this->search($query, $user, $limit);
        if (empty($matches)) {
            return ['context' => '', 'sources' => []];
        }

        $parts = [];
        $sources = [];

        foreach ($matches as $m) {
            $header = "--- CoreX how-to: {$m['title']}";
            if ($m['url']) {
                $header .= " → {$m['url']}";
            }
            $header .= ' ---';

            $lines = [$header];
            if ($m['description'] !== '') {
                $lines[] = $m['description'];
            }
            $lines[] = 'Steps:';

            $n = 1;
            foreach ($m['steps'] as $step) {
                $title = trim((string) ($step['title'] ?? ''));
                $body  = trim((string) ($step['body'] ?? ''));
                if ($title === '' && $body === '') {
                    continue;
                }
                $lines[] = $title !== '' ? "{$n}. {$title}: {$body}" : "{$n}. {$body}";
                $n++;
            }

            if ($m['url']) {
                $lines[] = "Direct link: {$m['url']}";
            }

            $parts[] = implode("\n", $lines);

            $sources[] = [
                'title'    => $m['title'],
                'url'      => $m['url'],
                'is_tour'  => true,
            ];
        }

        return [
            'context' => implode("\n\n", $parts),
            'sources' => $sources,
        ];
    }

    /**
     * Normalised relevance: how much of the QUESTION this tour accounts for,
     * on a 0..1 scale, rather than how many points it can accumulate.
     *
     * A title/description hit is worth full credit, a step-body hit partial —
     * a tour whose title names the thing you asked about is answering you; one
     * that merely mentions it in passing on step 7 is not.
     *
     * @return array{0:float,1:int} [coverage 0..1, titleHits]
     */
    private function score(array $words, array $tour): array
    {
        $titleDesc = mb_strtolower(($tour['title'] ?? '') . ' ' . ($tour['description'] ?? ''));

        $stepText = '';
        foreach ($tour['steps'] as $step) {
            $stepText .= ' ' . mb_strtolower(($step['title'] ?? '') . ' ' . ($step['body'] ?? ''));
        }

        $credit    = 0.0;
        $titleHits = 0;
        $skipNext  = false;

        foreach (array_values($words) as $i => $word) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            // Compound check first: users split words CoreX joins. "whistle
            // blower" must find the "whistleblower" tour, and "where house"
            // must find "warehouse". Credit both tokens as one confident hit.
            $next = $words[$i + 1] ?? null;
            if ($next !== null) {
                $joined = $word . $next;
                if ($this->mentions($titleDesc, $joined)) {
                    $credit    += 2.0;
                    $titleHits += 2;
                    $skipNext   = true;
                    continue;
                }
                if ($this->mentions($stepText, $joined)) {
                    $credit  += 0.7;
                    $skipNext = true;
                    continue;
                }
            }

            if ($this->mentions($titleDesc, $word)) {
                $credit += 1.0;
                $titleHits++;
            } elseif ($this->mentions($stepText, $word)) {
                $credit += 0.35;
            }
        }

        $coverage = $credit / max(count($words), 1);

        return [$coverage, $titleHits];
    }

    /**
     * Whole-word-ish containment.
     *
     * Plain `str_contains` created false friends that injected the wrong
     * walkthrough — "review" matched "Reviewing & assigning a split pack", and
     * "pack" matched inside unrelated compound words. Anchoring on a word
     * boundary keeps legitimate stem matches (listing/listings) while dropping
     * the accidental substring hits.
     */
    private function mentions(string $haystack, string $word): bool
    {
        return (bool) preg_match('/\b' . preg_quote($word, '/') . '/u', $haystack);
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
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOP_WORDS, true) && ! is_numeric($w)
        ));
    }
}
