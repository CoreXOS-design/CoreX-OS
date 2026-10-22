<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Services\Rentals\TakeOnImport\Reos\ReosContactNames;
use App\Services\Rentals\TakeOnImport\Reos\ReosLayoutException;
use App\Services\Rentals\TakeOnImport\Reos\ReosLeaseIdCell;
use App\Services\Rentals\TakeOnImport\Reos\ReosRentRollMerger;
use App\Services\Rentals\TakeOnImport\Reos\ReosRentRollParser;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyScheduleParser;
use Tests\TestCase;

/**
 * AT-479 / rental-takeon-import.md R2, R6, R8 — the reOS files are read as they really come: the Lease ID is a HYPERLINK
 * formula (proved here on a real .xlsx written with a formula cell, not assumed from the library), the row numbers are the
 * spreadsheet's own, commission/segment/invoices are never kept, a wrong layout is refused with a message. Synthetic data only.
 *
 * Input paths covered: formula with "," and ";", bare uuid, garbage / empty / mismatching Lease ID, wrong sheet, wrong header,
 * not an Excel file, a blank row inside the data, every error row kind, the lazy-but-valid minimal row, Rent Roll match / none /
 * two-rows / ignored money columns, company / joint / person names, several e-mails in a cell, agent text forms.
 */
final class ReosParsingTest extends TestCase
{
    use ReosFixtures;

    protected function tearDown(): void
    {
        $this->tearDownReosFiles();
        parent::tearDown();
    }

