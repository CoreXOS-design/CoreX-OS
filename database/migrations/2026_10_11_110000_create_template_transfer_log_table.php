<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/esign-template-transfer.md §7 — insert-only audit of every e-sign
 * template package export / import (who, when, package checksum, target agency,
 * outcome). Platform-level (owner) record: deliberately NOT agency-scoped, and
 * no FKs to users/agencies so the record survives their removal (names are
 * snapshotted). Failed / rejected imports are written too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('template_transfer_log')) {
            return;
        }

        Schema::create('template_transfer_log', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10);                       // export | import
            $table->string('outcome', 10);                         // success | rejected | failed
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name', 255)->nullable();
            $table->unsignedBigInteger('source_agency_id')->nullable();   // export: the template's agency
            $table->unsignedBigInteger('target_agency_id')->nullable();   // import: the chosen agency
            $table->string('target_agency_name', 255)->nullable();
            $table->string('template_name', 255)->nullable();
            $table->unsignedBigInteger('template_id')->nullable();        // export: source row; import: created row (this environment)
            $table->char('package_checksum', 64)->nullable();
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->string('source_label', 255)->nullable();
            $table->string('name_clash_choice', 20)->nullable();          // new_version | new_copy
            $table->json('warnings')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['direction', 'outcome']);
            $table->index('created_at');
            $table->index('package_checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_transfer_log');
    }
};
