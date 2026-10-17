<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AT-456 finding — AnthropicGateway::generateStructured() rebuilt the NarrativeRequest by hand and dropped
 * `documents`, so a DocuPerfect PDF import never sent the PDF to the AI. The copy now goes through
 * NarrativeRequest::withSystemPrompt(), which carries every constructor field.
 */
final class GenerateStructuredCarriesEveryFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
    }

    public function test_generate_structured_sends_the_pdf_as_a_document_block_and_keeps_source_and_system_flags(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"fields":{}}']],
            'usage'   => ['input_tokens' => 1200, 'output_tokens' => 40],
        ], 200)]);

        $pdfB64 = base64_encode('%PDF-1.4 fake');
        $request = new NarrativeRequest(
            narrativeType: 'esign_import_pdf',
            cacheKey: 'esign_import_pdf:test-' . uniqid(),
            modelAlias: 'quality',
            systemPrompt: 'Find the blanks.',
            userPrompt: 'Analyse the attached PDF.',
            inputData: ['k' => 'v'],
            forceRefresh: true,
            documents: [['type' => 'pdf', 'media_type' => 'application/pdf', 'data' => $pdfB64]],
            source: AiUsageEvent::SOURCE_DOCUPERFECT_VISION,
            timeoutSeconds: 90,
            system: true,
        );

        $response = app(AnthropicGateway::class)->generateStructured($request, ['fields' => 'object']);

        $this->assertSame(['fields' => []], $response->outputJson);

        Http::assertSent(function (Request $r) use ($pdfB64) {
            $content = $r['messages'][0]['content'] ?? null;

            return is_array($content)
                && ($content[0]['type'] ?? null) === 'document'
                && ($content[0]['source']['media_type'] ?? null) === 'application/pdf'
                && ($content[0]['source']['data'] ?? null) === $pdfB64
                && ($content[1]['type'] ?? null) === 'text'
                && str_contains((string) $r['system'], 'Find the blanks.')
                && str_contains((string) $r['system'], 'Respond ONLY with valid JSON');
        });

        // The ledger row is filed under the request's own source and as a platform (system) call.
        $row = AiUsageEvent::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(AiUsageEvent::SOURCE_DOCUPERFECT_VISION, $row->source);
        $this->assertNull($row->agency_id);
    }

    public function test_with_system_prompt_copies_every_constructor_field(): void
    {
        $original = new NarrativeRequest(
            narrativeType: 't', cacheKey: 'k', modelAlias: 'fast', systemPrompt: 'old', userPrompt: 'u',
            inputData: ['a' => 1], maxTokens: 321, temperature: 0.2, cacheTtlMinutes: 7, agencyId: 5,
            fallbackData: ['text' => 'fb'], forceRefresh: true, promptVersion: 'v9',
            documents: [['type' => 'image', 'media_type' => 'image/png', 'data' => 'abc']],
            source: AiUsageEvent::SOURCE_AD_TEMPLATE, timeoutSeconds: 99, system: true,
        );

        $copy = $original->withSystemPrompt('new');

        $this->assertNotSame($original, $copy);
        $this->assertSame('new', $copy->systemPrompt);
        $this->assertSame('old', $original->systemPrompt);

        // Reflection over the constructor: a field added later is covered without editing this test.
        foreach ((new \ReflectionClass(NarrativeRequest::class))->getConstructor()->getParameters() as $param) {
            $name = $param->getName();
            if ($name === 'systemPrompt') {
                continue;
            }
            $this->assertSame($original->{$name}, $copy->{$name}, "NarrativeRequest::withSystemPrompt() dropped '{$name}'");
        }
    }
}
