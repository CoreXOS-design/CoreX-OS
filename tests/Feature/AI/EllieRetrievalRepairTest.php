<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\AI\KnowledgeSearchService;
use App\Services\AI\NavigationAtlasService;
use App\Services\AI\TourKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression guards for the three retrieval defects that capped Ellie at ~60%
 * useful. Every case here is a REAL question taken from ai_messages, with the
 * answer Ellie actually gave recorded in the assertion comments.
 *
 * Spec: .ai/specs/ellie-v2.md §5.
 */
final class EllieRetrievalRepairTest extends TestCase
{
    use RefreshDatabase;

    // ── §5.1 The navigation gate must not discard confident matches ─────────

    public function test_navigation_matches_are_found_without_navigational_phrasing(): void
    {
        $user = $this->seedUser();
        $nav  = app(NavigationAtlasService::class);

        // Real question. The phrase gate rejected it (no "where do i…"), so the
        // atlas never ran and Ellie replied "I don't have documented steps for
        // manually adding a buyer" — while this match scored 12.
        $question = 'How do i manually add a buyer in my buyer pipeline';

        $this->assertFalse(
            $nav->isNavigationQuery($question),
            'Precondition: this phrasing is NOT navigational — that is the whole point.',
        );

        $matches = $nav->search($question, $user, 3);

        $this->assertNotEmpty($matches, 'A confident destination must survive non-navigational phrasing.');
        $this->assertStringContainsStringIgnoringCase('buyer', $matches[0]['label']);
        $this->assertNotEmpty($matches[0]['url']);
    }

    public function test_weak_matches_are_dropped_by_the_relevance_floor(): void
    {
        $user = $this->seedUser();
        $nav  = app(NavigationAtlasService::class);

        // Removing the gate must not turn the atlas into a firehose. Questions
        // with nothing to do with CoreX must return nothing, rather than the
        // 1-point blurb-word matches that used to trail every result.
        foreach (['tell me a joke', 'what is the capital of France', 'how is my mother doing'] as $offTopic) {
            $this->assertEmpty(
                $nav->search($offTopic, $user, 3),
                "An unrelated question must not surface a destination: {$offTopic}",
            );
        }
    }

    // ── §5.2 Tour scoring must not inject the WRONG walkthrough ────────────

    public function test_wrong_feature_walkthrough_is_not_injected(): void
    {
        $user  = $this->seedUser();
        $tours = app(TourKnowledgeService::class);

        // Real question. Returned the "Document packs" tour — a different
        // feature. A wrong tour is worse than none: the prompt tells the model
        // to follow injected steps exactly, so Ellie describes the wrong thing.
        $matches = $tours->search('step by step how to make a viewing pack', $user, 2);

        $titles = array_map(fn ($m) => mb_strtolower($m['title']), $matches);

        // Returning nothing is the CORRECT outcome here — no viewing-pack tour
        // exists, so Ellie should fall back to the page link rather than
        // describing a different feature.
        $this->assertNotContains('document packs', $titles, 'Viewing packs and document packs are different features.');
    }

    public function test_false_friend_word_match_is_not_injected(): void
    {
        $user  = $this->seedUser();
        $tours = app(TourKnowledgeService::class);

        // Real question. "review" matched "Reviewing & assigning a split pack".
        $matches = $tours->search('Client want to leave me a review where does he do it', $user, 2);

        $titles = array_map(fn ($m) => mb_strtolower($m['title']), $matches);

        $this->assertNotContains(
            'reviewing & assigning a split pack',
            $titles,
            'A "review"/"Reviewing" substring collision must not pass as a how-to match.',
        );
    }

    public function test_compound_words_still_match_when_the_user_splits_them(): void
    {
        $user  = $this->seedUser();
        $tours = app(TourKnowledgeService::class);

        // Users type "whistle blower"; the tour is titled "whistleblower".
        $matches = $tours->search('where can i find the whistle blower system', $user, 2);

        $this->assertNotEmpty($matches, 'A split compound must still find its tour.');
        $this->assertStringContainsStringIgnoringCase('whistleblower', $matches[0]['title']);
    }

    // ── §5.3 / clause numbers — the OTP class of question ──────────────────

    public function test_clause_number_retrieves_the_matching_section(): void
    {
        $this->seedUser();
        $this->seedOtpDocument();

        $results = app(KnowledgeSearchService::class)->search('Offer to Purchase clause 11', 3);

        $this->assertNotEmpty($results['sources']);
        $this->assertSame(
            '11. FIXTURES AND FITTINGS',
            $results['sources'][0]['section'],
            'A clause number must resolve to the section whose heading opens with it.',
        );
    }

    public function test_decimal_clause_numbers_survive_tokenisation(): void
    {
        $this->seedUser();
        $this->seedOtpDocument();

        // "2.6" used to be stripped to "26" by punctuation removal, and a bare
        // "9" was dropped entirely by the 3-character minimum — so every
        // clause-number question silently degraded to searching "clause".
        $results = app(KnowledgeSearchService::class)->search('Offer to Purchase clause 2.1', 3);

        $this->assertNotEmpty($results['sources']);
        $this->assertSame('2.1 BOND FINANCE', $results['sources'][0]['section']);
    }

