<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC capture lane, step 3.6 (.ai/specs/mic-work-tab.md §11.2.2): the free Property24 area pages, read monthly by the
 * extension. All three tables are GLOBAL public facts (no agency_id) like the shared advert pool; nothing private and no
 * paid price is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p24_suburb_trends', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('p24_suburb_id');
            $t->string('suburb', 120)->nullable();
            $t->string('town', 120)->nullable();
            $t->string('metric', 40);
            $t->string('period', 20);
            $t->string('segment', 40)->default('');
            $t->decimal('value', 16, 4)->nullable();
            $t->date('read_on');
            $t->timestamps();
            $t->unique(['p24_suburb_id', 'metric', 'period', 'segment', 'read_on'], 'uniq_p24st_dim');
            $t->index(['p24_suburb_id', 'metric', 'read_on'], 'idx_p24st_suburb_metric');
        });

        Schema::create('p24_street_numbers', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('p24_suburb_id');
            $t->string('street_slug', 120);
            $t->string('street_name', 160)->nullable();
            $t->string('house_number', 20);
            $t->string('address_text', 200)->nullable();
            $t->unsignedSmallInteger('last_sold_year')->nullable();
            $t->date('first_seen_on');
            $t->date('last_seen_on');
            $t->timestamps();
            $t->unique(['p24_suburb_id', 'street_slug', 'house_number'], 'uniq_p24sn_house');
            $t->index(['p24_suburb_id', 'street_slug'], 'idx_p24sn_street');
        });

        Schema::create('p24_suburb_agencies', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('p24_suburb_id');
            $t->string('agency_portal_id', 20);
            $t->string('agency_name', 191);
            $t->unsignedInteger('listings_in_suburb')->nullable();
            $t->unsignedInteger('total_listings')->nullable();
            $t->date('read_on');
            $t->timestamps();
            $t->unique(['p24_suburb_id', 'agency_portal_id', 'read_on'], 'uniq_p24sa_agency_day');
            $t->index(['p24_suburb_id', 'read_on'], 'idx_p24sa_suburb_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_suburb_agencies');
        Schema::dropIfExists('p24_street_numbers');
        Schema::dropIfExists('p24_suburb_trends');
    }
};
