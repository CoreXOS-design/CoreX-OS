<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * AT-392 — the V8 rental application checklist references two document
     * types the taxonomy doesn't have yet (payslip, financial statements).
     * Everything else it needs (ids, por, bank_statement, company_registration,
     * power_of_attorney) already exists — reused, not duplicated.
     */
    public function up(): void
    {
        $maxSort = DB::table('document_types')->max('sort_order') ?? 0;

        $newTypes = [
            ['slug' => 'payslip',              'label' => 'Payslip',               'grouping' => 'contact'],
            ['slug' => 'financial_statements', 'label' => 'Financial Statements',  'grouping' => 'contact'],
        ];

        foreach ($newTypes as $i => $type) {
            $exists = DB::table('document_types')->where('slug', $type['slug'])->exists();
            if (! $exists) {
                DB::table('document_types')->insert([
                    'slug'       => $type['slug'],
                    'label'      => $type['label'],
                    'grouping'   => $type['grouping'],
                    'sort_order' => $maxSort + $i + 1,
                    'is_active'  => true,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('document_types')->whereIn('slug', ['payslip', 'financial_statements'])->delete();
    }
};
