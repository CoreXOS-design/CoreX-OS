<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Meeting ID and passcode that go with a webinar's joining link.
 *
 * Spec: .ai/specs/webinar-registration.md §4.4
 *
 * ══ WHY THREE COLUMNS AND NOT ONE ══
 *
 * These are NOT derivable from `join_url`, and anyone tempted to parse them out of it
 * will ship a broken mail. A Zoom link carries an ENCODED `pwd` token, while the
 * passcode a human types into the Zoom app is a separate short string:
 *
 *     link      …/j/82437708791?pwd=qYHFilPvbAdY4EVMBurh9XYun4Rcga.1
 *     passcode  0ABcMc
 *
 * One is not the other and neither yields the other. A registrant who joins by Meeting
 * ID in the desktop app — which is what people do when the browser link misbehaves, on
 * the morning of the webinar, with no time to ask — needs the second one. Storing only
 * the URL means that person cannot get in.
 *
 * ══ STORED VERBATIM ══
 *
 * string(100), nullable, no normalisation anywhere in the stack:
 *
 *   - The Meeting ID keeps its internal spaces ("824 3770 8791"). That is how Zoom
 *     displays it and how a person reads it aloud; collapsing the spaces makes it
 *     harder to type, not tidier.
 *   - The passcode is CASE-SENSITIVE. "0ABcMc" is not "0abcmc" and is not "0ABCMC".
 *     Upper-casing it for looks would hand every registrant a code that fails.
 *
 * NULL means "not set", and the mails omit the line entirely rather than printing an
 * empty label. Every existing row is NULL, so no webinar already out there changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webinars')) {
            return;
        }

        Schema::table('webinars', function (Blueprint $table) {
            if (! Schema::hasColumn('webinars', 'join_meeting_id')) {
                $table->string('join_meeting_id', 100)->nullable()->after('join_url');
            }

            if (! Schema::hasColumn('webinars', 'join_passcode')) {
                $table->string('join_passcode', 100)->nullable()->after('join_meeting_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webinars')) {
            return;
        }

        Schema::table('webinars', function (Blueprint $table) {
            foreach (['join_passcode', 'join_meeting_id'] as $column) {
                if (Schema::hasColumn('webinars', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
