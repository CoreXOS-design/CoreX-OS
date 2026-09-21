<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 conversion — Johan: "current rental stock cannot be dumped, it
 * needs to be converted." Before any property's spaces_json/features_json
 * is rewritten into the new {spaces:[...], features:{...}} shape, its raw
 * old value is copied here, untouched, indefinitely recoverable. No hard
 * deletes — this is the "keep the original recoverable" requirement, not
 * an audit log entry that could itself be pruned later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('spaces_json_legacy_backup')->nullable()->after('spaces_json');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('spaces_json_legacy_backup');
        });
    }
};
