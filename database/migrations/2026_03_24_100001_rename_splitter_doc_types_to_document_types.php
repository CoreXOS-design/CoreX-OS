<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 0. The name 'document_types' is already taken by the presentation/doc-library
        //    table. Move it out of the way first.
        if (Schema::hasTable('document_types') && Schema::hasColumn('document_types', 'key')) {
            Schema::rename('document_types', 'document_library_types');
        }

        // 1. Rename splitter_doc_types → document_types
        Schema::rename('splitter_doc_types', 'document_types');

        // 2. Add grouping column
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('grouping', 20)->default('shared')->after('is_active');
        });

        // 3. Update existing rows with smart grouping
        $contactSlugs  = ['fica', 'ids', 'por'];
        $propertySlugs = ['condition_report', 'rates_taxes', 'body_corporate', 'house_rules'];

        DB::table('document_types')
            ->whereIn('slug', $contactSlugs)
            ->update(['grouping' => 'contact']);

        DB::table('document_types')
            ->whereIn('slug', $propertySlugs)
            ->update(['grouping' => 'property']);

        // 4. Merge entries from docuperfect_document_types that don't already exist
        if (Schema::hasTable('docuperfect_document_types')) {
            $dpTypes = DB::table('docuperfect_document_types')->whereNull('deleted_at')->get();
            $existingSlugs = DB::table('document_types')->pluck('slug')->toArray();
            $maxSort = DB::table('document_types')->max('sort_order') ?? 0;

            $dpNameToSlug = [
                'Mandates'           => 'mandate',
                'OTPs'               => 'offer_to_purchase',
                'Condition Reports'  => 'condition_report',
                'FICA'               => 'fica',
                'Other'              => 'other',
                'Addendums'          => 'addendum',
                'Rental Agreements'  => 'rental_agreement',
            ];

            $now = now();
            foreach ($dpTypes as $dp) {
                $slug = $dpNameToSlug[$dp->name] ?? Str::slug($dp->name, '_');
                if (!in_array($slug, $existingSlugs)) {
                    $maxSort++;
                    DB::table('document_types')->insert([
                        'slug'       => $slug,
                        'label'      => $dp->name,
                        'sort_order' => $maxSort,
                        'is_active'  => true,
                        'grouping'   => 'shared',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $existingSlugs[] = $slug;
                }
            }
        }

        // 5. Merge entries from rental_document_types that don't already exist
        if (Schema::hasTable('rental_document_types')) {
            $rentalTypes = DB::table('rental_document_types')->whereNull('deleted_at')->get();
            $existingSlugs = DB::table('document_types')->pluck('slug')->toArray();
            $maxSort = DB::table('document_types')->max('sort_order') ?? 0;

            $rentalNameToSlug = [
                'Lease Agreement'    => 'lease_agreement',
                'Mandate'            => 'mandate',
                'Addendum'           => 'addendum',
                'Notice'             => 'notice',
                'Inspection Report'  => 'inspection_report',
                'Power of Attorney'  => 'power_of_attorney',
                'Disclosure'         => 'disclosure',
                'Other'              => 'other',
            ];

            $now = now();
            foreach ($rentalTypes as $rt) {
                $slug = $rentalNameToSlug[$rt->name] ?? Str::slug($rt->name, '_');
                if (!in_array($slug, $existingSlugs)) {
                    $maxSort++;
                    DB::table('document_types')->insert([
                        'slug'       => $slug,
                        'label'      => $rt->name,
                        'sort_order' => $maxSort,
                        'is_active'  => true,
                        'grouping'   => 'shared',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $existingSlugs[] = $slug;
                }
            }
        }
    }

    public function down(): void
    {
        // Remove merged entries that weren't in original splitter table
        $originalSlugs = [
            'mandate', 'fica', 'ids', 'por', 'condition_report', 'listing_form',
            'rates_taxes', 'body_corporate', 'house_rules', 'offer_to_purchase',
            'disclosure', 'other',
        ];
        DB::table('document_types')
            ->whereNotIn('slug', $originalSlugs)
            ->delete();

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('grouping');
        });

        Schema::rename('document_types', 'splitter_doc_types');

        // Restore the doc-library table name
        if (Schema::hasTable('document_library_types')) {
            Schema::rename('document_library_types', 'document_types');
        }
    }
};
