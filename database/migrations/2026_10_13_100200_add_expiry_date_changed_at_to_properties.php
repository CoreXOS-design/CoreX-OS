<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-448 — when did this listing's expiry date last change?
 *
 * Spec: .ai/specs/at448-property-expiry.md §4.1
 *
 * The expiry lock unlocks on an Extension document uploaded AFTER the last
 * expiry-date change, and locks again the moment a new date is saved — so
 * every extension needs its own paperwork. Stamped by PropertyObserver::saving()
 * whenever `expiry_date` is dirty. Nullable, no default: every existing row is
 * "never changed", so its first unlock needs any Extension document at all.
 * Guarded so a re-run after a partial failure is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('properties') || Schema::hasColumn('properties', 'expiry_date_changed_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('expiry_date_changed_at')->nullable()->after('expiry_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('properties', 'expiry_date_changed_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('expiry_date_changed_at');
        });
    }
};
