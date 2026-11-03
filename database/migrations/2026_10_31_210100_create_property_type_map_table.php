<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, step 5.2 (.ai/specs/mic-work-tab.md section 8, AT-519): ONE property-type mapping table.
 *
 * Every raw type text CoreX holds (properties, MIC listings, CMA comp rows, P24 alerts, tracked files) maps to one canonical
 * `type_key`: house, apartment, townhouse, land, farm, commercial, industrial, other. `raw_key` is the lower-cased, trimmed text.
 * `agency_id` NULL = the global row every agency uses; an agency row of the same raw_key overrides it for that agency only.
 * `confidence`: exact | assumed (Duplex/Simplex read as townhouse) | title_type_only (a CMA title-type label such as "Residence" says
 * nothing about house vs flat, so it is `other`, never guessed). Global reference data: seeded HERE so it travels on a git-pull deploy.
 */
return new class extends Migration
{
    private const ROWS = [
        // raw_key => [type_key, confidence]
        'house' => ['house', 'exact'], 'house (2 storey)' => ['house', 'exact'], 'house (3 storey)' => ['house', 'exact'],
        'apartment' => ['apartment', 'exact'], 'apartment / flat' => ['apartment', 'exact'], 'flat' => ['apartment', 'exact'],
        'townhouse' => ['townhouse', 'exact'], 'duplex' => ['townhouse', 'assumed'], 'simplex' => ['townhouse', 'assumed'],
        'vacant land' => ['land', 'exact'], 'vacant land / plot' => ['land', 'exact'], 'vacantland' => ['land', 'exact'], 'land' => ['land', 'exact'],
        'farm' => ['farm', 'exact'],
        'commercial' => ['commercial', 'exact'], 'commercial property' => ['commercial', 'exact'], 'business / commercial: business' => ['commercial', 'exact'],
        'industrial' => ['industrial', 'exact'], 'industrial property' => ['industrial', 'exact'],
        'residence' => ['other', 'title_type_only'], 'residential' => ['other', 'title_type_only'], 'full title' => ['other', 'title_type_only'],
        'residence: residential' => ['other', 'title_type_only'], 'amenity: residence' => ['other', 'title_type_only'],
        'sectional title scheme' => ['other', 'title_type_only'],
    ];

    public function up(): void
    {
        Schema::create('property_type_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('raw_key', 100);
            $table->string('type_key', 24);
            $table->string('confidence', 20)->default('exact');
            $table->timestamps();

            $table->unique(['agency_id', 'raw_key'], 'ptm_agency_raw_unique');
            $table->index('raw_key');
        });

        $now = now();
        DB::table('property_type_map')->insert(array_map(
            fn ($raw, $v) => ['agency_id' => null, 'raw_key' => $raw, 'type_key' => $v[0], 'confidence' => $v[1], 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::ROWS),
            array_values(self::ROWS),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('property_type_map');
    }
};
