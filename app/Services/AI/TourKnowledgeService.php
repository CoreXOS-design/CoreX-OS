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

    private const STOP_WORDS = [
        'where', 'do', 'i', 'to', 'a', 'an', 'the', 'is', 'are', 'can', 'how',
        'go', 'get', 'find', 'take', 'me', 'show', 'open', 'of', 'for', 'on',
        'in', 'at', 'my', 'want', 'need', 'would', 'like', 'please', 'and', 'or',
        'with', 'new', 'page', 'you', 'ellie', 'hey', 'hi', 'what', 'add', 'set',
        'up', 'does', 'this', 'that', 'from', 'have', 'has', 'it', 'be', 'use',
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

            // Require at least one hit in the title/description (not just a
            // passing mention deep in a step body) and a meaningful total.
            if ($titleHits < 1 || $score < 4) {
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
     * @return array{0:float,1:int} [score, titleHits]
     */
    private function score(array $words, array $tour): array
    {
        $titleDesc = mb_strtolower(($tour['title'] ?? '') . ' ' . ($tour['description'] ?? ''));

        $stepText = '';
        foreach ($tour['steps'] as $step) {
            $stepText .= ' ' . mb_strtolower(($step['title'] ?? '') . ' ' . ($step['body'] ?? ''));
        }

        $score = 0.0;
        $titleHits = 0;

        foreach ($words as $word) {
            if (str_contains($titleDesc, $word)) {
                $score += 3.0;
                $titleHits++;
            } elseif (str_contains($stepText, $word)) {
                $score += 1.0;
            }
        }

        return [$score, $titleHits];
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
