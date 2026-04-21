<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed FICA-related document types that don't yet exist in the taxonomy.
     */
    public function up(): void
    {
        $maxSort = DB::table('document_types')->max('sort_order') ?? 0;

        $newTypes = [
            ['slug' => 'bank_statement',       'label' => 'Bank Statement',        'grouping' => 'contact'],
            ['slug' => 'tax_clearance',        'label' => 'Tax Clearance',         'grouping' => 'contact'],
            ['slug' => 'company_registration', 'label' => 'Company Registration',  'grouping' => 'contact'],
            ['slug' => 'trust_deed',           'label' => 'Trust Deed',            'grouping' => 'contact'],
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

    /**
     * Remove the seeded document types.
     */
    public function down(): void
    {
        DB::table('document_types')->whereIn('slug', [
            'bank_statement', 'tax_clearance', 'company_registration', 'trust_deed',
        ])->delete();
    }
};
