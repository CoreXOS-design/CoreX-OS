<?php

namespace App\Services\AI;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Training\TrainingDoc;
use App\Models\Training\TrainingDocChunk;

class KnowledgeSearchService
{
    private EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Search knowledge base for relevant chunks using vector similarity.
     *
     * @return array{context: string, sources: array}
     */
    public function search(string $query, int $limit = 5): array
    {
        try {
            // Embedded as a QUERY, not a passage — BGE is trained
            // asymmetrically and the query side takes an instruction prefix.
            $queryEmbedding = $this->embeddingService->embed($query, EmbeddingService::KIND_QUERY);

            // No query embedding — the embedding service is down or the model
            // failed to load. (Historically: the OpenAI account hit
            // `insufficient_quota` and every embed() call returned null.)
            //
            // This path USED TO return training-doc keyword matches only, which
            // silently discarded all ~950 embedded knowledge chunks — every
            // company document and every piece of SA legislation — for as long
            // as the provider was down. Ellie answered "I don't have that in our
            // company documents" about documents she was holding. A dead
            // embedding provider must degrade, not zero out the knowledge base
            // (BUILD_STANDARD §3 — prevent or absorb, never break).
            //
            // This check comes FIRST, before the has_embedding guards below:
            // keyword search does not care whether a chunk was ever embedded,
            // and gating it behind "are there embedded chunks?" made the whole
            // fallback unreachable on a KB that had never been embedded at all.
            if ($queryEmbedding === null) {
                \Log::warning('KnowledgeSearchService: no query embedding — falling back to keyword search across the full KB.');

                return $this->buildKeywordResults($query, $limit);
            }

            // Load all embedded chunks from active, ready, ellie-enabled documents
            $chunks = KnowledgeChunk::whereHas('document', function ($q) {
                $q->where('is_active', true)
                  ->where('status', 'ready')
                  ->where('is_ellie_enabled', true);
            })
                ->where('has_embedding', true)
                ->with('document')
                ->get();

            // Also load training doc chunks (always included — canonical user-facing answers)
            // Try embedding-based first; fall back to keyword-based if no embeddings available
            $trainingChunks = TrainingDocChunk::where('has_embedding', true)
                ->with('doc')
                ->get();

            // Keyword fallback: if no training embeddings exist, search by content keywords
            if ($trainingChunks->isEmpty()) {
                $trainingChunks = $this->keywordMatchTrainingChunks($query, $limit * 3);
            }

            // Nothing embedded at all — keyword search is the only option left.
            if ($chunks->isEmpty() && $trainingChunks->isEmpty()) {
                return $this->buildKeywordResults($query, $limit);
            }

            // Extract structural signals from query for hybrid scoring
            $stopWords = ['what', 'is', 'the', 'of', 'a', 'an', 'in', 'for', 'to', 'how', 'does', 'do', 'whats', 'tell', 'me', 'about', 'can', 'you'];
            preg_match_all('/\b(\d+(?:\.\d+)*)\b/', $query, $numberMatches);
            $queryNumbers = $numberMatches[1] ?? [];
            $queryWords = array_values(array_filter(
                preg_split('/\s+/', mb_strtolower(preg_replace('/[^\w\s]/', '', $query))),
                fn ($w) => $w !== '' && !in_array($w, $stopWords) && !is_numeric($w)
            ));
            $totalMeaningfulWords = max(count($queryWords), 1);

            // Score each chunk: hybrid = (cosine * 0.7) + (structural * 0.3)
            $scoreChunk = function ($chunk, bool $isTraining) use ($queryEmbedding, $queryNumbers, $queryWords, $totalMeaningfulWords) {
                // Compute cosine similarity if both embeddings exist
                $cosine = 0.0;
                if ($chunk->embedding && $queryEmbedding) {
                    $chunkEmb = is_array($chunk->embedding) ? $chunk->embedding : json_decode($chunk->embedding, true);
                    if (is_array($chunkEmb) && count($chunkEmb) > 0) {
                        $cosine = $this->embeddingService->cosineSimilarity($queryEmbedding, $chunkEmb);
                    }
                }

                // For KB chunks use section_title, for training chunks use heading_path
                $title = mb_strtolower($chunk->section_title ?? $chunk->heading_path ?? '');

                // Numbered reference matching
                $numberScore = 0.0;
                foreach ($queryNumbers as $num) {
                    $escaped = preg_quote($num, '/');
                    if (preg_match('/^' . $escaped . '(?:[\.\s]|$)/', $title)) {
                        $numberScore = 1.0;
                        break;
                    } elseif (str_contains($title, $num)) {
                        $numberScore = max($numberScore, 0.5);
                    }
                }

                // Keyword title matching
                $keywordScore = 0.0;
                if ($title !== '') {
                    $matchCount = 0;
                    foreach ($queryWords as $word) {
                        if (str_contains($title, $word)) {
                            $matchCount++;
                        }
                    }
                    $keywordScore = $matchCount / $totalMeaningfulWords;
                }

                // DOCUMENT-NAME matching — the signal that was missing here.
                //
                // A clause number alone does not identify a clause: "Offer to
                // Purchase clause 13" anchors equally well against the Property
                // Practitioners Act's own §13, and legislation is long enough to
                // win on generic similarity. The user named the document; that
                // has to count. Without this, clause 11 and 13 resolved to the
                // Alienation of Land Act and the PPA instead of the OTP.
                //
                // (The keyword-only path scores this too — the two paths must
                // agree, or which one is active silently changes the answer.)
                $docTitle = mb_strtolower((string) ($isTraining
                    ? ($chunk->doc->title ?? '')
                    : ($chunk->document->title ?? '')));

                $docScore = 0.0;
                if ($docTitle !== '' && $totalMeaningfulWords > 0) {
                    $docHits = 0;
                    foreach ($queryWords as $word) {
                        if (str_contains($docTitle, $word)) {
                            $docHits++;
                        }
                    }
                    $docScore = $docHits / $totalMeaningfulWords;
                }

                // Weights: naming a document is an EXPLICIT instruction, so it
                // is weighted close to raw similarity rather than as a tiebreak.
                // At 0.20 the Alienation of Land Act still beat Offer to
                // Purchase on "clause 11", because that Act's §11 ("when land
                // has been sold in terms of a contract") is semantically nearer
                // to the phrase "offer to purchase" than the OTP's own
                // "FIXTURES AND FITTINGS" is. Similarity alone cannot resolve
                // that; the user telling us which document does.
                $structural = max($numberScore, $keywordScore);
                $hybrid = ($cosine * 0.45) + ($structural * 0.25) + ($docScore * 0.30);

                // Training chunks used to get a ×1.2 boost, to compensate for
                // never having been embedded — they could only ever be reached
                // by keyword, so without a thumb on the scale they lost every
                // comparison to an embedded KB chunk.
                //
                // They are embedded now, by the same model, so that handicap is
                // gone and the boost became an over-correction: it buried the
                // legal documents. "What if my buyer cannot get a home loan"
                // returned three CoreX training guides instead of Offer to
                // Purchase §2.1 BOND FINANCE. Both pools now compete on equal
                // footing and similarity decides.
                //
                // The two pools answer different questions — training guides
                // for "how do I do X in CoreX", legal documents for "what does
                // this clause say" — and a blanket multiplier cannot tell those
                // apart. The query itself can.

                return ['chunk' => $chunk, 'score' => $hybrid, 'is_training' => $isTraining];
            };

            $scored = $chunks->map(fn ($c) => $scoreChunk($c, false))
                ->merge($trainingChunks->map(fn ($c) => $scoreChunk($c, true)));

            // Drop below-threshold chunks BEFORE limiting, then take the top N.
            //
            // The order used to be take($limit)->filter(...), which silently lost
            // recall: it sliced the top N and then discarded some or all of them,
            // so a query whose 3rd-best chunk was weak returned 2 results instead
            // of promoting the 4th. Filter first so $limit counts SURVIVORS.
            // Lower threshold for training chunks (keyword-only matches score
            // lower than embedding matches). Spec: .ai/specs/ellie-v2.md §5.3.
            $topChunks = $scored
                ->filter(fn ($item) => $item['score'] >= ($item['is_training'] ? 0.15 : 0.3))
                ->sortByDesc('score')
                ->take($limit);

            if ($topChunks->isEmpty()) {
                return ['context' => '', 'sources' => []];
            }

            $contextParts = [];
            $sources = [];

            foreach ($topChunks as $item) {
                $chunk = $item['chunk'];
                $isTraining = $item['is_training'] ?? false;

                if ($isTraining) {
                    $doc = $chunk->doc;
                    $header = "--- From training guide: {$doc->title}";
                    if ($chunk->heading_path) {
                        $header .= " ({$chunk->heading_path})";
                    }
                    $anchor = $chunk->section_anchor ? "#{$chunk->section_anchor}" : '';
                    $header .= " → /corex/training-help/{$doc->slug}{$anchor}";
                    $header .= " ---";

                    $contextParts[] = $header . "\n" . $chunk->content;

                    $sources[] = [
                        'document_id'  => $doc->id,
                        'title'        => $doc->title,
                        'section'      => $chunk->heading_path,
                        'page'         => null,
                        'category'     => 'Training',
                        'url'          => "/corex/training-help/{$doc->slug}{$anchor}",
                        'is_training'  => true,
                    ];
                } else {
                    $doc = $chunk->document;
                    $header = "--- From: {$doc->title}";
                    if ($chunk->section_title) {
                        $header .= " ({$chunk->section_title})";
                    }
                    if ($chunk->page_number) {
                        $header .= " [Page {$chunk->page_number}]";
                    }
                    $header .= " ---";

                    $contextParts[] = $header . "\n" . $chunk->content;

                    $sources[] = [
                        'document_id' => $doc->id,
                        'title'       => $doc->title,
                        'section'     => $chunk->section_title,
                        'page'        => $chunk->page_number,
                        'category'    => $doc->category->name ?? null,
                    ];
                }
            }

            return [
                'context' => implode("\n\n", $contextParts),
                'sources' => $sources,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Knowledge search failed: ' . $e->getMessage());
            return ['context' => '', 'sources' => []];
        }
    }

    /**
     * Determine if the message warrants a knowledge base search.
     */
    public function shouldSearch(string $message): bool
    {
        // Always search when KB documents with embeddings are available
        if (KnowledgeDocument::where('status', 'ready')->where('is_ellie_enabled', true)->exists()) {
            return true;
        }

        // Also search when training docs have been ingested
        if (TrainingDocChunk::where('has_embedding', true)->exists()) {
            return true;
        }

        // Also search when training docs exist (even without embeddings — keyword fallback)
        if (TrainingDoc::exists()) {
            return true;
        }

        // Fallback: keyword gate for when no ready documents exist (avoids unnecessary queries)
        $lower = mb_strtolower($message);

        $patterns = [
            'what does', 'what is', 'what are',
            'clause', 'section', 'policy', 'procedure',
            'otp', 'mandate', 'fica', 'compliance',
            'commission', 'split', 'transfer', 'trust account',
            'lease', 'rental', 'tell me about', 'explain',
            'according to', 'cpd', 'ppra', 'eaab',
            'conveyancing', 'bond', 'contract', 'agreement',
            'regulation', 'rule', 'guideline', 'requirement',
            'training', 'onboarding', 'branding', 'marketing',
            'how do i', 'how does', 'what should',
            'knowledge base', 'document says',
            'evaluation', 'valuation', 'commercial', 'agricultural',
            'hospitality', 'industrial', 'crop', 'livestock',
            'comparable', 'financial', 'calculator', 'bond overpayment',
            'fee scale', 'knowledge', 'document',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keyword-based fallback for training chunks when no embeddings are available.
     */
    private function keywordMatchTrainingChunks(string $query, int $limit = 15): \Illuminate\Support\Collection
    {
        $words = $this->meaningfulWords($query);

        if (empty($words)) {
            return collect();
        }

        // Fetch chunks that match ANY keyword, then score them in PHP
        $candidates = TrainingDocChunk::with('doc')
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('content', 'like', "%{$word}%")
                      ->orWhere('heading_path', 'like', "%{$word}%");
                }
            })
            ->limit(50)
            ->get();

        // Score by keyword match count — more matching keywords = higher relevance
        $scored = $candidates->map(function ($chunk) use ($words) {
            $text = mb_strtolower(($chunk->heading_path ?? '') . ' ' . $chunk->content);
            $matchCount = 0;
            foreach ($words as $word) {
                if (str_contains($text, $word)) $matchCount++;
            }
            // Heading matches are worth more
            $headingText = mb_strtolower($chunk->heading_path ?? '');
            $headingMatches = 0;
            foreach ($words as $word) {
                if (str_contains($headingText, $word)) $headingMatches++;
            }
            $score = $matchCount + ($headingMatches * 2);
            return ['chunk' => $chunk, 'score' => $score, 'match_count' => $matchCount];
        });

        // Require at least 1 meaningful keyword match (not just stop words)
        // Filter out very low relevance chunks
        $minMatches = count($words) >= 3 ? 2 : 1;
        $filtered = $scored->filter(fn ($item) => $item['match_count'] >= $minMatches);

        return $filtered->sortByDesc('score')->take($limit)->pluck('chunk');
    }

