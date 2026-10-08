<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §21 — the portal's duplicate-submission ledger. One row per state-changing portal
 * request (a fault report, a decision, an answer): the browser sends one key per form, the server remembers the answer it
 * gave, and a second press of the same button replays that answer instead of doing the work again (two faults were
 * created by one double tap on 8 Oct 2026). A technical ledger, not business data: rows are never deleted — a failed
 * attempt is flagged `failed` and the same key may try again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portal_submissions')) {
            return;
        }

        Schema::create('portal_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('client_user_id');
            // sha1 of "METHOD path" — the same key on a different screen/record is a different submission.
            $table->char('request_hash', 40);
            // The key the page sent, or "auto:<sha1 of the request content>" when the client sent none.
            $table->string('submission_key', 100);
            $table->string('status', 12)->default('processing'); // processing | done | failed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->string('response_type', 100)->nullable();
            $table->timestamps();

            $table->unique(['client_user_id', 'request_hash', 'submission_key'], 'portal_submissions_unique_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_submissions');
    }
};
