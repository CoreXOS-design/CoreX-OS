<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Services\Docuperfect\DocumentTemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionClass;
use Tests\TestCase;

/**
 * A generated template must NEVER silently lose a blank.
 *
 * processTagSpans() dispatched on `$tag['type']` and `return ''`d on both the orphan path
 * and the unknown-type path. `return ''` does not skip a tag — it ERASES THE BLANK FROM THE
 * DOCUMENT. The import then completes, reports success, and produces a legal template whose
 * fill-in lines have quietly vanished: the EATS came out reading "I / We  the undersigned"
 * with nothing to sign into, and nothing anywhere said so.
 *
 * That is the exact failure this ticket exists to prevent: a document that looks complete
 * and is not. An import that fails loudly is strictly better.
 *
 * Prevent-or-absorb (BUILD_STANDARD §3): a tag we cannot understand is absorbed as a manual
 * field the agent can still fill, and logged. Content is never destroyed.
 */
final class GeneratorNeverDeletesBlanksTest extends TestCase
{
    use RefreshDatabase;

    private function process(string $html, array $tags, array $mappings): string
    {
        $gen = app(DocumentTemplateGenerator::class);
        $m = (new ReflectionClass($gen))->getMethod('processTagSpans');
        $m->setAccessible(true);

        // Same named-field map generate() builds from the mappings.
        $ids = collect($mappings)->pluck('namedFieldId')->filter()->all();
        $namedFieldMap = $ids === []
            ? new Collection()
            : \App\Models\Docuperfect\NamedField::whereIn('id', $ids)->get()->keyBy('id');

        return $m->invoke(
            $gen,
            $html,
            collect($tags)->keyBy('id')->all(),
            $mappings,
            $namedFieldMap,
            new Collection(),
        );
    }

    /** THE BUG: a tag with no `type` erased the blank. It must survive instead. */
    public function test_a_tag_with_no_type_keeps_its_blank(): void
    {
        $html = '<p>I / We <span data-tag-id="t0">[1]</span> the undersigned</p>';

        $out = $this->process($html, [['id' => 't0', 'number' => 1]], []);

        $this->assertStringContainsString('class="field', $out, 'the blank must still be there');
        $this->assertStringNotContainsString('data-tag-id', $out, 'the raw tag should be rendered, not left');
        $this->assertStringContainsString('I / We', $out);
        $this->assertStringContainsString('the undersigned', $out);
    }

    /** An unknown tag type is absorbed as a manual field — never dropped. */
    public function test_an_unknown_tag_type_keeps_its_blank(): void
    {
        $html = '<p>Price: R<span data-tag-id="t9">[9]</span></p>';

        $out = $this->process($html, [['id' => 't9', 'number' => 9, 'type' => 'wat']], []);

        $this->assertStringContainsString('field-manual', $out);
        $this->assertStringContainsString('Price: R', $out);
    }

    /** An orphan tag span (no matching tag record) is absorbed too. */
    public function test_an_orphan_tag_span_keeps_its_blank(): void
    {
        $html = '<p>Erf <span data-tag-id="ghost">[4]</span> Portion</p>';

        $out = $this->process($html, [], []);

        $this->assertStringContainsString('field-manual', $out);
        $this->assertStringContainsString('Erf', $out);
        $this->assertStringContainsString('Portion', $out);
    }

    /**
     * The whole-document guarantee, and the one that would have caught the real defect:
     * every blank that went in comes out as a field. None may disappear.
     */
    public function test_every_blank_survives_a_full_document(): void
    {
        $tags = [];
        $html = '<div>';
        for ($i = 0; $i < 39; $i++) {                       // the EATS' real blank count
            $tags[] = ['id' => 't' . $i, 'number' => $i + 1];  // deliberately typeless
            $html .= '<p>Line ' . $i . ': <span data-tag-id="t' . $i . '">[' . ($i + 1) . ']</span></p>';
        }
        $html .= '</div>';

        $out = $this->process($html, $tags, []);

        $this->assertSame(
            39,
            substr_count($out, 'class="field'),
            'all 39 blanks must survive — silently dropping them is how a template loses its fill-in lines'
        );
        $this->assertSame(0, substr_count($out, 'data-tag-id'), 'no tag left unrendered');
    }

    /** A normal typed input tag still renders exactly as before. */
    public function test_a_typed_input_tag_still_renders(): void
    {
        $html = '<p>Name: <span data-tag-id="t0">[1]</span></p>';

        $out = $this->process($html, [['id' => 't0', 'number' => 1, 'type' => 'input']], []);

        $this->assertStringContainsString('class="field', $out);
    }

    /**
     * The chain that Phase 1 rides on, end to end:
     *   tag  →  processTagSpans writes data-field + data-contact-type
     *        →  RoleBlockNormalizer stamps the data-role-block contract.
     *
     * The importer never ran that last step — only the CDS *builder* path normalised at
     * publish — so every imported document went out WITHOUT the contract and rendered
     * through legacy clustering forever after. (Which is why the backfill found nothing to
     * do: there was never anything to find.) The generator now stamps at birth.
     */
    public function test_the_generators_output_stamps_the_role_block_contract(): void
    {
        $nf = \App\Models\Docuperfect\NamedField::create([
            'name' => 'Seller Full Name',
            'source_type' => 'contact',
            'source_column' => 'first_name+last_name',
            'source_contact_type' => 'Seller',
        ]);

        $html = '<p>I / We <span data-tag-id="t0">[1]</span> the undersigned</p>';

        $processed = $this->process(
            $html,
            [['id' => 't0', 'number' => 1, 'type' => 'input']],
            ['t0' => ['mappingType' => 'named_field', 'namedFieldId' => $nf->id]],
        );

        // What the generator writes: the pillar name, with the party alongside it.
        $this->assertStringContainsString('data-field="contact.first_name+last_name"', $processed);
        $this->assertStringContainsString('data-contact-type="Seller"', $processed);

        // What the renderer needs: the contract.
        $stamped = app(\App\Services\Docuperfect\RoleBlockNormalizer::class)->normalize($processed);

        $this->assertStringContainsString('data-role-block="seller"', $stamped);
    }
}