    /**
     * Degraded-mode search: keyword scoring across BOTH the knowledge base and
     * the training guides, used when no query embedding is available.
     *
     * Training chunks keep their canonical-answer boost, mirroring the hybrid
     * path, so the hand-written user-facing guides still win ties.
     */
    private function buildKeywordResults(string $query, int $limit): array
    {
        $words = $this->meaningfulWords($query);
        if (empty($words)) {
            return ['context' => '', 'sources' => []];
        }

        // Score BOTH pools on the same scale and merge by relevance. Simply
        // concatenating training-first buried the legislation and company
        // documents under generic training guides — a question about the
        // Property Practitioners Act would fill its whole budget with
        // "Agency Admin Daily Operations".
        $training = $this->keywordMatchTrainingChunks($query, $limit * 3)
            ->map(fn ($c) => [
                'chunk'       => $c,
                'is_training' => true,
                // ×1.2, matching the canonical-answer boost the hybrid path uses.
                'score'       => $this->keywordScore($c->heading_path ?? '', $c->content ?? '', $words, $c->doc->title ?? '') * 1.2,
            ]);

        $knowledge = $this->keywordMatchKnowledgeChunks($query, $limit * 3)
            ->map(fn ($c) => [
                'chunk'       => $c,
                'is_training' => false,
                'score'       => $this->keywordScore($c->section_title ?? '', $c->content ?? '', $words, $c->document->title ?? ''),
            ]);

        $items = $training->concat($knowledge)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($items->isEmpty()) {
            return ['context' => '', 'sources' => []];
        }

        return $this->formatChunks($items);
    }

