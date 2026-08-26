<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webinars (AT-383) — the registration links RR Technologies publishes on the
 * CoreX marketing website.
 *
 * Spec: .ai/specs/webinar-registration.md §3.1
 *
 * System-owner sales tooling, NOT tenant data — no agency_id, no BelongsToAgency.
 * A webinar belongs to RR Technologies' sales process, exactly like the demo access
 * grants it issues (demo-access-control.md §2). Lives on PRIMARY.
 *
 * TWO COLUMNS CARRY THE WHOLE POLICY OF A WEBINAR:
 *
 *   access_ends_days_after — demo access for everyone who registers through this
 *       link dies at end-of-day this many days AFTER the webinar. Johan's rule:
 *       "the demo runs until that date or even like 3 days after, and anyone that
 *       doesn't use the login just loses access." That is an ABSOLUTE deadline
 *       shared by the whole cohort — deliberately NOT the per-user rolling clock
 *       the demo grants normally use. See spec §5 for the three lifecycle changes
 *       that make a never-used grant expire on time.
 *       0 is legal and means "access ends at the end of the webinar day".
 *
 *   reminder_hours_before — how far ahead the single reminder email goes out.
 *
 * There is NO `registration_open` flag. Registration is open while the webinar is
 * un-archived and starts_at is still in the future — derived, so there is no switch
 * anyone can forget to flip, and a finished webinar cannot keep minting free demo
 * logins forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webinars')) {
            return;
        }

        Schema::create('webinars', function (Blueprint $table) {
            $table->id();

            // The public URL segment the marketing website registers against.
            $table->string('slug')->unique();

            $table->string('title');
            $table->text('description')->nullable();

            $table->dateTime('starts_at');
            $table->unsignedInteger('duration_minutes')->nullable();

            // Zoom / Teams / Meet. Earned by registering — never returned by the
            // public GET endpoint, only mailed to someone who signed up.
            $table->string('join_url')->nullable();

            $table->unsignedInteger('access_ends_days_after')->default(3);
            $table->unsignedInteger('reminder_hours_before')->default(24);

            $table->unsignedBigInteger('created_by_user_id');

            // "Delete" archives. The row is never removed (non-negotiable #1) —
            // registrations hang off it and are the sales record.
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(['archived_at', 'starts_at'], 'webinars_open_idx');

            $table->foreign('created_by_user_id', 'webinars_creator_fk')
                  ->references('id')->on('users')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinars');
    }
};
