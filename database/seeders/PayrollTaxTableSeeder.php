<?php

namespace Database\Seeders;

use App\Models\Payroll\PayrollTaxTable;
use Illuminate\Database\Seeder;

class PayrollTaxTableSeeder extends Seeder
{
    public function run(): void
    {
        $taxYearStart = '2026-03-01';
        $taxYearEnd   = '2027-02-28';

        // SARS 2026/27 PAYE brackets (annual income)
        $brackets = [
            ['bracket_order' => 1, 'income_from' => 1,        'income_to' => 237100,  'base_tax' => 0,      'rate_percent' => 18.00],
            ['bracket_order' => 2, 'income_from' => 237101,   'income_to' => 370500,  'base_tax' => 42678,  'rate_percent' => 26.00],
            ['bracket_order' => 3, 'income_from' => 370501,   'income_to' => 512800,  'base_tax' => 77362,  'rate_percent' => 31.00],
            ['bracket_order' => 4, 'income_from' => 512801,   'income_to' => 673000,  'base_tax' => 121475, 'rate_percent' => 36.00],
            ['bracket_order' => 5, 'income_from' => 673001,   'income_to' => 857900,  'base_tax' => 179147, 'rate_percent' => 39.00],
            ['bracket_order' => 6, 'income_from' => 857901,   'income_to' => 1817000, 'base_tax' => 251258, 'rate_percent' => 41.00],
            ['bracket_order' => 7, 'income_from' => 1817001,  'income_to' => null,    'base_tax' => 644489, 'rate_percent' => 45.00],
        ];

        $this->validateBrackets($brackets);

        foreach ($brackets as $bracket) {
            PayrollTaxTable::updateOrCreate(
                [
                    'tax_year_start' => $taxYearStart,
                    'bracket_order'  => $bracket['bracket_order'],
                ],
                array_merge($bracket, [
                    'tax_year_start' => $taxYearStart,
                    'tax_year_end'   => $taxYearEnd,
                ])
            );
        }

        $this->command->info("Seeded {$taxYearStart} PAYE brackets: " . count($brackets) . ' rows.');
    }

    /**
     * Validate bracket continuity and base_tax accumulation.
     * Throws RuntimeException on any data entry error.
     */
    private function validateBrackets(array $brackets): void
    {
        for ($i = 1; $i < count($brackets); $i++) {
            $prev = $brackets[$i - 1];
            $curr = $brackets[$i];

            // income_from of current must equal income_to of previous + 1
            if ($prev['income_to'] !== null) {
                $expectedFrom = $prev['income_to'] + 1;
                if ((int) $curr['income_from'] !== $expectedFrom) {
                    throw new \RuntimeException(
                        "Bracket {$curr['bracket_order']}: income_from ({$curr['income_from']}) "
                        . "does not equal previous income_to + 1 ({$expectedFrom})"
                    );
                }
            }

            // base_tax of current must equal base_tax[prev] + (income_to[prev] - income_from[prev] + 1) * rate[prev] / 100
            if ($prev['income_to'] !== null) {
                $range = $prev['income_to'] - $prev['income_from'] + 1;
                $expectedBaseTax = bcadd(
                    (string) $prev['base_tax'],
                    bcmul((string) $range, bcdiv((string) $prev['rate_percent'], '100', 6), 2),
                    2
                );
                // Allow ±R1 tolerance for SARS rounding
                $diff = abs((float) $expectedBaseTax - (float) $curr['base_tax']);
                if ($diff > 1.00) {
                    throw new \RuntimeException(
                        "Bracket {$curr['bracket_order']}: base_tax ({$curr['base_tax']}) "
                        . "does not match calculated ({$expectedBaseTax}). Diff: R{$diff}"
                    );
                }
            }
        }
    }
}