    /**
     * Keyword-based matching over embedded, ellie-enabled knowledge chunks.
     *
     * Mirrors keywordMatchTrainingChunks(). Scores in PHP after a LIKE-based
     * candidate fetch, weighting section-title hits above body hits.
     */
    private function keywordMatchKnowledgeChunks(string $query, int $limit = 15): \Illuminate\Support\Collection
    {
        $words = $this->meaningfulWords($query);

        if (empty($words)) {
            return collect();
        }

        $numbers = $this->numericTokens($words);
        $terms   = array_values(array_diff($words, $numbers));

        $candidates = KnowledgeChunk::whereHas('document', function ($q) {
                $q->where('is_active', true)
                  ->where('status', 'ready')
                  ->where('is_ellie_enabled', true);
            })
            ->with('document')
            ->where(function ($q) use ($terms, $numbers) {
                // Word terms match anywhere. Numbers are matched against the
                // SECTION TITLE only — a bare "9" appears in almost every body
                // of text and would drown the candidate pool in noise, whereas
                // in a heading it identifies the clause.
                foreach ($terms as $word) {
                    $q->orWhere('content', 'like', "%{$word}%")
                      ->orWhere('section_title', 'like', "%{$word}%");
                }
                foreach ($numbers as $num) {
                    $q->orWhere('section_title', 'like', "%{$num}%");
                }
            })
            ->limit(120)
            ->get();

        $scored = $candidates->map(fn ($chunk) => [
            'chunk' => $chunk,
            'score' => $this->keywordScore(
                (string) ($chunk->section_title ?? ''),
                (string) $chunk->content,
                $words,
                (string) ($chunk->document->title ?? ''),
            ),
        ]);

        return $scored
            ->filter(fn ($item) => $item['score'] > 0.0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('chunk');
    }

    /**
     * Format scored chunks into the context block + source rows Ellie consumes.
     *
     * @param \Illuminate\Support\Collection<int, array{chunk:mixed, is_training:bool}> $items
     */
    private function formatChunks(\Illuminate\Support\Collection $items): array
    {
        $contextParts = [];
        $sources = [];

        foreach ($items as $item) {
            $chunk = $item['chunk'];

            if ($item['is_training']) {
                $doc = $chunk->doc;
                if (!$doc) continue;

                $header = "--- From training guide: {$doc->title}";
                if ($chunk->heading_path) {
                    $header .= " ({$chunk->heading_path})";
                }
                $anchor = $chunk->section_anchor ? "#{$chunk->section_anchor}" : '';
                $header .= " → /corex/training-help/{$doc->slug}{$anchor}";
                $header .= " ---";

                $contextParts[] = $header . "\n" . $chunk->content;

                $sources[] = [
                    'document_id'  => $doc->id,
                    'title'        => $doc->title,
                    'section'      => $chunk->heading_path,
                    'page'         => null,
                    'category'     => 'Training',
                    'url'          => "/corex/training-help/{$doc->slug}{$anchor}",
                    'is_training'  => true,
                ];

                continue;
            }

            $doc = $chunk->document;
            if (!$doc) continue;

            $header = "--- From: {$doc->title}";
            if ($chunk->section_title) {
                $header .= " ({$chunk->section_title})";
            }
            if ($chunk->page_number) {
                $header .= " [Page {$chunk->page_number}]";
            }
            $header .= " ---";

            $contextParts[] = $header . "\n" . $chunk->content;

            $sources[] = [
                'document_id' => $doc->id,
                'title'       => $doc->title,
                'section'     => $chunk->section_title,
                'page'        => $chunk->page_number,
                'category'    => $doc->category->name ?? null,
            ];
        }

        return [
            'context' => implode("\n\n", $contextParts),
            'sources' => $sources,
        ];
    }

    /**
     * Shared keyword relevance: body hits count once, title/heading hits count
     * triple — the section heading is what names the topic.
     *
     * @param array<int, string> $words
     */
    private function keywordScore(string $title, string $body, array $words, string $docTitle = ''): float
    {
        $title    = mb_strtolower(trim($title));
        $body     = mb_strtolower($body);
        $docTitle = mb_strtolower(trim($docTitle));

        $score = 0.0;

        // The DOCUMENT name matters as much as the section heading: "Offer to
        // Purchase clause 9" must prefer chunks OF the Offer to Purchase over a
        // clause 9 in the Dual Mandate. This was scored nowhere before, so the
        // right document lost to whatever happened to repeat the words most.
        if ($docTitle !== '') {
            foreach ($words as $word) {
                if (preg_match('/^\d+(\.\d+)*$/', $word)) {
                    continue;
                }
                if (str_contains($docTitle, $word)) {
                    $score += 4.0;
                }
            }
        }

        // A clause number that OPENS the section heading is a direct hit — the
        // heading "9. OCCUPATION AND POSSESSION" IS clause 9. Weighted far above
        // any word match, because it is the answer rather than a hint at it.
        foreach ($this->numericTokens($words) as $num) {
            if (preg_match('/^\D{0,4}' . preg_quote($num, '/') . '(?:[.\s)]|$)/u', $title)) {
                $score += 12.0;
            } elseif (str_contains($title, $num)) {
                $score += 3.0;
            }
        }

        foreach ($words as $word) {
            if (preg_match('/^\d+(\.\d+)*$/', $word)) {
                continue; // already scored above
            }
            if (str_contains($title, $word)) {
                $score += 3.0;
            } elseif (str_contains($body, $word)) {
                $score += 1.0;
            }
        }

        // Normalise so a long chunk cannot out-score a precise one purely on length.
        return $score / max(count($words), 1);
    }

    /**
     * Meaningful tokens from a query: non-stop-word words of 3+ characters,
     * PLUS clause/section numbers of any length.
     *
     * Numbers used to be dropped outright (the 3-char minimum killed "9", and
     * stripping punctuation turned "2.6" into "26"). For an agency whose most
     * common question is "what does clause 9 of the OTP say", that meant every
     * such search silently degraded to searching for the word "clause" — the
     * model would guess repeatedly and never find the section. Clause numbers
     * are the single most load-bearing token in these questions.
     */
    private function meaningfulWords(string $query): array
    {
        $stopWords = ['what', 'is', 'the', 'of', 'a', 'an', 'in', 'for', 'to', 'how', 'does', 'do', 'whats', 'tell', 'me', 'about', 'can', 'you', 'where', 'when', 'why', 'this', 'that', 'with', 'from', 'not', 'but', 'all', 'are', 'was', 'were', 'been', 'have', 'has', 'had', 'will', 'would', 'could', 'should', 'may', 'might'];

        // Keep dots BETWEEN digits so "2.6" survives; drop other punctuation.
        $clean = preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', mb_strtolower($query));
        $clean = preg_replace('/[^\w\s.]/u', ' ', (string) $clean);

        return array_values(array_filter(
            preg_split('/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY),
            fn ($w) => (preg_match('/^\d+(\.\d+)*$/', $w) === 1)
                    || (strlen($w) >= 3 && !in_array($w, $stopWords, true))
        ));
    }

    /**
     * Numeric tokens ("9", "2.6") from the meaningful-word list.
     *
     * @param array<int, string> $words
     * @return array<int, string>
     */
    private function numericTokens(array $words): array
    {
        return array_values(array_filter($words, fn ($w) => preg_match('/^\d+(\.\d+)*$/', $w) === 1));
    }
}
