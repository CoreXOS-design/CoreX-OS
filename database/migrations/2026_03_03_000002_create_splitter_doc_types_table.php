<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('splitter_doc_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed existing hardcoded types
        $types = [
            ['slug' => 'mandate',           'label' => 'Mandate',            'sort_order' => 1],
            ['slug' => 'fica',              'label' => 'FICA',              'sort_order' => 2],
            ['slug' => 'ids',               'label' => 'IDs / Identity',    'sort_order' => 3],
            ['slug' => 'por',               'label' => 'Proof of Residence','sort_order' => 4],
            ['slug' => 'condition_report',  'label' => 'Condition Report',  'sort_order' => 5],
            ['slug' => 'listing_form',      'label' => 'Listing Form',      'sort_order' => 6],
            ['slug' => 'rates_taxes',       'label' => 'Rates & Taxes',     'sort_order' => 7],
            ['slug' => 'body_corporate',    'label' => 'Body Corporate',    'sort_order' => 8],
            ['slug' => 'house_rules',       'label' => 'House Rules',       'sort_order' => 9],
            ['slug' => 'offer_to_purchase', 'label' => 'Offer to Purchase', 'sort_order' => 10],
            ['slug' => 'disclosure',        'label' => 'Disclosure',        'sort_order' => 11],
            ['slug' => 'other',             'label' => 'Other',             'sort_order' => 12],
        ];

        $now = now();
        foreach ($types as &$t) {
            $t['is_active']  = true;
            $t['created_at'] = $now;
            $t['updated_at'] = $now;
        }

        DB::table('splitter_doc_types')->insert($types);
    }

    public function down(): void
    {
        Schema::dropIfExists('splitter_doc_types');
    }
};
