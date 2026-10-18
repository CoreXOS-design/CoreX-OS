<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every row now has a `code`/`description` (backfilled by
 * 2026_10_05_270100 immediately before this one). `name` is fully
 * superseded — no shortcut dead column left behind, same discipline as
 * the type/unit Pastel enhancement's own 2026_10_05_240400.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->string('code', 50)->nullable(false)->change();
            $table->string('description', 500)->nullable(false)->change();
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->string('name', 191)->nullable()->after('description');
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            DB::statement('UPDATE rental_catalogue_items SET name = description');
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->change();
            $table->string('description', 500)->nullable()->change();
        });
    }
};
