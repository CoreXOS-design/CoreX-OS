<?php

namespace Tests\Unit;

use App\Http\Controllers\Docuperfect\TemplateController;
use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsReimportService;
use Tests\TestCase;

/**
 * Re-import from Word — the matching rules, DB-free. Spec: .ai/specs/cds-reimport-from-word.md.
 * The point of the feature: fields are matched BY NAME, so a paragraph added above them never moves
 * a binding onto the wrong field, and the report lists exactly what was dropped and what is new.
 */
class CdsReimportMatchTest extends TestCase
{
    private function svc(): CdsReimportService
    {
        return new CdsReimportService(new CdsParserService());
    }

    /** A custom-named marker field, the importer's ~~~~Seller - Full name~~~~ form. */
    private function marker(string $blockId, string $label): array
    {
        return ['type' => 'insertable_block_placeholder', 'purpose' => 'custom_named', 'block_id' => $blockId, 'custom_label' => $label];
    }

    /** An @@@@ field the importer identified from its context. */
    private function field(string $name, string $label = ''): array
    {
        return ['type' => 'field_placeholder', 'field_name' => $name, 'label' => $label];
    }

    private function cds(array ...$sections): array
    {
        return ['sections' => array_map(fn ($content) => ['type' => 'paragraph', 'content' => $content], $sections)];
    }

    /** Old template state: one tag per field, bound to a distinct, recognisable label. */
    private function state(array $cds): array
    {
        $tags = [];
        $mappings = [];
        foreach ($this->svc()->fieldList($cds) as $f) {
            $id = 'tag-' . $f['index'];
            $tags[] = ['id' => $id, 'type' => 'input', 'number' => $f['index'] + 1, 'parserIndex' => $f['index']];
            $mappings[$id] = ['mappingType' => 'named_field', 'typeKey' => 'sf:x' . $f['index'], 'label' => 'BOUND-' . ($f['key'] ?? 'none'), 'party' => 'owner_party', 'editable_by' => ['agent']];
        }

        return [$tags, $mappings];
    }

    public function test_a_shifted_paragraph_does_not_shift_bindings(): void
    {
        $old = $this->cds(
            [$this->marker('seller_full_name', 'Seller - Full name')],
            [$this->marker('seller_physical_address', 'Seller - Physical address')],
            [$this->marker('property_erf', 'Property - Erf')],
        );
        // The corrected Word file gains a paragraph and a new field ABOVE everything.
        $new = $this->cds(
            [$this->marker('seller_email', 'Seller - Email')],
            [$this->marker('seller_full_name', 'Seller - Full name')],
            [$this->marker('seller_physical_address', 'Seller - Physical address')],
            [$this->marker('property_erf', 'Property - Erf')],
        );
        [$tags, $mappings] = $this->state($old);

        $r = $this->svc()->carryOver($old, $tags, $mappings, $new);

        $this->assertSame('BOUND-seller_full_name', $r['bindings'][1]['label']);
        $this->assertSame('BOUND-seller_physical_address', $r['bindings'][2]['label']);
        $this->assertSame('BOUND-property_erf', $r['bindings'][3]['label']);
        $this->assertArrayNotHasKey(0, $r['bindings'], 'the new field must not inherit position 0\'s old binding');
        $this->assertSame(3, $r['report']['carried_count']);
        $this->assertSame([], $r['report']['dropped']);
        $this->assertSame(['seller_email'], array_column($r['report']['added'], 'name'));
        $this->assertSame(0, $r['report']['added'][0]['new_index'], 'the report links to the field by its new position');
    }

    public function test_the_report_lists_exactly_the_dropped_and_the_new_fields(): void
    {
        $old = $this->cds([$this->marker('a', 'A'), $this->marker('b', 'B'), $this->marker('c', 'C')]);
        $new = $this->cds([$this->marker('a', 'A'), $this->marker('c', 'C'), $this->marker('d', 'D'), $this->marker('e', 'E')]);
        [$tags, $mappings] = $this->state($old);

        $r = $this->svc()->carryOver($old, $tags, $mappings, $new)['report'];

        $this->assertSame(['b'], array_column($r['dropped'], 'name'));
        $this->assertSame('BOUND-b', $r['dropped'][0]['was_bound_to']);
        $this->assertSame(['d', 'e'], array_column($r['added'], 'name'));
        $this->assertSame([2, 3], array_column($r['added'], 'new_index'));
        $this->assertSame(2, $r['matched_count']);
        $this->assertSame(3, $r['old_count']);
        $this->assertSame(4, $r['new_count']);
    }

