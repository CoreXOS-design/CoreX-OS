<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-383 — demo_access_grants.expiry_hours becomes NULLABLE.
 *
 * Spec: .ai/specs/webinar-registration.md §5.2 (decision D8)
 *
 * ══ NULL IS NOT "MISSING". NULL IS A MODE. ══
 *
 * A demo grant now runs on one of two clocks, and this column says which:
 *
 *   expiry_hours SET   → ROLLING. expires_at is NULL until first login, then
 *                        first_login_at + expiry_hours. The original behaviour,
 *                        unchanged, and still what the admin "Issue demo access"
 *                        form produces.
 *
 *   expiry_hours NULL  → FIXED DEADLINE. expires_at was written AT ISSUE and is
 *                        absolute. Nothing moves it — not first login, not a
 *                        second login, not re-issuing.
 *
 * Webinar registrations (webinar_registration.md) need the second: Johan's rule is
 * that a webinar cohort's demo access ends on a set date and "anyone that doesn't
 * use the login just loses access". A rolling clock cannot express that — a grant
 * that is never used never starts counting, so it would stay live forever.
 *
 * ══ WHY NULLABILITY, AND NOT AN `expiry_mode` ENUM ══
 *
 * An enum would sit alongside an expiry_hours that is meaningless in one of its two
 * states — the same fact stored twice, free to disagree. Nullability makes the two
 * modes mutually exclusive by construction: there is exactly one column to read and
 * no combination of values that means nothing. DemoAccessService::issue() throws if
 * a caller passes both an expires_at and an expiry_hours, so a two-clock grant
 * cannot be created even by mistake.
 *
 * ══ SAFETY ══
 *
 * NOT NULL → NULL is a WIDENING change: every existing row keeps its value and every
 * existing read keeps working. Nothing is backfilled and nothing is lost. The down()
 * cannot be a plain narrowing — any fixed-deadline grant issued in the meantime has a
 * NULL here — so it backfills those rows from their own deadline first (see below).
 *
 * Requires doctrine/dbal on some stacks for ->change(); Laravel 11+ handles it
 * natively.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demo_access_grants')) {
            return;
        }

        Schema::table('demo_access_grants', function (Blueprint $table) {
            $table->unsignedInteger('expiry_hours')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('demo_access_grants')) {
            return;
        }

        // Fixed-deadline grants have no hours. Narrowing the column would fail on
        // them, so give each one the hours between issue and its own deadline —
        // arithmetically true at the moment of issue, and the closest honest value
        // the rolling model can hold. Floor of 1: the column is unsigned and a
        // same-day deadline would otherwise round to 0.
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE demo_access_grants
                SET expiry_hours = GREATEST(1, TIMESTAMPDIFF(HOUR, created_at, expires_at))
              WHERE expiry_hours IS NULL
                AND expires_at IS NOT NULL'
        );

        // Belt and braces: anything still NULL (no deadline either) takes the
        // documented default rather than blocking the rollback.
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE demo_access_grants SET expiry_hours = 72 WHERE expiry_hours IS NULL'
        );

        Schema::table('demo_access_grants', function (Blueprint $table) {
            $table->unsignedInteger('expiry_hours')->nullable(false)->change();
        });
    }
};
