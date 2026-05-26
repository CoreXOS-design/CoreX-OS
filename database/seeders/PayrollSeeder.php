<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PayrollTaxTableSeeder::class,
            PayrollTaxRebateSeeder::class,
            PayrollEarningTypeSeeder::class,
            PayrollDeductionTypeSeeder::class,
        ]);
    }
}