    public function test_lease_id_formula_with_comma_or_semicolon_gives_the_uuid_and_the_link(): void
    {
        foreach ([',', ';'] as $sep) {
            $path = $this->scheduleFile([$this->reosRow(['uuid' => self::UUID_A, 'sep' => $sep])]);

            $rows = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false);

            self::assertCount(1, $rows, "separator {$sep}");
            self::assertSame(self::UUID_A, $rows[0]['payload']['external_id'], "separator {$sep}");
            self::assertSame('https://app.reos.co.za/leases/' . self::UUID_A, $rows[0]['payload']['external_url'], "separator {$sep}");
            self::assertSame([], $rows[0]['errors'], "separator {$sep}");
        }
    }

    public function test_lease_id_cell_forms(): void
    {
        self::assertSame(['uuid' => self::UUID_A, 'url' => null], ReosLeaseIdCell::parse(self::UUID_A), 'a bare uuid keeps no invented link');
        self::assertSame(['uuid' => self::UUID_A, 'url' => null], ReosLeaseIdCell::parse(strtoupper(self::UUID_A)), 'upper-case uuid is lower-cased');
        self::assertNull(ReosLeaseIdCell::parse(''));
        self::assertNull(ReosLeaseIdCell::parse(null));
        self::assertNull(ReosLeaseIdCell::parse('not a lease id'));
        self::assertNull(ReosLeaseIdCell::parse('=HYPERLINK("https://app.reos.co.za/leases/" , "")'));
        self::assertNull(
            ReosLeaseIdCell::parse('=HYPERLINK("https://app.reos.co.za/leases/' . self::UUID_A . '", "' . self::UUID_B . '")'),
            'link and friendly text naming different leases is not trusted'
        );
        // friendly text that is not a uuid: the link alone decides; the function name is case-insensitive
        self::assertSame(
            ['uuid' => self::UUID_A, 'url' => 'https://app.reos.co.za/leases/' . self::UUID_A],
            ReosLeaseIdCell::parse('=hyperlink( "https://app.reos.co.za/leases/' . self::UUID_A . '" ; "x" )')
        );
    }

    public function test_row_numbers_are_the_real_spreadsheet_rows_even_with_a_blank_row_between(): void
    {
        $path = $this->scheduleFile([
            $this->reosRow(['uuid' => self::UUID_A]),
            [],   // a blank row in the middle of the data
            $this->reosRow(['uuid' => self::UUID_B, 'Tenant' => 'ZZ TEST Tenant Two']),
        ]);

        $rows = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false);

        self::assertSame([5, 7], array_column($rows, 'row_number'));
    }

    public function test_money_side_columns_are_never_kept_in_the_payload(): void
    {
        $path = $this->scheduleFile([$this->reosRow(['Fixed Invoices' => '120.00', 'Variable Invoices' => '45.00', 'Lease Segment' => 'Residential'])]);

        $row = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false)[0];

        $dump = json_encode($row['payload']);
        foreach (['759', '660', 'commission', 'invoice', 'segment', 'Residential', '120.00', '45.00'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $dump, "'{$needle}' must not be stored");
        }
        self::assertSame(8500.0, $row['payload']['rent']);
        self::assertSame(8500.0, $row['payload']['deposit']);
        self::assertTrue($row['payload']['rollover']);
    }

    public function test_commission_cells_are_understood_but_a_garbled_one_is_only_a_warning(): void
    {
        self::assertSame(759.0, ReosTenancyScheduleParser::parseCommission('759.00'));
        self::assertSame(0.0, ReosTenancyScheduleParser::parseCommission('0.00%'));
        self::assertNull(ReosTenancyScheduleParser::parseCommission('lots'));

        $path = $this->scheduleFile([$this->reosRow(['Current Commission (including VAT)' => 'lots'])]);
        $row = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false)[0];

        self::assertSame([], $row['errors']);
        self::assertNotEmpty($row['warnings']);
    }

    public function test_a_wrong_layout_is_refused_with_a_message_that_says_what_was_found(): void
    {
        $parser = new ReosTenancyScheduleParser();

        $wrongSheet = $this->scheduleFile([$this->reosRow()], 'Some Other Sheet');
        try {
            $parser->assertLayout($wrongSheet);
            self::fail('a file without the "Tenancy Schedule" sheet must be refused');
        } catch (ReosLayoutException $e) {
            self::assertStringContainsString('Tenancy Schedule', $e->getMessage());
            self::assertStringContainsString('Some Other Sheet', $e->getMessage());
        }

        $badHeaders = ReosTenancyScheduleParser::HEADERS;
        $badHeaders[6] = 'Tenant Mail';   // "Tenant Email" is gone, so no row holds the full header set
        try {
            $parser->assertLayout($this->scheduleFile([$this->reosRow()], 'Tenancy Schedule', $badHeaders));
            self::fail('a changed header must be refused');
        } catch (ReosLayoutException $e) {
            self::assertStringContainsString('header row', $e->getMessage());
            self::assertStringContainsString('Tenant Email', $e->getMessage(), 'the message names the expected headers');
            self::assertStringContainsString('Lease ID', $e->getMessage());
        }
    }

    public function test_something_that_is_not_an_excel_file_is_refused_plainly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zznot') . '.xlsx';
        file_put_contents($path, "this is not a spreadsheet\n");
        $this->reosTempFiles[] = $path;

        $this->expectException(ReosLayoutException::class);
        (new ReosTenancyScheduleParser())->assertLayout($path);
    }

    public function test_each_kind_of_bad_row_becomes_an_error_row_not_a_crash(): void
    {
        $path = $this->scheduleFile([
            $this->reosRow(['uuid' => self::UUID_A, 'Lease Status' => 'Terminated']),
            $this->reosRow(['uuid' => self::UUID_B, 'Current Rent' => 'lots']),
            $this->reosRow(['uuid' => self::UUID_C, 'Current Rent' => '0']),
            $this->reosRow(['uuid' => self::UUID_D, 'Lease Start' => '03/04/2025']),
            $this->reosRow(['Lease ID' => 'garbage']),
            $this->reosRow(['uuid' => self::UUID_A]),   // the same reOS lease again
            $this->reosRow(['uuid' => '99999999-2222-3333-4444-555555555555', 'Lease End' => '2024-01-01']),
        ]);

        $rows = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false);

        self::assertCount(7, $rows);
        self::assertStringContainsString('Terminated', $rows[0]['errors'][0]);
        self::assertStringContainsString('Current Rent', implode(' ', $rows[1]['errors']));
        self::assertStringContainsString('more than zero', implode(' ', $rows[2]['errors']));
        self::assertStringContainsString('Lease Start', implode(' ', $rows[3]['errors']));
        self::assertStringContainsString('reOS lease id', implode(' ', $rows[4]['errors']));
        self::assertStringContainsString('appears twice', implode(' ', $rows[5]['errors']));
        self::assertStringContainsString('not after Lease Start', implode(' ', $rows[6]['errors']));
    }

    public function test_the_lazy_but_valid_minimum_row_reads_clean(): void
    {
        // Only what is needed: id, address, status, start, rent. Everything else empty (no end date = month-to-month).
        $path = $this->scheduleFile([[
            'Lease ID' => '=HYPERLINK("https://app.reos.co.za/leases/' . self::UUID_A . '", "' . self::UUID_A . '")',
            'Property Address' => '12 ZZ Beach Road',
            'Lease Status' => 'Draft',
            'Lease Start' => '2026-11-01',
            'Current Rent' => 'R 7 200.50',
        ]]);

        $row = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false)[0];

        self::assertSame([], $row['errors']);
        self::assertSame(7200.5, $row['payload']['rent']);
        self::assertNull($row['payload']['lease_end_date']);
        self::assertNull($row['payload']['rollover']);
        self::assertSame(ReosTenancyScheduleParser::STATUS_DRAFT, $row['payload']['status_action']);
        self::assertCount(2, $row['warnings'], 'no owner / no tenant named are warnings, not errors');
    }

    public function test_rollover_values(): void
    {
        self::assertTrue(ReosTenancyScheduleParser::parseBool('True'));
        self::assertTrue(ReosTenancyScheduleParser::parseBool('yes'));
        self::assertFalse(ReosTenancyScheduleParser::parseBool('False'));
        self::assertFalse(ReosTenancyScheduleParser::parseBool('0'));
        self::assertNull(ReosTenancyScheduleParser::parseBool('maybe'));
        self::assertNull(ReosTenancyScheduleParser::parseBool(null));
    }

    // ── Rent Roll ──────────────────────────────────────────────────────────────────────────

    public function test_rent_roll_wallet_fills_the_matching_schedule_row_and_the_money_columns_are_ignored(): void
    {
        $schedule = iterator_to_array((new ReosTenancyScheduleParser())->rows($this->scheduleFile([
            $this->reosRow(['uuid' => self::UUID_A]),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Marine Drive', 'Tenant' => 'ZZ TEST Tenant Two']),
        ])), false);

        $parser = new ReosRentRollParser();
        $roll = $this->rentRollFile([
            // different punctuation / case / word order must still match
            ['address' => '3 zz aqua breeze, 13 zz stafford avenue', 'tenant' => 'Tenant One, ZZ TEST', 'wallet' => 'WLT-1001'],
            ['address' => '55 ZZ Nowhere Lane', 'tenant' => 'ZZ TEST Nobody', 'wallet' => 'WLT-9999'],
        ]);
        $parser->assertLayout($roll);
        $summary = (new ReosRentRollMerger())->merge($schedule, $parser->rows($roll));

        self::assertSame('WLT-1001', $schedule[0]['payload']['tenant_wallet_ref']);
        self::assertNull($schedule[1]['payload']['tenant_wallet_ref']);
        self::assertSame(['matched' => 1, 'unmatched_roll_rows' => 1, 'ambiguous' => 0], $summary);

        foreach ($parser->rows($roll) as $r) {
            self::assertSame(['row_number', 'address', 'tenant', 'wallet'], array_keys($r), 'only the wallet-related columns are read');
        }
    }

    public function test_two_rent_roll_rows_for_one_lease_are_not_guessed(): void
    {
        $schedule = iterator_to_array((new ReosTenancyScheduleParser())->rows($this->scheduleFile([$this->reosRow()])), false);
        $roll = [
            ['row_number' => 5, 'address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue', 'tenant' => 'ZZ TEST Tenant One', 'wallet' => 'WLT-1'],
            ['row_number' => 6, 'address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue', 'tenant' => 'ZZ TEST Tenant One', 'wallet' => 'WLT-2'],
        ];

        $summary = (new ReosRentRollMerger())->merge($schedule, $roll);

        self::assertNull($schedule[0]['payload']['tenant_wallet_ref']);
        self::assertSame(1, $summary['ambiguous']);
        self::assertStringContainsString('More than one Rent Roll row', implode(' ', $schedule[0]['warnings']));
    }

    public function test_a_rent_roll_with_the_wrong_sheet_or_header_is_refused(): void
    {
        $parser = new ReosRentRollParser();
        try {
            $parser->assertLayout($this->rentRollFile([], 'Rentals'));
            self::fail('wrong sheet must be refused');
        } catch (ReosLayoutException $e) {
            self::assertStringContainsString('Rent Roll', $e->getMessage());
        }

        // A tenancy schedule uploaded in the Rent Roll slot.
        $this->expectException(ReosLayoutException::class);
        $parser->assertLayout($this->scheduleFile([$this->reosRow()]));
    }

    // ── Names ──────────────────────────────────────────────────────────────────────────────

    public function test_company_names_become_entities_without_a_registration_number(): void
    {
        foreach (['ZZ Wildevy Properties Pty Ltd', 'ZZ Holdings (PTY) LTD', 'ZZ Smith CC', 'ZZ Family Trust', 'ZZ Co Inc', 'ZZ Care NPC',
                 'ZZ Investments', 'ZZ Church', 'ZZ JV', 'zz sea view holdings'] as $name) {
            $c = ReosContactNames::classify($name);
            self::assertSame('company', $c['kind'], $name);
            self::assertSame($name, $c['entity_name'], $name);
            self::assertSame($name, $c['first_name'], $name);
            self::assertSame('', $c['last_name'], $name);
        }
    }

    public function test_joint_names_stay_one_contact_with_the_full_name_and_the_last_word_as_surname(): void
    {
        $a = ReosContactNames::classify('William & Dorothy Geyser');
        self::assertSame(['joint', 'William & Dorothy', 'Geyser'], [$a['kind'], $a['first_name'], $a['last_name']]);

        $b = ReosContactNames::classify('A and B Smith');
        self::assertSame(['joint', 'A and B', 'Smith'], [$b['kind'], $b['first_name'], $b['last_name']]);

        $c = ReosContactNames::classify('Pieter&Annelie van Wyk');
        self::assertSame('joint', $c['kind']);
        self::assertSame('wyk', mb_strtolower($c['last_name']));
    }

    public function test_ordinary_people_are_split_as_the_existing_take_on_does(): void
    {
        $p = ReosContactNames::classify('  Johan   van der Merwe ');
        self::assertSame(['person', 'Johan', 'van der Merwe'], [$p['kind'], $p['first_name'], $p['last_name']]);

        $one = ReosContactNames::classify('Madonna');
        self::assertSame(['person', 'Madonna', ''], [$one['kind'], $one['first_name'], $one['last_name']], 'a single word never leaves last_name unset');

        // "and" inside a surname is not a joint owner, and a name that merely contains letters of a company word is not a company.
        self::assertSame('person', ReosContactNames::classify('Sandra Anderson')['kind']);
        self::assertSame('person', ReosContactNames::classify('Cecile Trustworthy')['kind']);
    }

    public function test_name_keys_ignore_order_case_punctuation_and_ampersands(): void
    {
        self::assertSame(ReosContactNames::key('William & Dorothy Geyser'), ReosContactNames::key('geyser, Dorothy and William'));
    }

    public function test_cells_holding_several_emails_or_phones_are_split_and_junk_is_dropped(): void
    {
        self::assertSame(['a@example.test', 'b@example.test'], ReosContactNames::splitValues(' a@example.test; b@example.test , not-an-email ', true));
        self::assertSame(['+27815550101', '031 555 0102'], ReosContactNames::splitValues('+27815550101 / 031 555 0102 / 12', false));
        self::assertSame([], ReosContactNames::splitValues('', true));
        self::assertSame([], ReosContactNames::splitValues(null, false));
    }

    // ── the header row is FOUND, not assumed ───────────────────────────────────────────────

    /** @dataProvider headerRows */
    public function test_the_tenancy_schedule_header_may_be_on_any_of_the_first_15_rows(int $headerRow): void
    {
        $path = $this->scheduleFile([$this->reosRow(['uuid' => self::UUID_A]), $this->reosRow(['uuid' => self::UUID_B, 'Tenant' => 'ZZ TEST Tenant Two'])], 'Tenancy Schedule', null, $headerRow);
        $parser = new ReosTenancyScheduleParser();

        $parser->assertLayout($path);
        $rows = iterator_to_array($parser->rows($path), false);

        self::assertCount(2, $rows);
        self::assertSame([$headerRow + 1, $headerRow + 2], array_column($rows, 'row_number'), 'real spreadsheet row numbers, counted from below the header');
        self::assertSame([self::UUID_A, self::UUID_B], array_map(fn ($r) => $r['payload']['external_id'], $rows));
        self::assertSame(8500.0, $rows[0]['payload']['rent']);
        self::assertSame('ZZ TEST Tenant Two', $rows[1]['payload']['tenant_name']);
    }

    public static function headerRows(): array
    {
        return ['row 3' => [3], 'row 4' => [4], 'row 5' => [5], 'row 8' => [8], 'row 15 (the last one scanned)' => [15]];
    }

    public function test_extra_metadata_rows_and_blank_rows_in_the_preamble_do_not_matter(): void
    {
        // Metadata lines of every kind above the header, with gaps — and a line that merely CONTAINS one header word.
        $preamble = [1 => 'Tenancy Schedule / ZZ TEST Agency', 3 => 'Compiled: 2026-10-21 08:00', 4 => 'Date Range: 2026-10-01 to 2026-10-21', 6 => 'Lease ID totals below', 9 => 'Prepared by ZZ'];
        $path = $this->scheduleFile([$this->reosRow(['uuid' => self::UUID_A])], 'Tenancy Schedule', null, 11, $preamble);

        $rows = iterator_to_array((new ReosTenancyScheduleParser())->rows($path), false);

        self::assertCount(1, $rows);
        self::assertSame(12, $rows[0]['row_number']);
        self::assertSame(self::UUID_A, $rows[0]['payload']['external_id']);
    }

    public function test_the_real_rent_roll_layout_title_blank_compiled_date_range_then_headers_on_row_5(): void
    {
        $path = $this->rentRollFile(
            [['address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue', 'tenant' => 'ZZ TEST Tenant One', 'wallet' => 'WLT-1001'], ['address' => '9 ZZ Marine Drive', 'tenant' => 'ZZ TEST Tenant Two', 'wallet' => 'WLT-1002']],
            'Rent Roll', 5,
            [1 => 'Rent Roll / ZZ TEST Agency', 3 => 'Compiled: 2026-10-21 08:00', 4 => 'Date Range: 2026-10-01 to 2026-10-21']   // row 2 blank
        );
        $parser = new ReosRentRollParser();

        $parser->assertLayout($path);
        $rows = $parser->rows($path);

        self::assertSame([6, 7], array_column($rows, 'row_number'), 'data from row 6');
        self::assertSame(['WLT-1001', 'WLT-1002'], array_column($rows, 'wallet'));
        self::assertSame('ZZ TEST Tenant Two', $rows[1]['tenant']);
    }

    /** @dataProvider headerRows */
    public function test_the_rent_roll_header_may_be_on_any_of_the_first_15_rows(int $headerRow): void
    {
        $path = $this->rentRollFile([['address' => '9 ZZ Marine Drive', 'tenant' => 'ZZ TEST Tenant Two', 'wallet' => 'WLT-1002']], 'Rent Roll', $headerRow);

        $rows = (new ReosRentRollParser())->rows($path);

        self::assertSame([$headerRow + 1], array_column($rows, 'row_number'));
        self::assertSame('WLT-1002', $rows[0]['wallet']);
    }

    public function test_no_header_row_in_the_first_15_rows_is_refused_naming_the_expected_headers(): void
    {
        // The header is on row 16: one row too far down.
        foreach ([
            [new ReosTenancyScheduleParser(), $this->scheduleFile([$this->reosRow()], 'Tenancy Schedule', null, 16), ['Lease ID', 'Property Address', 'Lease Segment']],
            [new ReosRentRollParser(), $this->rentRollFile([['address' => 'x', 'tenant' => 'y', 'wallet' => 'z']], 'Rent Roll', 16), ['Property Address', 'Tenant Wallet', 'Lease End']],
        ] as [$parser, $path, $mustName]) {
            try {
                $parser->assertLayout($path);
                self::fail('a header below row 15 must be refused');
            } catch (ReosLayoutException $e) {
                self::assertStringContainsString('first 15 rows', $e->getMessage());
                foreach ($mustName as $header) {
                    self::assertStringContainsString($header, $e->getMessage());
                }
            }
        }
    }

    public function test_columns_are_found_by_their_heading_whatever_their_position_or_order(): void
    {
        // The Rent Roll with the six wanted headings shuffled, shifted two columns right, and money columns in between.
        $headers = ['Rent', 'Tenant Wallet', 'Owner', 'Balance', 'Tenant', 'Lease End', 'Agent', 'Property Address'];
        $rows = [['9999.00', 'WLT-77', 'ZZ O', '1.00', 'ZZ TEST Tenant Two', '2027-02-28', 'ZZ A', '9 ZZ Marine Drive']];
        $path = $this->customSheetFile('Rent Roll', $headers, $rows, 5, 3);

        $got = (new ReosRentRollParser())->rows($path);

        self::assertSame([['row_number' => 6, 'address' => '9 ZZ Marine Drive', 'tenant' => 'ZZ TEST Tenant Two', 'wallet' => 'WLT-77']], $got);
    }

    public function test_an_empty_sheet_or_a_data_only_sheet_has_no_header_row(): void
    {
        $path = $this->customSheetFile('Rent Roll', ['Foo', 'Bar'], [['1', '2']], 2);

        $this->expectException(ReosLayoutException::class);
        (new ReosRentRollParser())->assertLayout($path);
    }
}

