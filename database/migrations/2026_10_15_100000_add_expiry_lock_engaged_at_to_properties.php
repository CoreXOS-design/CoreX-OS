<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-448 (audit fix) - `properties.expiry_lock_engaged_at`: "this P24-origin listing
 * lived on the market inside CoreX, so the expiry lock applies to it".
 *
 * Spec: .ai/specs/at448-property-expiry.md (D4, section 2.3).
 *
 * The expiry lock exempts untouched Imported Stock (its dates are import artefacts and
 * setting an expiry is the AT-422 takeover). Imported Stock is derived from the CURRENT
 * status, so a live P24-origin listing that is withdrawn / sold / expired would otherwise
 * fall into the exempt bucket. Johan's ruling: such listings MUST STAY on the Imported
 * Stock page - so Imported Stock membership (imported_released_at) is NOT touched; this
 * separate stamp records that the lock now applies. Stamped by PropertyObserver::saving()
 * (a signed-in user takes a P24-origin listing off the market) and by the mandates:expire
 * sweep. Exemption = isImportedStock() AND this column IS NULL.
 *
 * Nullable, no default, no index (never filtered on): every existing row is "not engaged".
 * Guarded so a re-run after a partial failure is a no-op; reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('properties') || Schema::hasColumn('properties', 'expiry_lock_engaged_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('expiry_lock_engaged_at')->nullable()->after('expiry_date_changed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('properties') || ! Schema::hasColumn('properties', 'expiry_lock_engaged_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('expiry_lock_engaged_at');
        });
    }
};
