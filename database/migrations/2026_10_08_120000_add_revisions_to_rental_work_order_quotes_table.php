<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.21 (2026-10-05, Johan) — a job card's
 * quote is editable after it is sent and can be RE-SENT; a re-send creates
 * the next revision and supersedes the previous one (kept, never deleted).
 *
 * revision          — 1 for the first quote sent from a card, 2, 3… for each
 *                     re-send. Outside-supplier quotes stay at the default 1
 *                     (revisions only mean something per job card).
 * superseded_at     — stamped on the previous revision when the next is sent.
 * content_signature — hash of what the quote showed (title, live tasks, live
 *                     lines), so the card can tell "changed since sent".
 *
 * Existing cards that were already sent more than once (the old behaviour
 * recorded a fresh quote per send and just flipped is_selected) are
 * back-filled 1..n in send order, every revision but the last superseded —
 * historical rows only gain labels, nothing is deleted or re-selected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_work_order_quotes', 'revision')) {
            Schema::table('rental_work_order_quotes', function (Blueprint $table) {
                $table->unsignedSmallInteger('revision')->default(1)->after('rental_job_card_id');
                $table->timestamp('superseded_at')->nullable()->after('is_selected');
                $table->string('content_signature', 64)->nullable()->after('superseded_at');
            });
        }

        $this->backfill();
    }

    /** Idempotent — re-labels each card's quotes 1..n in send order, all but the last superseded. */
    public function backfill(): void
    {

        $perCard = DB::table('rental_work_order_quotes')
            ->whereNotNull('rental_job_card_id')
            ->orderBy('rental_job_card_id')->orderBy('id')
            ->get(['id', 'rental_job_card_id', 'created_at'])
            ->groupBy('rental_job_card_id');

        foreach ($perCard as $quotes) {
            $quotes = $quotes->values();
            foreach ($quotes as $i => $quote) {
                $next = $quotes[$i + 1] ?? null;
                DB::table('rental_work_order_quotes')->where('id', $quote->id)->update([
                    'revision' => $i + 1,
                    'superseded_at' => $next ? ($next->created_at ?? now()) : null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('rental_work_order_quotes', function (Blueprint $table) {
            $table->dropColumn(['revision', 'superseded_at', 'content_signature']);
        });
    }
};
