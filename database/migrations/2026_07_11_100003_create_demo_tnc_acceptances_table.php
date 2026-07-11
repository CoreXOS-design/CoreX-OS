<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-230 Demo Access Control — clickwrap acceptance records.
 *
 * Spec: .ai/specs/demo-access-control.md §4.3
 *
 * One row per (grant, T&C version). The UNIQUE index is what makes acceptance
 * IDEMPOTENT: a double-submit (double-click, two tabs, a retried request) is one
 * row, not two.
 *
 * Points at a demo_tnc_versions row that can never be edited (§4.1) — which is
 * what makes this an evidence record rather than a checkbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_tnc_acceptances')) {
            return;
        }

        Schema::create('demo_tnc_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('demo_access_grant_id');
            $table->unsignedBigInteger('demo_tnc_version_id');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->unique(
                ['demo_access_grant_id', 'demo_tnc_version_id'],
                'demo_tnc_accept_unq'
            );

            $table->foreign('demo_access_grant_id', 'demo_tnc_accept_grant_fk')
                  ->references('id')->on('demo_access_grants')
                  ->cascadeOnUpdate();

            $table->foreign('demo_tnc_version_id', 'demo_tnc_accept_version_fk')
                  ->references('id')->on('demo_tnc_versions')
                  ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_tnc_acceptances');
    }
};
