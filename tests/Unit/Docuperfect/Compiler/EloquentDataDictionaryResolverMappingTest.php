<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler;

use App\Models\Docuperfect\DataDictionaryEntry;
use App\Services\Docuperfect\Compiler\Support\EloquentDataDictionaryResolver;
use PHPUnit\Framework\TestCase;

/**
 * WS0↔WS1 seam — proves the CoreX dictionary row → linter DictionaryEntry VO mapping
 * (canonical type + normalized validation). Pure unit test: models built in memory, no DB.
 */
final class EloquentDataDictionaryResolverMappingTest extends TestCase
{
    private function model(string $dataType, array $attrs = []): DataDictionaryEntry
    {
        return new DataDictionaryEntry(array_merge([
            'key' => 'k',
            'category' => 'other',
            'label' => 'Label',
            'data_type' => $dataType,
            'validation' => null,
        ], $attrs));
    }

    public function test_maps_data_types_to_canonical_linter_types(): void
    {
        $cases = [
            'zar_money' => 'money_zar',
            'sa_id' => 'sa_id',
            'ppra_no' => 'ppra_no',
            'ffc_no' => 'ppra_no',
            'date' => 'date',
            'marital_status' => 'enum',
            'erf_number' => 'string',
            'title_deed' => 'string',
            'gps' => 'string',
            'full_name' => 'string',
            'text' => 'string',
        ];

        foreach ($cases as $dataType => $expected) {
            $vo = EloquentDataDictionaryResolver::toContractEntry($this->model($dataType));
            $this->assertSame($expected, $vo->type, "{$dataType} should map to {$expected}");
            $this->assertSame($expected, $vo->constraint('type'));
        }
    }

    public function test_marital_status_options_become_enum_constraint(): void
    {
        $vo = EloquentDataDictionaryResolver::toContractEntry(
            $this->model('marital_status', ['validation' => ['options' => ['Single', 'Married COP', 'Divorced']]]),
        );

        $this->assertSame('enum', $vo->type);
        $this->assertSame(['Single', 'Married COP', 'Divorced'], $vo->constraint('enum'));
    }

    public function test_text_length_bounds_map_to_min_max_length(): void
    {
        $vo = EloquentDataDictionaryResolver::toContractEntry(
            $this->model('text', ['validation' => ['min' => 3, 'max' => 500]]),
        );

        $this->assertSame(3, $vo->constraint('min_length'));
        $this->assertSame(500, $vo->constraint('max_length'));
    }

    public function test_absolute_date_bounds_and_regex_map_through(): void
    {
        $vo = EloquentDataDictionaryResolver::toContractEntry(
            $this->model('date', ['validation' => ['after_date' => '2026-01-01', 'before_date' => '2026-12-31']]),
        );
        $this->assertSame('2026-01-01', $vo->constraint('min'));
        $this->assertSame('2026-12-31', $vo->constraint('max'));

        $regexVo = EloquentDataDictionaryResolver::toContractEntry(
            $this->model('text', ['validation' => ['regex' => '/^[A-Z]{2}\d+$/']]),
        );
        $this->assertSame('/^[A-Z]{2}\d+$/', $regexVo->constraint('regex'));
    }

    public function test_preserves_ref_category_and_label(): void
    {
        $vo = EloquentDataDictionaryResolver::toContractEntry(
            $this->model('zar_money', ['key' => 'purchase_price', 'category' => 'money', 'label' => 'Purchase Price']),
        );

        $this->assertSame('purchase_price', $vo->ref);
        $this->assertSame('money', $vo->category);
        $this->assertSame('Purchase Price', $vo->label);
    }
}
