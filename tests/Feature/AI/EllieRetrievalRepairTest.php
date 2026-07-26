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

    public function test_knowledge_base_stays_searchable_without_embeddings(): void
    {
        $this->seedUser();
        $this->seedOtpDocument();

        // No OPENAI_API_KEY -> embed() returns null. This path used to return
        // training-doc matches ONLY, silently discarding every knowledge chunk;
        // in production the account hit insufficient_quota and Ellie answered
        // "I don't have that in our company documents" about documents she held.
        config(['services.openai.key' => null]);

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
    private function seedDocument(string $title, array $sections, ?User $uploader = null): void
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
                'has_embedding'  => false,
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
