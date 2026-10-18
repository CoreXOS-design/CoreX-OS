<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 9 Oct 2026 (Q2): a work order whose quote the owner declined BEFORE the declined-quote state existed (work order 64) still showed that quote as
 * "Selected" and "What happens next" had no way forward. Bring those rows in line with how a fresh decline is now recorded: the selected quote is marked
 * declined (when + the owner's reason, from the decline itself) and is no longer selected. Idempotent; touches only declined work orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_work_order_quotes') || ! Schema::hasColumn('rental_work_order_quotes', 'declined_at')) {
            return;
        }
        $rows = DB::table('rental_work_orders as w')
            ->join('rental_work_order_quotes as q', 'q.rental_work_order_id', '=', 'w.id')
            ->where('w.owner_approval_status', 'declined')->whereNull('w.deleted_at')
            ->where('q.is_selected', 1)->whereNull('q.deleted_at')
            ->get(['w.id as wo_id', 'w.agency_id', 'q.id as quote_id']);
        foreach ($rows as $r) {
            $approval = DB::table('rental_approvals')->where('rental_work_order_id', $r->wo_id)->where('decision', 'declined')->orderByDesc('id')->first();
            $when = $approval->decided_at ?? $approval->created_at ?? now();
            $reason = trim((string) ($approval->evidence_text ?? ''));
            DB::table('rental_work_order_quotes')->where('id', $r->quote_id)->update([
                'is_selected' => 0, 'declined_at' => $when, 'decline_reason' => $reason !== '' ? $reason : null, 'updated_at' => now(),
            ]);
            DB::table('rental_work_order_updates')->insert([
                'agency_id' => $r->agency_id, 'rental_work_order_id' => $r->wo_id, 'update_type' => 'quote_declined',
                'note' => 'Quote declined by the owner' . ($reason !== '' ? ': ' . $reason : '') . ' (recorded when the declined-quote state was introduced).',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // data repair - nothing to undo
    }
};
