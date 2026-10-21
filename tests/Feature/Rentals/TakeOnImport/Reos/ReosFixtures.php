<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\User;
use App\Services\Rentals\TakeOnImport\Reos\ReosRentRollParser;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyScheduleParser;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * AT-479 — SYNTHETIC reOS files and tenants only. Every name is "ZZ TEST …", every e-mail @example.test, every uuid made up.
 * No real client data anywhere in the reOS tests (the brief's rule).
 */
trait ReosFixtures
{
    protected const UUID_A = '11111111-2222-3333-4444-555555555551';
    protected const UUID_B = '11111111-2222-3333-4444-555555555552';
    protected const UUID_C = '11111111-2222-3333-4444-555555555553';
    protected const UUID_D = '11111111-2222-3333-4444-555555555554';

    /** @var list<string> */
    private array $reosTempFiles = [];

    protected function tearDownReosFiles(): void
    {
        foreach ($this->reosTempFiles as $f) {
            @unlink($f);
        }
        $this->reosTempFiles = [];
    }

    /** Header name => value, with sensible ZZ TEST defaults. */
    protected function reosRow(array $o = []): array
    {
        $uuid = $o['uuid'] ?? self::UUID_A;
        $sep = $o['sep'] ?? ',';
        unset($o['uuid'], $o['sep']);

        return array_merge([
            'Lease ID' => '=HYPERLINK("https://app.reos.co.za/leases/' . $uuid . '"' . $sep . ' "' . $uuid . '")',
            'Property Address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue',
            'Owner' => 'ZZ TEST Owner One',
            'Owner Email' => 'zz.owner1@example.test',
            'Owner Phone' => '+27815550101',
            'Tenant' => 'ZZ TEST Tenant One',
            'Tenant Email' => 'zz.tenant1@example.test',
            'Tenant Phone' => '+27 635550102',
            'Lease Type' => 'Managed',
            'Lease Status' => 'Active',
            'Lease Start' => '2025-03-01',
            'Lease End' => '2027-02-28',
            'Rollover' => 'True',
            'Deposit Required' => '8500.00',
            'Current Rent' => '8500.00',
            'Fixed Invoices' => '',
            'Variable Invoices' => '',
            'Current Commission (including VAT)' => '759.00',
            'Current Commission (excluding VAT)' => '660.00',
            'Agent' => 'ZZ Agent Person (0%),ZZ TEST Agency (100%)',
            'Lease Segment' => 'Residential',
        ], $o);
    }

    /** @param list<array<string,mixed>> $rows */
    protected function scheduleFile(array $rows, string $sheet = 'Tenancy Schedule', ?array $headers = null, int $headerRow = 4, ?array $preamble = null): string
    {
        $headers ??= ReosTenancyScheduleParser::HEADERS;
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle($sheet);
        // Whatever sits above the header row: the title and "Compiled" by default, or exactly the rows the test names (row => text).
        foreach ($preamble ?? [1 => 'Tenancy Schedule / ZZ TEST Agency', 3 => 'Compiled: 2026-10-21 08:00'] as $rowNo => $text) {
            if ($rowNo < $headerRow) {
                $ws->setCellValue([1, $rowNo], $text);
            }
        }
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, $headerRow], $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($headers as $i => $h) {
                if (array_key_exists($h, $row) && $row[$h] !== '' && $row[$h] !== null) {
                    $ws->setCellValueExplicit([$i + 1, $headerRow + 1 + $r], (string) $row[$h], $this->cellType((string) $row[$h]));
                }
            }
        }

        return $this->save($ss);
    }

    /** A formula (starts with "=") must be written as a formula cell, as reOS's export does; everything else as text. */
    private function cellType(string $v): string
    {
        return str_starts_with($v, '=') ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
    }

    /** @param list<array{address:string,tenant:string,wallet:string}> $rows */
    protected function rentRollFile(array $rows, string $sheet = 'Rent Roll', int $headerRow = 4, ?array $preamble = null): string
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle($sheet);
        foreach ($preamble ?? [1 => 'Rent Roll / ZZ TEST Agency'] as $rowNo => $text) {
            if ($rowNo < $headerRow) {
                $ws->setCellValue([1, $rowNo], $text);
            }
        }
        $headers = array_merge(ReosRentRollParser::HEADERS, ['Rent', 'Balance', 'Arrears']);
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, $headerRow], $h);
        }
        foreach ($rows as $r => $row) {
            $vals = [$row['address'], 'ZZ Agent Person', 'ZZ TEST Owner One', $row['tenant'], $row['wallet'], '2027-02-28', '8500.00', '0.00', '0.00'];
            foreach ($vals as $i => $v) {
                $ws->setCellValueExplicit([$i + 1, $headerRow + 1 + $r], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        return $this->save($ss);
    }

    /**
     * A sheet with exactly the headers/rows given (list of lists), at any column offset and order: for the "columns are found by
     * their heading" tests. $headerRow is where the heading row goes; data follows directly below it.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    protected function customSheetFile(string $sheet, array $headers, array $rows, int $headerRow = 4, int $firstColumn = 1): string
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle($sheet);
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$firstColumn + $i, $headerRow], $h);
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $v) {
                $ws->setCellValueExplicit([$firstColumn + $i, $headerRow + 1 + $r], (string) $v, str_starts_with((string) $v, '=') ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        return $this->save($ss);
    }

    private function save(Spreadsheet $ss): string
    {
        $base = tempnam(sys_get_temp_dir(), 'zzreos');
        $path = $base . '.xlsx';
        // reOS's export carries formula text with no calculated value; do not let PhpSpreadsheet calculate (it cannot, for the ';' form).
        $writer = new Xlsx($ss);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        @unlink($base);
        $this->reosTempFiles[] = $path;

        return $path;
    }

    protected function upload(string $path, string $name = 'ZZ-tenancy-schedule.xlsx'): UploadedFile
    {
        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @return array{0: Agency, 1: Branch, 2: User, 3: User} agency, branch, admin (owner-role, may import), a real agent */
    protected function reosAgency(string $name = 'ZZ TEST Agency'): array
    {
        $agency = Agency::create(['name' => $name, 'slug' => 'zz-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'ZZ Main Branch']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'ZZ Agent Person']);

        return [$agency, $branch, $admin, $agent];
    }

    /** A verified P24 suburb with its city and province. */
    protected function p24Suburb(string $suburb = 'ZZ Testville', string $city = 'ZZ Test City', string $province = 'ZZ Test Province'): P24Suburb
    {
        $country = \App\Models\P24Country::firstOrCreate(['name' => 'ZZ Test Country'], ['p24_id' => 1]);
        $prov = P24Province::firstOrCreate(['name' => $province], ['p24_id' => random_int(100000, 999999), 'p24_country_id' => $country->id, 'p24_verified_at' => now()]);
        $c = P24City::firstOrCreate(['name' => $city, 'p24_province_id' => $prov->id], ['p24_id' => random_int(100000, 999999), 'p24_verified_at' => now()]);

        return P24Suburb::firstOrCreate(['name' => $suburb, 'p24_city_id' => $c->id], ['slug' => strtolower(str_replace(' ', '-', $suburb)) . '-' . uniqid(), 'p24_id' => random_int(100000, 999999), 'p24_verified_at' => now()]);
    }
}
