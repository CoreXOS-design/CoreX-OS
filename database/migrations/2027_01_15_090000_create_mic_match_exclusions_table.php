<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-520 / mic-work-tab.md 13.12: "Not the same property". One row per split an agent made from the "Listed by N agencies" popover. It is the memory the
 * matchers read (this advert is never auto-joined to that file again, and the new file is never auto-merged back) AND the record undo works from
 * (`moves` = every row that changed file). `undone_at` set = the split was undone and the exclusion no longer applies. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mic_match_exclusions')) {
            return;
        }
        Schema::create('mic_match_exclusions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('file_id');          // the property file the advert was split FROM
            $t->unsignedBigInteger('new_file_id');      // the file it was split onto
            $t->unsignedBigInteger('listing_id');
            $t->string('portal_ref', 100)->nullable();
            $t->string('canonical_ref', 150)->nullable();
            $t->string('reason', 40)->default('not_same_property');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('undone_at')->nullable();
            $t->unsignedBigInteger('undone_by')->nullable();
            $t->json('moves')->nullable();
            $t->string('journal', 255)->nullable();
            $t->index(['agency_id', 'file_id', 'undone_at'], 'mme_agency_file_idx');
            $t->index(['agency_id', 'new_file_id', 'undone_at'], 'mme_agency_new_idx');
            $t->index(['agency_id', 'canonical_ref'], 'mme_agency_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_match_exclusions');
    }
};
