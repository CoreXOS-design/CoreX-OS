<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Reference;

use App\Events\Esign\TemplatePublished;
use App\Models\Docuperfect\CompiledTemplate;
use Database\Seeders\DataDictionarySeeder;
use Database\Seeders\ReferencePackDictionarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * E-Sign Document Compiler — WS5 (reference proofs) — persistence gate.
 *
 * The one DB-backed WS5 test: `esign:publish-reference-pack` freezes 116/117/119 as immutable,
 * content-hashed, versioned CoreX-standard `compiled_templates` rows through the REAL publish
 * path, and re-running is idempotent. (The full-chain lint/certify/side-by-side proofs are the
 * separate pure suite `ReferencePackProofTest`.)
 */
final class ReferencePackPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The dictionary the reference bindings resolve against: WS0 standard + the 6 WS5 entries.
        $this->seed(DataDictionarySeeder::class);
        $this->seed(ReferencePackDictionarySeeder::class);
    }

    public function test_publishes_all_three_references_as_immutable_standard_versions(): void
    {
        Event::fake([TemplatePublished::class]);

        $this->artisan('esign:publish-reference-pack')->assertSuccessful();

        foreach (['116', '117', '119'] as $family) {
            $row = CompiledTemplate::query()->standard()->published()->family($family)->first();
            $this->assertNotNull($row, "Template {$family} should be published.");
            $this->assertNull($row->agency_id, 'Reference pack is CoreX-standard (agency_id NULL).');
            $this->assertSame(1, $row->version);
            $this->assertNotSame('', (string) $row->content_hash);
            $this->assertSame(CompiledTemplate::LINT_PASSED, $row->lint_status);
            $this->assertIsArray($row->lint_report);
            $this->assertTrue((bool) ($row->lint_report['publishable'] ?? false));
        }

        // The publish event fired for each (the integration moat).
        Event::assertDispatchedTimes(TemplatePublished::class, 3);
    }

    public function test_116_rebuilds_its_fifteen_field_bindings(): void
    {
        Event::fake([TemplatePublished::class]);
        $this->artisan('esign:publish-reference-pack')->assertSuccessful();

        $t116 = CompiledTemplate::query()->standard()->published()->family('116')->firstOrFail();
        $this->assertSame(15, $t116->fieldBindings()->count(), '116 has 15 fill-points → 15 derived bindings.');

        // Zero-field references produce zero bindings.
        $t117 = CompiledTemplate::query()->standard()->published()->family('117')->firstOrFail();
        $this->assertSame(0, $t117->fieldBindings()->count());
    }

    public function test_published_reference_is_immutable(): void
    {
        Event::fake([TemplatePublished::class]);
        $this->artisan('esign:publish-reference-pack')->assertSuccessful();

        $row = CompiledTemplate::query()->standard()->published()->family('116')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $row->structure = ['tampered' => true];
        $row->save(); // the model boot guard forbids mutating a published row (§5).
    }

    public function test_republish_is_idempotent(): void
    {
        Event::fake([TemplatePublished::class]);

        $this->artisan('esign:publish-reference-pack')->assertSuccessful();
        $countAfterFirst = CompiledTemplate::query()->standard()->published()->count();

        // Second run: identical content_hash already published → nothing new.
        $this->artisan('esign:publish-reference-pack')->assertSuccessful();
        $countAfterSecond = CompiledTemplate::query()->standard()->published()->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Re-running must not create duplicate versions.');
        $this->assertSame(3, $countAfterSecond);
    }
}
