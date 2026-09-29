<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency token for DealPipelineService::createDeal() — see the sibling
 * migration on `deals` (add_create_token_to_deals_table) for the full
 * reasoning; same mechanism, same nullable+unique shape, for the DealV2
 * pipeline's own create path (deals-v2/create-form.blade.php and the wizard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->string('create_token', 64)->nullable()->after('reference');
            $table->unique('create_token');
        });
    }

    public function down(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->dropUnique(['create_token']);
            $table->dropColumn('create_token');
        });
    }
};