    public function test_a_field_with_no_name_is_never_matched_by_position(): void
    {
        $old = $this->cds([$this->field(''), $this->marker('a', 'A')]);
        $new = $this->cds([$this->marker('a', 'A'), $this->field('')]);
        [$tags, $mappings] = $this->state($old);

        $r = $this->svc()->carryOver($old, $tags, $mappings, $new);

        $this->assertCount(1, $r['bindings']);
        $this->assertSame('BOUND-a', $r['bindings'][0]['label'], 'marker "a" moved from position 1 to 0 and kept its binding');
        $this->assertCount(1, $r['report']['dropped']);
        $this->assertCount(1, $r['report']['added']);
        $this->assertStringContainsString('no name', $r['report']['dropped'][0]['reason']);
        $this->assertStringContainsString('no name', $r['report']['added'][0]['reason']);
    }

    public function test_a_repeated_name_pairs_in_order_when_used_the_same_number_of_times(): void
    {
        $old = $this->cds([$this->field('contact.date', 'Date'), $this->field('x.y', 'Y'), $this->field('contact.date', 'Date')]);
        $new = $this->cds([$this->field('contact.date', 'Date'), $this->field('contact.date', 'Date'), $this->field('x.y', 'Y')]);
        [$tags, $mappings] = $this->state($old);
        // Give the two "date" fields different bindings so a wrong pairing would show.
        $mappings['tag-0']['label'] = 'FIRST-DATE';
        $mappings['tag-2']['label'] = 'SECOND-DATE';

        $r = $this->svc()->carryOver($old, $tags, $mappings, $new);

        $this->assertSame('FIRST-DATE', $r['bindings'][0]['label']);
        $this->assertSame('SECOND-DATE', $r['bindings'][1]['label']);
        $this->assertSame('BOUND-x.y', $r['bindings'][2]['label']);
    }

    public function test_a_repeated_name_that_changed_size_with_different_bindings_is_reported_not_guessed(): void
    {
        $old = $this->cds([$this->field('contact.date', 'Date'), $this->field('contact.date', 'Date')]);
        $new = $this->cds([$this->field('contact.date', 'Date'), $this->field('contact.date', 'Date'), $this->field('contact.date', 'Date')]);
        [$tags, $mappings] = $this->state($old);
        $mappings['tag-0']['label'] = 'FIRST-DATE';
        $mappings['tag-1']['label'] = 'SECOND-DATE';

        $r = $this->svc()->carryOver($old, $tags, $mappings, $new);

        $this->assertSame([], $r['bindings']);
        $this->assertCount(2, $r['report']['dropped']);
        $this->assertCount(3, $r['report']['added']);
        $this->assertStringContainsString('cannot be matched safely', $r['report']['added'][0]['reason']);
    }

    public function test_a_repeated_name_that_changed_size_but_always_meant_the_same_thing_is_carried(): void
    {
        $old = $this->cds([$this->field('contact.date', 'Date'), $this->field('contact.date', 'Date')]);
        $new = $this->cds([$this->field('contact.date', 'Date'), $this->field('contact.date', 'Date'), $this->field('contact.date', 'Date')]);
        [$tags, $mappings] = $this->state($old);
        $mappings['tag-0'] = $mappings['tag-1'] = ['mappingType' => 'named_field', 'typeKey' => 'sf:date', 'label' => 'SAME-DATE'];

        $r = $this->svc()->carryOver($old, $tags, $mappings, $new);

        $this->assertSame(['SAME-DATE', 'SAME-DATE', 'SAME-DATE'], array_map(fn ($b) => $b['label'], array_values($r['bindings'])));
        $this->assertSame([], $r['report']['added']);
    }

    public function test_an_unbound_old_field_carries_nothing_and_is_not_reported_as_lost(): void
    {
        $old = $this->cds([$this->marker('a', 'A')]);
        $new = $this->cds([$this->marker('a', 'A')]);
        [$tags] = $this->state($old);

        $r = $this->svc()->carryOver($old, $tags, ['tag-0' => ['mappingType' => 'named_field', 'typeKey' => '']], $new);

        $this->assertSame([], $r['bindings']);
        $this->assertSame([], $r['report']['dropped']);
        $this->assertSame([], $r['report']['added']);
    }

    public function test_field_order_is_the_same_order_the_builder_uses_for_parser_index(): void
    {
        $cds = json_decode((string) file_get_contents(base_path('database/seeders/data/exclusive-authority-to-sell.json')), true);
        $cds = is_string($cds['cds_json']) ? json_decode($cds['cds_json'], true) : $cds['cds_json'];

        $controller = (new \ReflectionClass(TemplateController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($controller, 'extractFieldsFromCds');
        $m->setAccessible(true);
        $builder = $m->invoke($controller, $cds);

        $mine = $this->svc()->fieldList($cds);

        $this->assertNotEmpty($builder);
        $this->assertCount(count($builder), $mine);
        foreach ($builder as $i => $f) {
            $this->assertSame($f['index'], $mine[$i]['index']);
            $this->assertSame($f['field_name'], $mine[$i]['name']);
        }
    }
}
