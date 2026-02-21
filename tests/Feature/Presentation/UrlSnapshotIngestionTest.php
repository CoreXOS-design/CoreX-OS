<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\PresentationUrlSnapshot;
use App\Models\User;
use App\Services\Presentations\Evidence\UrlIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests P6/P7 URL ingestion: storeUrlSnapshot endpoint + UrlIngestionService.
 */
class UrlSnapshotIngestionTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name'     => 'Test Branch',
            'code'     => 'TST',
            'is_active'=> true,
        ]);

        $this->user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Ingestion Test',
            'property_address'   => '1 Test St',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    // ── UrlIngestionService unit-style tests (in-process, no HTTP) ─────────────

    private function makeSnapshot(string $html, string $sourceType = 'p24_search'): PresentationUrlSnapshot
    {
        return PresentationUrlSnapshot::create([
            'presentation_id' => $this->presentation->id,
            'url'             => 'https://example.com/search',
            'snapshot_html'   => $html,
            'source_type'     => $sourceType,
            'http_status'     => 200,
            'content_hash'    => hash('sha256', $html),
            'fetched_at'      => now(),
        ]);
    }

    public function test_ingestion_returns_skipped_for_empty_html(): void
    {
        $snapshot = $this->makeSnapshot('');
        $result   = (new UrlIngestionService())->ingest($this->presentation->id, $snapshot->id);

        $this->assertTrue($result['skipped']);
        $this->assertSame('empty_html', $result['skip_reason']);
    }

    public function test_ingestion_returns_skipped_for_unknown_source_type(): void
    {
        $snapshot = $this->makeSnapshot('<html></html>', 'other');
        $result   = (new UrlIngestionService())->ingest($this->presentation->id, $snapshot->id);

        $this->assertTrue($result['skipped']);
        $this->assertSame('no_parser_for_source_type', $result['skip_reason']);
    }

    public function test_ingestion_persists_rows_from_json_ld(): void
    {
        $data = [
            '@type'  => 'Product',
            'offers' => ['price' => 2_500_000],
            'numberOfRooms' => 3,
        ];
        $html = '<html><head><script type="application/ld+json">' . json_encode($data) . '</script></head><body></body></html>';

        $snapshot = $this->makeSnapshot($html, 'p24_search');
        $result   = (new UrlIngestionService())->ingest($this->presentation->id, $snapshot->id);

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['rows_extracted']);
        $this->assertSame(1, $result['rows_persisted']);
        $this->assertSame('deterministic_v1', $result['extraction_method']);

        $listing = PresentationActiveListing::where('presentation_id', $this->presentation->id)->first();
        $this->assertNotNull($listing);
        $this->assertSame(2_500_000, $listing->list_price_inc);
        $this->assertSame($snapshot->id, $listing->source_snapshot_id);
    }

    public function test_persisted_row_has_correct_parser_version(): void
    {
        $data = ['@type' => 'House', 'offers' => ['price' => 1_800_000]];
        $html = '<html><head><script type="application/ld+json">' . json_encode($data) . '</script></head><body></body></html>';

        $snapshot = $this->makeSnapshot($html, 'p24_search');
        (new UrlIngestionService())->ingest($this->presentation->id, $snapshot->id);

        $listing = PresentationActiveListing::where('presentation_id', $this->presentation->id)->first();
        $this->assertSame('p24_search_v1', $listing->parser_version);
    }

    public function test_private_property_source_type_dispatches_correctly(): void
    {
        $data = ['offers' => ['price' => 1_500_000], 'numberOfRooms' => 2];
        $html = '<html><head><script type="application/ld+json">' . json_encode($data) . '</script></head><body></body></html>';

        $snapshot = $this->makeSnapshot($html, 'private_property_search');
        $result   = (new UrlIngestionService())->ingest($this->presentation->id, $snapshot->id);

        $this->assertSame(1, $result['rows_extracted']);
    }

    // ── HTTP endpoint test ────────────────────────────────────────────────────

    public function test_store_url_snapshot_endpoint_requires_auth(): void
    {
        $response = $this->postJson(
            route('presentations.url-snapshots.store', $this->presentation),
            ['url' => 'https://example.com', 'source_type' => 'other'],
        );
        $response->assertUnauthorized();
    }

    public function test_store_url_snapshot_validates_source_type(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.url-snapshots.store', $this->presentation),
            ['url' => 'https://example.com', 'source_type' => 'invalid_type'],
        );
        $response->assertUnprocessable();
    }
}
