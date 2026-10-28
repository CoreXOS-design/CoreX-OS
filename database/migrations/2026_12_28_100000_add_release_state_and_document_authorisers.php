<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/esign-template-transfer.md §13.2 — the e-sign document workshop.
 *
 *  - docuperfect_templates.release_status      null = an ordinary template exactly as before (every existing row stays null, no backfill);
 *                                              awaiting_approval | declined = a released copy that is NOT live; live = an approved release;
 *                                              superseded = an older approved release replaced by a newer version (it is also archived).
 *  - docuperfect_templates.workshop_verified_* the owner's "verified" stamp and the content hash it was given for (a later edit makes it stale).
 *  - agencies.document_authoriser_user_ids / _roles   "Who may authorise documents" — the people and roles an agency names to approve a released template.
 *
 * Additive and idempotent; down() drops only what up() added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('docuperfect_templates')) {
            Schema::table('docuperfect_templates', function (Blueprint $t) {
                if (! Schema::hasColumn('docuperfect_templates', 'release_status')) {
                    $t->string('release_status', 20)->nullable()->index();
                }
                if (! Schema::hasColumn('docuperfect_templates', 'workshop_verified_at')) {
                    $t->timestamp('workshop_verified_at')->nullable();
                }
                if (! Schema::hasColumn('docuperfect_templates', 'workshop_verified_by')) {
                    $t->unsignedBigInteger('workshop_verified_by')->nullable();
                }
                if (! Schema::hasColumn('docuperfect_templates', 'workshop_verified_sha')) {
                    $t->char('workshop_verified_sha', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable('agencies')) {
            Schema::table('agencies', function (Blueprint $t) {
                if (! Schema::hasColumn('agencies', 'document_authoriser_user_ids')) {
                    $t->json('document_authoriser_user_ids')->nullable();
                }
                if (! Schema::hasColumn('agencies', 'document_authoriser_roles')) {
                    $t->json('document_authoriser_roles')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('docuperfect_templates')) {
            Schema::table('docuperfect_templates', function (Blueprint $t) {
                foreach (['release_status', 'workshop_verified_at', 'workshop_verified_by', 'workshop_verified_sha'] as $c) {
                    if (Schema::hasColumn('docuperfect_templates', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('agencies')) {
            Schema::table('agencies', function (Blueprint $t) {
                foreach (['document_authoriser_user_ids', 'document_authoriser_roles'] as $c) {
                    if (Schema::hasColumn('agencies', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
