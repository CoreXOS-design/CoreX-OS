<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job card line's catalogue CODE, copied from the catalogue item at
 * add-time — same snapshot discipline as the line's existing `type`/
 * `description`/`unit` columns (rental_job_card_lines' own migration
 * docblock): a later rename/archive of the catalogue item never changes a
 * historical line. Null for a free-text line (nothing in the catalogue to
 * snapshot a code from). Print/quote layouts show "code — description".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('rental_catalogue_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
