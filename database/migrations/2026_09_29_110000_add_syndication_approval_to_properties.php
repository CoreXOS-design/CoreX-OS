<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Syndication Approval Gate (layer 3) — the durable approval fact.
 * Spec: .ai/specs/syndication-approval-gate.md §4.1
 *
 * Deliberately mirrors `compliance_snapshot_at` in shape: approval "holds
 * forever" (spec D2), so the gate is a NULL check rather than a status
 * machine and cannot drift out of date.
 *
 * No backfill here. Every existing property ships NULL, which is inert
 * because the agency switch defaults to OFF. The grandfathering of stock
 * that is already published happens per-agency at switch-on time, via
 * GrandfatherSyndicatedStockJob (spec §4.4 / D8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('syndication_approved_at')->nullable()->index()->after('compliance_snapshot_data');
            $table->unsignedBigInteger('syndication_approved_by_user_id')->nullable()->after('syndication_approved_at');

            $table->foreign('syndication_approved_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['syndication_approved_by_user_id']);
            $table->dropColumn(['syndication_approved_at', 'syndication_approved_by_user_id']);
        });
    }
};
