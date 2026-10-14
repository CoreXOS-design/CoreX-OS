<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/property-upload-wizard.md (Cape walk 14 Oct) - one draft per wizard session. The wizard stamps every step-1
 * save with the token it generated when the page opened, so a second press of "Continue" (or a retry after a slow reply)
 * finds the draft the first press made instead of creating another. Nullable: only wizard drafts carry one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('properties', 'wizard_token')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->string('wizard_token', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('properties', 'wizard_token')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('wizard_token');
            });
        }
    }
};
