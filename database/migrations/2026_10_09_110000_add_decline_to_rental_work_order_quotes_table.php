<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 9 Oct 2026 (Q2): when the owner declines a work order's quote, THAT quote is marked declined (when, and the reason) and is no longer the
 * selected one - so the work order is not left pointing at a dead quote, and the agent's next step (capture/choose another quote and put it to the
 * owner, or cancel the work order) is clear. Soft state only: nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_work_order_quotes') || Schema::hasColumn('rental_work_order_quotes', 'declined_at')) {
            return;
        }
        Schema::table('rental_work_order_quotes', function (Blueprint $table) {
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_work_order_quotes') && Schema::hasColumn('rental_work_order_quotes', 'declined_at')) {
            Schema::table('rental_work_order_quotes', function (Blueprint $table) {
                $table->dropColumn(['declined_at', 'decline_reason']);
            });
        }
    }
};
