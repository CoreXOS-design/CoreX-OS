<?php

declare(strict_types=1);

namespace Tests\Unit\Leases;

use App\Services\Rentals\LeaseAgreementValuesReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * leases.md §15.8.2 (Build L1) — the reader is pure: printed text in, typed values out. These pin the
 * parsing of what an agreement actually prints (money with a CSS "R", nbsp, comma/space thousands,
 * both decimal marks; dates in the formats an agreement prints), the struck-field rule (the reworded
 * <ins> text wins over the struck <del> text) and the precedence of the five places a value can live.
 */
final class LeaseAgreementValuesReaderTest extends TestCase
{
    private function reader(): LeaseAgreementValuesReader
    {
        return new LeaseAgreementValuesReader;
    }

    #[DataProvider('moneyProvider')]
    public function test_money_is_read_to_the_cent(string $printed, ?float $expected): void
    {
        $this->assertSame($expected, LeaseAgreementValuesReader::parseMoney($printed));
    }

    public static function moneyProvider(): array
    {
        return [
            'letter R and space thousands' => ['R6 940', 6940.0],
            'nbsp thousands' => ["R6\u{00A0}940", 6940.0],
            'narrow nbsp thousands' => ["6\u{202F}940", 6940.0],
            'comma thousands, dot decimals' => ['6,940.00', 6940.0],
            'space thousands, comma decimals' => ['6 940,50', 6940.5],
            'no R, no separators' => ['6940', 6940.0],
            'R with a space' => ['R 6940', 6940.0],
            'cents' => ['R6 940.55', 6940.55],
            'lone comma then three digits is thousands' => ['6,940', 6940.0],
            'dots as thousands' => ['1.234.567', 1234567.0],
            'words are not money' => ['by arrangement', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('dateProvider')]
    public function test_dates_are_read_in_the_formats_an_agreement_prints(string $printed, ?string $expected): void
    {
        $this->assertSame($expected, LeaseAgreementValuesReader::parseDate($printed));
    }

    public static function dateProvider(): array
    {
        return [
            'iso' => ['2026-10-13', '2026-10-13'],
            'day month-name year' => ['13 October 2026', '2026-10-13'],
            'single-digit day' => ['1 November 2026', '2026-11-01'],
            'abbreviated month' => ['13 Oct 2026', '2026-10-13'],
            'nbsp between parts' => ["13\u{00A0}October\u{00A0}2026", '2026-10-13'],
            'slashes are day first, never month first' => ['01/02/2026', '2026-02-01'],
            'an impossible date is not a date' => ['31 February 2026', null],
            'garbage' => ['next month', null],
        ];
    }

    public function test_percent_integer_and_month_parsing(): void
    {
        $this->assertSame(7.5, LeaseAgreementValuesReader::parsePercent('7.5'));
        $this->assertSame(7.5, LeaseAgreementValuesReader::parsePercent('7,5 %'));
        $this->assertNull(LeaseAgreementValuesReader::parsePercent('seven'));
        $this->assertSame(2, LeaseAgreementValuesReader::parseInteger(' 2 '));
        $this->assertNull(LeaseAgreementValuesReader::parseInteger('two'));
        $this->assertSame(10, LeaseAgreementValuesReader::parseMonth('October'));
        $this->assertSame(10, LeaseAgreementValuesReader::parseMonth('oct'));
        $this->assertSame(3, LeaseAgreementValuesReader::parseMonth('03'));
        $this->assertNull(LeaseAgreementValuesReader::parseMonth('13'));
    }

    public function test_a_struck_and_reworded_field_reads_as_the_new_text_never_the_struck_text(): void
    {
        $html = '<p>Rent <span data-field="monthly_rental"><span data-strikethrough-applied="1">'
            .'<del class="change-del">R6 500</del> <ins class="change-ins">R6 940</ins></span></span></p>';

        $out = $this->reader()->readFromParts($html, [], [], ['rent' => 'monthly_rental']);

        $this->assertSame('R6 940', $out['rent']['printed']);
        $this->assertSame(6940.0, $out['rent']['parsed']);
        $this->assertTrue($out['rent']['struck']);
        $this->assertSame('html', $out['rent']['source']);
    }

    public function test_a_struck_field_with_no_replacement_reads_as_empty_not_as_the_struck_text(): void
    {
        $html = '<span data-field="monthly_rental"><span data-strikethrough-applied="1"><del class="change-del">R6 500</del></span></span>';

        $out = $this->reader()->readFromParts($html, [], [], ['rent' => 'monthly_rental']);

        $this->assertSame('', $out['rent']['printed']);
        $this->assertNull($out['rent']['parsed']);
        $this->assertTrue($out['rent']['struck']);
    }

    public function test_the_five_sources_are_read_in_precedence_order(): void
    {
        $html = '<span data-field="monthly_rental">R1</span>';
        $data = [
            '_fill_review_overlay' => ['monthly_rental' => 'R2'],
            'field_values' => ['monthly_rental' => 'R3'],
            'monthly_rental' => 'R4',
        ];
        $fieldsJson = [['field_name' => 'monthly_rental', 'value' => 'R5']];
        $map = ['rent' => 'monthly_rental'];
        $r = $this->reader();

        $this->assertSame(['R1', 'html'], $this->printedAndSource($r->readFromParts($html, $data, $fieldsJson, $map)));

        $this->assertSame(['R2', 'overlay'], $this->printedAndSource($r->readFromParts(null, $data, $fieldsJson, $map)));
        unset($data['_fill_review_overlay']);
        $this->assertSame(['R3', 'field_values'], $this->printedAndSource($r->readFromParts(null, $data, $fieldsJson, $map)));
        unset($data['field_values']);
        $this->assertSame(['R4', 'flat'], $this->printedAndSource($r->readFromParts(null, $data, $fieldsJson, $map)));
        unset($data['monthly_rental']);
        $this->assertSame(['R5', 'fields_json'], $this->printedAndSource($r->readFromParts(null, $data, $fieldsJson, $map)));
        $this->assertSame([null, null], $this->printedAndSource($r->readFromParts(null, [], [], $map)));
    }

    public function test_fields_json_may_also_be_a_plain_map(): void
    {
        $out = $this->reader()->readFromParts(null, [], ['monthly_rental' => 'R6 940'], ['rent' => 'monthly_rental']);

        $this->assertSame(6940.0, $out['rent']['parsed']);
        $this->assertSame('fields_json', $out['rent']['source']);
    }

    public function test_an_empty_higher_source_does_not_hide_a_value_in_a_lower_one(): void
    {
        $out = $this->reader()->readFromParts('<span data-field="monthly_rental"></span>', ['monthly_rental' => 'R6 940'], [], ['rent' => 'monthly_rental']);

        $this->assertSame(['R6 940', 'flat'], $this->printedAndSource($out));
    }

    public function test_an_unreadable_value_keeps_what_was_printed_and_parses_to_null(): void
    {
        $html = '<span data-field="monthly_rental">by arrangement</span><span data-field="lease_start">soon</span>';

        $out = $this->reader()->readFromParts($html, [], [], ['rent' => 'monthly_rental', 'start_date' => 'lease_start']);

        $this->assertSame('by arrangement', $out['rent']['printed']);
        $this->assertNull($out['rent']['parsed']);
        $this->assertSame('soon', $out['start_date']['printed']);
        $this->assertNull($out['start_date']['parsed']);
    }

    public function test_a_value_printed_twice_is_read_once_and_the_first_non_empty_wins(): void
    {
        $html = '<span data-field="monthly_rental"></span> … <span data-field="monthly_rental">R6 940</span> … <span data-field="monthly_rental">R9 999</span>';

        $out = $this->reader()->readFromParts($html, [], [], ['rent' => 'monthly_rental']);

        $this->assertSame('R6 940', $out['rent']['printed']);
    }

    public function test_the_per_recipient_instance_of_a_field_is_found_when_the_plain_name_is_not_printed(): void
    {
        $html = '<span data-field="lessee_name__r1">  Thandi   Nkosi </span>';

        $out = $this->reader()->readFromParts($html, [], [], ['tenant_name' => 'lessee_name']);

        $this->assertSame('Thandi Nkosi', $out['tenant_name']['printed']);
    }

    public function test_values_are_typed_by_the_registry_and_an_unknown_key_is_plain_text(): void
    {
        $html = '<span data-field="a">2</span><span data-field="b">7,5</span><span data-field="c">October</span>'
            .'<span data-field="d">13 October 2026</span><span data-field="e">2026-10-13</span><span data-field="f">  anything   goes </span>'
            .'<span data-field="g">2</span><span data-field="h">1</span>';
        $map = [
            'adults' => 'a', 'escalation_percent' => 'b', 'escalation_month' => 'c', 'earliest_termination_date' => 'd',
            'start_date' => 'e', 'some_agency_extra' => 'f', 'tenant_name_2' => 'g', 'renewal_option_months' => 'h',
        ];

        $out = $this->reader()->readFromParts($html, [], [], $map);

        $this->assertSame(2, $out['adults']['parsed']);
        $this->assertSame(7.5, $out['escalation_percent']['parsed']);
        $this->assertSame(10, $out['escalation_month']['parsed']);
        $this->assertSame('2026-10-13', $out['earliest_termination_date']['parsed']);
        $this->assertSame('2026-10-13', $out['start_date']['parsed']);
        $this->assertSame('anything goes', $out['some_agency_extra']['parsed']);
        // A member of an indexed family keeps the family's type (text), and a name is never typed as a number.
        $this->assertSame('2', $out['tenant_name_2']['parsed']);
        $this->assertSame(1, $out['renewal_option_months']['parsed']);
    }

    public function test_the_map_accepts_both_shapes_and_drops_unusable_entries(): void
    {
        $map = $this->reader()->normaliseMap([
            'rent' => 'monthly_rental',
            'pets' => ['field' => ' what_pets ', 'required' => true, 'label' => '  Pets  '],
            'adults' => ['field' => ''],
            'deposit' => ['required' => true],
            'other_conditions' => 42,
            0 => 'not_a_key',
        ]);

        $this->assertSame([
            'rent' => ['field' => 'monthly_rental', 'required' => false, 'label' => null],
            'pets' => ['field' => 'what_pets', 'required' => true, 'label' => 'Pets'],
        ], $map);
    }

    public function test_html_with_no_fields_and_a_document_with_no_data_read_as_nothing(): void
    {
        $out = $this->reader()->readFromParts('<p>No fields here</p>', [], [], ['rent' => 'monthly_rental']);

        $this->assertSame([null, null], $this->printedAndSource($out));
        $this->assertFalse($out['rent']['struck']);
        $this->assertSame([], $this->reader()->readFromParts(null, [], [], []));
    }

    /** @return array{0: ?string, 1: ?string} */
    private function printedAndSource(array $out): array
    {
        return [$out['rent']['printed'], $out['rent']['source']];
    }
}
