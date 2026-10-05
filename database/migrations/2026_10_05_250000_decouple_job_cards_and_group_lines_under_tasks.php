<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job card rebuild, 2026-10-05 — Johan rejected the AT-442 design after
 * testing /rental-job-cards/create and /2 on QA1. Two structural changes:
 *
 * 1. A job card no longer BUILDS a work order. rental_work_order_id becomes
 *    nullable (unique constraint kept — at most one job card per work
 *    order, MySQL's unique index already allows many NULLs). A new nullable
 *    rental_fault_report_id lets a job card link directly to its originating
 *    fault report without a work order existing at all. "No source — created
 *    directly" is now a legitimate, permanent state, not a transient one on
 *    the way to always having a work order.
 *    RentalJobCardController::store() (and its new RentalJobCardService::
 *    createStandalone()) is the ONLY call site affected — RentalWorkOrder-
 *    Controller::store()'s own assignment_type=internal path and
 *    RentalFaultReportController@raiseWorkOrder still call the EXISTING
 *    createForProperty()/createFromFaultReport() unchanged, which still
 *    build a work order deliberately — that is a different, already-tested
 *    feature this rebuild does not touch.
 * 2. Johan's design: "each task has its own PARTS & LABOUR lines
 *    underneath it." rental_job_card_lines gains a nullable
 *    rental_job_card_task_id — null means the line sits in the built-in
 *    "General" group (no task), which is exactly where every existing
 *    line ends up unchanged (additive column, defaults to null).
 *
 * rental_work_order_photos gains the same optional-owner shape
 * rental_work_order_quotes already has (rental_job_card_id nullable
 * alongside an now-nullable rental_work_order_id) — a job card with no
 * linked work order still needs somewhere to put its own photos
 * (RentalJobCardService::storePhoto()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_work_order_id')->nullable()->change();
            $table->foreignId('rental_fault_report_id')->nullable()
                ->after('rental_work_order_id')
                ->constrained(indexName: 'rjc_fault_report_fk')->nullOnDelete();
        });

        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->foreignId('rental_job_card_task_id')->nullable()
                ->after('rental_job_card_id')
                ->constrained(indexName: 'rjcl_task_fk')->nullOnDelete();
        });

        Schema::table('rental_work_order_photos', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_work_order_id')->nullable()->change();
            $table->foreignId('rental_job_card_id')->nullable()
                ->after('rental_work_order_id')
                ->constrained(indexName: 'rwop_job_card_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_job_card_id');
            $table->unsignedBigInteger('rental_work_order_id')->nullable(false)->change();
        });

        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_job_card_task_id');
        });

        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_fault_report_id');
            $table->unsignedBigInteger('rental_work_order_id')->nullable(false)->change();
        });
    }
};
