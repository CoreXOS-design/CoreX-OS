<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §18.7 — a lease's notice terms only reach a tenant or owner once an agent has confirmed them
 * against the signed lease. Columns: when and by whom. Existing rows an agent typed or saved themselves (source
 * captured / edited) count as confirmed; anything else (agency default, carried forward) stays unconfirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lease_agreement_terms')) {
            return;
        }

        Schema::table('lease_agreement_terms', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_agreement_terms', 'notice_terms_confirmed_at')) {
                $table->timestamp('notice_terms_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('lease_agreement_terms', 'notice_terms_confirmed_by')) {
                $table->unsignedBigInteger('notice_terms_confirmed_by')->nullable();
            }
        });

        DB::table('lease_agreement_terms')
            ->whereNull('notice_terms_confirmed_at')
            ->whereIn('notice_terms_source', ['captured', 'edited'])
            ->update(['notice_terms_confirmed_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('lease_agreement_terms')) {
            return;
        }

        Schema::table('lease_agreement_terms', function (Blueprint $table) {
            foreach (['notice_terms_confirmed_by', 'notice_terms_confirmed_at'] as $col) {
                if (Schema::hasColumn('lease_agreement_terms', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
