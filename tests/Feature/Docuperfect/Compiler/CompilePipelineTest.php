<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler;

use App\Models\Docuperfect\CompiledTemplate;
use App\Services\Docuperfect\Compiler\Ingest\DeterministicSegmenter;
use App\Services\Docuperfect\Compiler\Ingest\HtmlIngestor;
use App\Services\Docuperfect\Compiler\Pipeline\CompileDraftService;
use App\Services\Docuperfect\Compiler\Pipeline\CompileGatePipeline;
use App\Services\Docuperfect\Compiler\Pipeline\HeuristicBindingSuggester;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use Database\Seeders\DataDictionarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WS4-E Gates 4–5 — the draft service, binding suggester, compile pipeline, AND the exit proof:
 * a raw document ingested → segmented → bound → declared → PUBLISHED as a linted, certified,
 * immutable hashed CDS (spec §3 + §10 WS4 gate). Seeds the CoreX SA dictionary so bindings
 * resolve.
 */
final class CompilePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataDictionarySeeder::class);
    }

    private function draftFrom(array $structure): CompiledTemplate
    {
        return (new CompileDraftService())->createFromSegmentation(new SegmentationResult($structure));
    }

    // ── draft service ─────────────────────────────────────────────────────────

    public function test_draft_service_creates_and_mutates_a_draft(): void
    {
        $service = new CompileDraftService();
        $draft = $service->createFromSegmentation(new SegmentationResult([
            'family' => 'test', 'legal_class' => 'general', 'delivery_modes' => ['web_esign'],
            'parties' => [], 'blocks' => [
                ['block_id' => 'fg', 'type' => 'field_group', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'],
                    'fields' => [['field_id' => 'f1', 'label' => 'Price', 'binding' => '', 'source' => 'agent_input', 'required' => true]]],
            ],
        ]));

        $this->assertSame(CompiledTemplate::STATUS_DRAFT, $draft->status);

        $service->bindField($draft, 'fg', 'f1', 'purchase_price');
        $service->declareParty($draft, ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 1]);
        $service->setBlockVisibility($draft, 'fg', ['mode' => 'only', 'party_keys' => ['agent']]);

        $fresh = $draft->fresh();
        $this->assertSame('purchase_price', $fresh->structure['blocks'][0]['fields'][0]['binding']);
        $this->assertSame('agent', $fresh->structure['parties'][0]['key']);
        $this->assertSame('only', $fresh->structure['blocks'][0]['visibility']['mode']);
    }

    public function test_draft_mutation_is_blocked_once_published(): void
    {
        $draft = $this->draftFrom(ReferencePackCds::template119());
        (new CompileGatePipeline())->publish($draft);

        $this->expectException(RuntimeException::class);
        (new CompileDraftService())->declareParty($draft->fresh(), ['key' => 'x', 'role' => 'X']);
    }

    // ── binding suggester (DB) ──────────────────────────────────────────────────

    public function test_binding_suggester_ranks_the_right_dictionary_entry_first(): void
    {
        $suggestions = (new HeuristicBindingSuggester())->suggest('Purchase Price', 'the agreed sum of R');

        $this->assertNotEmpty($suggestions);
        $this->assertSame('purchase_price', $suggestions[0]->dictionaryKey);
    }

    // ── compile pipeline ────────────────────────────────────────────────────────

    public function test_pipeline_publishes_a_reference_draft_with_hash_and_parity(): void
    {
        $draft = $this->draftFrom(ReferencePackCds::template117());

        $this->assertTrue((new CompileGatePipeline())->lint($draft)->publishable());

        $published = (new CompileGatePipeline())->publish($draft);

        $this->assertSame(CompiledTemplate::STATUS_PUBLISHED, $published->status);
        $this->assertSame(1, $published->version);
        $this->assertNotNull($published->content_hash);
        $this->assertNotNull($published->render_parity['web_hash'] ?? null);
        $this->assertSame(CompiledTemplate::LINT_PASSED, $published->lint_status);
        $this->assertNotEmpty($published->lint_report);
    }

    public function test_pipeline_refuses_to_publish_a_draft_with_an_unbound_field(): void
    {
        $structure = ReferencePackCds::template119();
        // Inject an UNBOUND field — L1 must block publish.
        $structure['blocks'][] = ['block_id' => 'fg', 'type' => 'field_group', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'only', 'party_keys' => ['agent']], 'condition' => ['kind' => 'always'],
            'fields' => [['field_id' => 'f1', 'label' => 'Something', 'binding' => '', 'source' => 'agent_input', 'required' => true]]];
        $draft = $this->draftFrom($structure);

        $this->assertFalse((new CompileGatePipeline())->lint($draft)->publishable());
        $this->expectException(RuntimeException::class);
        (new CompileGatePipeline())->publish($draft);
    }

    // ── EXIT PROOF (spec §10 WS4 gate) ──────────────────────────────────────────

    public function test_exit_proof_raw_document_ingested_bound_and_published(): void
    {
        // A neutral document (no CDS markers): letterhead + prose + a fill-point + signatures.
        $html = '<html><body>'
            . '<header>HOME FINDERS COASTAL</header>'
            . '<h1>Mandatory Disclosure</h1>'
            . '<p>1 The seller discloses the condition of the property.</p>'
            . '<p>Purchase Price: ____________</p>'
            . '<p>Thus done and signed by the Seller.</p>'
            . '<p>Signature of the Agent.</p>'
            . '</body></html>';
        $path = tempnam(sys_get_temp_dir(), 'ws4e') . '.html';
        file_put_contents($path, $html);

        try {
            // Ingest → segment → seed a draft.
            $ingested = (new HtmlIngestor())->ingest($path, 'mdf.html', ['family' => 'mdf-proof']);
            $segmented = (new DeterministicSegmenter())->segment($ingested);
            $draftService = new CompileDraftService();
            $draft = $draftService->createFromSegmentation($segmented, ['family' => 'mdf-proof']);

            // The operator confirms: bind the detected fill-point to a dictionary entry.
            $fieldBlock = null;
            foreach ($draft->structure['blocks'] as $block) {
                if (($block['type'] ?? '') === 'field_group' && ! empty($block['fields'])) {
                    $fieldBlock = $block;
                    break;
                }
            }
            $this->assertNotNull($fieldBlock, 'segmentation produced a fill-point to bind');
            $field = $fieldBlock['fields'][0];
            $suggestions = (new HeuristicBindingSuggester())->suggest($field['label']);
            $this->assertNotEmpty($suggestions, "suggester ranked a binding for label '{$field['label']}'");
            $draftService->bindField($draft, $fieldBlock['block_id'], $field['field_id'], $suggestions[0]->dictionaryKey);

            // Gate: the compiled draft lints publishable and certifies, then publishes.
            $published = (new CompileGatePipeline())->publish($draft->fresh());

            $this->assertSame(CompiledTemplate::STATUS_PUBLISHED, $published->status);
            $this->assertNotNull($published->content_hash);
            // Parties + signature surfaces were reconstructed from the raw document.
            $partyKeys = array_column($published->structure['parties'], 'key');
            $this->assertContains('seller', $partyKeys);
            $this->assertContains('agent', $partyKeys);
        } finally {
            @unlink($path);
        }
    }
}
