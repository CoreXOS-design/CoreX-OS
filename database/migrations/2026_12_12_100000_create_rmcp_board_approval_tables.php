<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RMCP board approval letter (.ai/specs/compliance.md "RMCP board approval letter").
 *
 *  - agency_board_members: the agency's board / senior management list (name, title, email, optional linked user).
 *  - rmcp_board_letters: one row per SEND of the resolution for a version ("round"). A letter's wording and the RMCP fingerprint
 *    are frozen at send; a changed draft means a new round, never an edit of the old one.
 *  - rmcp_board_signatures: one row per member per letter - the secure link token, status dates and the drawn/typed signature.
 *    A signed row is never changed again.
 *  - rmcp_board_audit_log: sent / viewed / signed / completed / approved, append-only.
 *  - rmcp_versions.board_letter_id: the signed letter the version was approved with (the Approval block fills from it).
 *
 * Soft deletes only; no row is ever hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_board_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->string('name', 150);
            $t->string('title', 150);
            $t->string('email', 190);
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('rmcp_board_letters', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('rmcp_version_id')->index();
            $t->unsignedInteger('round');
            $t->string('status', 20)->default('sent'); // sent | complete | cancelled | superseded
            $t->date('effective_from');
            $t->date('next_review_due')->nullable();
            $t->char('content_hash', 64);
            $t->longText('letter_html');
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('signed_pdf_path', 500)->nullable();
            $t->char('signed_pdf_sha256', 64)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['rmcp_version_id', 'round']);
        });

        Schema::create('rmcp_board_signatures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('letter_id')->index();
            $t->unsignedBigInteger('board_member_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('member_name', 150);
            $t->string('member_title', 150);
            $t->string('member_email', 190);
            $t->string('token', 64)->unique();
            $t->string('status', 20)->default('pending'); // pending | sent | viewed | signed
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedInteger('send_count')->default(0);
            $t->string('last_send_error', 300)->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('signature_type', 10)->nullable(); // drawn | typed
            $t->string('signature_path', 500)->nullable();
            $t->string('typed_name', 200)->nullable();
            $t->string('signed_via', 10)->nullable(); // link | in_app
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('rmcp_board_audit_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('rmcp_version_id')->index();
            $t->unsignedBigInteger('letter_id')->nullable()->index();
            $t->unsignedBigInteger('signature_id')->nullable();
            $t->string('event_type', 40);
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_label', 190)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::table('rmcp_versions', function (Blueprint $t) {
            $t->unsignedBigInteger('board_letter_id')->nullable()->after('board_approval_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('rmcp_versions', function (Blueprint $t) {
            $t->dropColumn('board_letter_id');
        });
        Schema::dropIfExists('rmcp_board_audit_log');
        Schema::dropIfExists('rmcp_board_signatures');
        Schema::dropIfExists('rmcp_board_letters');
        Schema::dropIfExists('agency_board_members');
    }
};
