<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional registration cut-off for a webinar.
 *
 * Spec: .ai/specs/webinar-registration.md §3.1a
 *
 * Registration used to close only when the webinar STARTED. The team needs to close
 * sign-ups earlier — "register by Friday 17:00" — so the attendee list is final before
 * the day, in time to load into Zoom and brief around. The marketing website's admin
 * console already collects the field and its public page already enforces and displays
 * it; until now the value had nowhere to persist and was silently dropped.
 *
 * ══ NULL IS THE WHOLE COMPATIBILITY STORY ══
 *
 * NULL means "no cut-off": registration stays open until the webinar starts, exactly as
 * it does today. Every existing row is NULL, so nothing is backfilled, no live webinar
 * changes behaviour, and the field is opt-in per webinar.
 *
 * Enforcement stays DERIVED, in Webinar::isOpenForRegistration() — there is deliberately
 * no stored "closed" flag. A flag would need something to flip it (a scheduled job, or a
 * write-on-read), and until that something ran the API would report a webinar as open
 * past its own cut-off. Derived, the cut-off is true the instant the clock passes it,
 * with nothing to run and nothing to forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webinars') || Schema::hasColumn('webinars', 'registration_closes_at')) {
            return;
        }

        Schema::table('webinars', function (Blueprint $table) {
            $table->timestamp('registration_closes_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webinars') || ! Schema::hasColumn('webinars', 'registration_closes_at')) {
            return;
        }

        Schema::table('webinars', function (Blueprint $table) {
            $table->dropColumn('registration_closes_at');
        });
    }
};
