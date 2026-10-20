<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency setting (audit 2026-10-20, L7): the timezone this agency's staff, crews, owners and tenants read times in - an IANA name such as Africa/Johannesburg or
 * Europe/London. NULL (the default for every existing agency) = the application's own timezone, exactly what every agency has used until now, so nothing moves on deploy.
 * No data backfill and no agency id in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'timezone')) {
            return;
        }
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'timezone')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('timezone');
            });
        }
    }
};
