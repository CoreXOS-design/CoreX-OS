<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/esign-signing-modes.md §3 (Build step 1). How an agency's signers sign — chosen per agency, fixed per
 * envelope at the moment it is created:
 *   sign_each          (default, today) — the signer signs / initials every place one by one
 *   adopt_then_stamp   — adopt a signature + initial once, then click each place to stamp it
 *   adopt_and_fill_all — adopt once, then one confirmation fills every place that is the signer's own
 * Every existing row defaults to sign_each, so nothing changes for an agency that never opens the setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('docuperfect_esign_settings', 'signing_mode')) {
            Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
                $table->string('signing_mode', 24)->default('sign_each');
            });
        }
        if (! Schema::hasColumn('signature_templates', 'signing_mode')) {
            Schema::table('signature_templates', function (Blueprint $table) {
                $table->string('signing_mode', 24)->default('sign_each');
            });
        }
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn('signing_mode');
        });
        Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
            $table->dropColumn('signing_mode');
        });
    }
};