    public function test_the_named_document_outranks_a_same_numbered_clause_elsewhere(): void
    {
        $this->seedUser();
        $this->seedOtpDocument();
        $this->seedDocument('Dual Mandate', [['11. POPIA', 'Processing of personal information under the mandate.']]);

        $results = app(KnowledgeSearchService::class)->search('Offer to Purchase clause 11', 3);

        $this->assertSame(
            'Offer to Purchase',
            $results['sources'][0]['title'],
            'The document the user named must win over the same clause number elsewhere.',
        );
    }

    public function test_named_document_wins_on_the_embedded_path_too(): void
    {
        $this->seedUser();

        // Both documents have a clause 11, both embedded with the SAME vector —
        // so cosine and the clause-number anchor are identical and the document
        // name is the only thing that can separate them.
        //
        // This is the regression that switching from keyword-only to embedded
        // search introduced: document-title scoring existed on the keyword path
        // but NOT on the hybrid one, so the moment embeddings came back,
        // "Offer to Purchase clause 11" silently started resolving to the
        // Alienation of Land Act's §11 instead. The two paths must agree —
        // which one happens to be active must never change the answer.
        $vector = array_fill(0, 384, 0.05);
        Http::fake(['*/embed' => Http::response(['embeddings' => [$vector], 'dim' => 384], 200)]);

        $this->seedDocument('Offer to Purchase', [
            ['11. FIXTURES AND FITTINGS', 'The Property is sold with all fixtures of a permanent nature.'],
        ], embedding: $vector);

        $this->seedDocument('Alienation of land Act', [
            ['11. (1) When land has been sold', 'When land has been sold in terms of a contract, the seller shall...'],
        ], embedding: $vector);

        $results = app(KnowledgeSearchService::class)->search('Offer to Purchase clause 11', 2);

        $this->assertNotEmpty($results['sources']);
        $this->assertSame('Offer to Purchase', $results['sources'][0]['title']);
    }

    public function test_mismatched_embedding_dimensions_score_zero_not_garbage(): void
    {
        $service = app(\App\Services\AI\EmbeddingService::class);

        // Changing the embedding model changes the vector length, and vectors
        // from two different models are not comparable in ANY dimension. This
        // used to compare the first min(count) components and return a
        // plausible-looking number — the worst possible outcome, because
        // ranking silently becomes noise instead of failing. 0.0 forces the
        // stale rows out of the results and the re-embed to be noticed.
        $this->assertSame(
            0.0,
            $service->cosineSimilarity(array_fill(0, 1536, 0.1), array_fill(0, 384, 0.1)),
        );

        // Same length still scores normally.
        $this->assertGreaterThan(
            0.9,
            $service->cosineSimilarity(array_fill(0, 384, 0.1), array_fill(0, 384, 0.1)),
        );
    }

    public function test_knowledge_base_stays_searchable_without_embeddings(): void
    {
        $this->seedUser();
        $this->seedOtpDocument();

        // Embedding service unreachable -> embed() returns null. This path used
        // to return training-doc matches ONLY, silently discarding every
        // knowledge chunk; in production the (then OpenAI) account hit
        // insufficient_quota and Ellie answered "I don't have that in our
        // company documents" about documents she was holding.
        //
        // Embeddings are self-hosted now, so the outage this guards against is
        // the local service being down rather than a vendor quota — but the
        // failure mode, and the requirement to degrade rather than go blank,
        // are identical.
        Http::fake(['*/embed' => Http::response('service unavailable', 503)]);

        $results = app(KnowledgeSearchService::class)->search('fixtures and fittings', 3);

        $this->assertNotEmpty(
            $results['context'],
            'A dead embedding provider must degrade to keyword search, not empty the knowledge base.',
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function seedOtpDocument(): void
    {
        $this->seedDocument('Offer to Purchase', [
            ['1.   PURCHASE PRICE', 'The Purchase Price is R… payable as set out below.'],
            ['2.1 BOND FINANCE', 'This offer is subject to the Purchaser obtaining a bond.'],
            ['11. FIXTURES AND FITTINGS', 'The Property is sold with all fixtures and fittings of a permanent nature.'],
            ['13. CERTIFICATES OF COMPLIANCE', 'The Seller shall furnish an electrical certificate of compliance.'],
        ]);
    }

    /** @param array<int, array{0:string,1:string}> $sections */
    private function seedDocument(string $title, array $sections, ?User $uploader = null, ?array $embedding = null): void
    {
        $uploader ??= User::query()->first() ?? $this->seedUser();

        $categoryId = DB::table('knowledge_categories')->insertGetId([
            'name' => 'Test Cat ' . Str::random(5),
            'slug' => 'test-cat-' . Str::random(8),
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $doc = KnowledgeDocument::create([
            'title'            => $title,
            'category_id'      => $categoryId,
            'uploaded_by'      => $uploader->id,
            'file_path'        => 'knowledge/' . Str::slug($title) . '.pdf',
            'file_name'        => Str::slug($title) . '.pdf',
            'file_type'        => 'pdf',
            'status'           => 'ready',
            'is_active'        => true,
            'is_ellie_enabled' => true,
        ]);

        foreach ($sections as $i => [$section, $body]) {
            KnowledgeChunk::create([
                'document_id'    => $doc->id,
                'chunk_index'    => $i,
                'section_title'  => $section,
                'content'        => $body,
                'embedding'      => $embedding,
                'has_embedding'  => $embedding !== null,
            ]);
        }
    }

    private function seedUser(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
    }
}
