<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AI\EllieReferenceChunk;
use App\Models\AI\EllieReferenceSource;
use App\Models\User;
use App\Services\AI\Ellie\EllieToolkit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * search_reference_sites — the ONLY thing that ever lets Ellie see content
 * from an approved external page, and it only ever reads already-indexed
 * chunks. No test here should ever cause a real network fetch; that's a
 * different class (EllieReferenceSourceFetchServiceTest) entirely.
 *
 * Spec: .ai/specs/ellie-reference-sources.md §7, §11.
 */
final class EllieReferenceSourceSearchToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_query_is_rejected(): void
    {
        $result = $this->callTool('search_reference_sites', ['query' => '']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_no_active_sources_returns_no_results(): void
    {
        $result = $this->callTool('search_reference_sites', ['query' => 'prime interest rate']);

        $this->assertSame('no results', $result['result'] ?? null);
    }

    public function test_a_matching_chunk_on_an_active_source_is_found_and_cites_the_url(): void
    {
        $source = $this->makeSource('https://bank.example.test/rates', 'Bank Example Prime Rate');
        $this->makeChunk($source, 'The current prime lending rate is 11.75 percent as of this month.');

        $result = $this->callTool('search_reference_sites', ['query' => 'prime lending rate']);

        $this->assertArrayHasKey('excerpts', $result);
        $this->assertStringContainsString('11.75', $result['excerpts']);
        $this->assertStringContainsString('https://bank.example.test/rates', $result['excerpts']);
        $this->assertSame('https://bank.example.test/rates', $result['sources'][0]['url'] ?? null);
    }

    public function test_a_disabled_source_is_excluded_immediately(): void
    {
        $source = $this->makeSource('https://bank.example.test/rates', 'Bank Example Prime Rate', isActive: false);
        $this->makeChunk($source, 'The current prime lending rate is 11.75 percent as of this month.');

        $result = $this->callTool('search_reference_sites', ['query' => 'prime lending rate']);

        $this->assertSame('no results', $result['result'] ?? null);
    }

    public function test_a_soft_deleted_source_is_excluded(): void
    {
        $source = $this->makeSource('https://bank.example.test/rates', 'Bank Example Prime Rate');
        $this->makeChunk($source, 'The current prime lending rate is 11.75 percent as of this month.');
        $source->delete();

        $result = $this->callTool('search_reference_sites', ['query' => 'prime lending rate']);

        $this->assertSame('no results', $result['result'] ?? null);
    }

    public function test_an_unrelated_query_does_not_match(): void
    {
        $source = $this->makeSource('https://bank.example.test/rates', 'Bank Example Prime Rate');
        $this->makeChunk($source, 'The current prime lending rate is 11.75 percent as of this month.');

        $result = $this->callTool('search_reference_sites', ['query' => 'FICA verification requirements']);

        $this->assertSame('no results', $result['result'] ?? null);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function callTool(string $tool, array $input): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'role' => 'agent']);

        return json_decode(app(EllieToolkit::class)->execute($tool, $input, $user), true);
    }

    private function makeSource(string $url, string $title, bool $isActive = true): EllieReferenceSource
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create(['agency_id' => $agencyId, 'role' => 'super_admin']);

        return EllieReferenceSource::create([
            'url' => $url,
            'title' => $title,
            'added_by_user_id' => $admin->id,
            'is_active' => $isActive,
            'last_fetch_status' => EllieReferenceSource::STATUS_OK,
        ]);
    }

    /**
     * Chunk seeded WITHOUT an embedding — exercises the keyword-fallback path
     * deterministically, with no dependency on the embedding service being up.
     */
    private function makeChunk(EllieReferenceSource $source, string $content): EllieReferenceChunk
    {
        return EllieReferenceChunk::create([
            'source_id' => $source->id,
            'chunk_index' => 0,
            'content' => $content,
            'has_embedding' => false,
        ]);
    }
}
