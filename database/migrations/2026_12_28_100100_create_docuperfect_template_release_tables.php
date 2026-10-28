<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/esign-template-transfer.md §13.2 — the release record (one per release of a template to an agency; version 1, 2, 3 ...)
 * and the insert-only release log. Neither is ever hard-deleted. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('docuperfect_template_releases')) {
            Schema::create('docuperfect_template_releases', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('source_template_id');
                $t->unsignedBigInteger('template_id');                       // the agency's copy
                $t->unsignedBigInteger('agency_id');                         // the target agency
                $t->unsignedInteger('version');                              // 1, 2, 3 per source template + agency
                $t->string('state', 20)->default('awaiting_approval');       // awaiting_approval | live | declined | withdrawn | superseded
                $t->string('readiness_verdict', 24)->nullable();
                $t->json('readiness_snapshot')->nullable();
                $t->char('source_content_sha', 64)->nullable();
                $t->char('package_sha256', 64)->nullable();
                $t->json('lease_link')->nullable();
                $t->unsignedBigInteger('released_by')->nullable();
                $t->timestamp('released_at')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->unsignedBigInteger('previous_release_id')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->unique(['source_template_id', 'agency_id', 'version'], 'dtr_source_agency_version_uq');
                $t->index(['agency_id', 'state'], 'dtr_agency_state_idx');
                $t->index('template_id', 'dtr_template_idx');
            });
        }

        if (! Schema::hasTable('docuperfect_template_release_events')) {
            Schema::create('docuperfect_template_release_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('release_id')->nullable();
                $t->unsignedBigInteger('template_id')->nullable();
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->string('event', 30);
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('actor_name')->nullable();
                $t->unsignedBigInteger('on_behalf_of_user_id')->nullable();
                $t->text('note')->nullable();
                $t->json('detail')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->index(['release_id', 'created_at'], 'dtre_release_idx');
                $t->index(['agency_id', 'created_at'], 'dtre_agency_idx');
                $t->index(['template_id', 'event'], 'dtre_template_event_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_template_release_events');
        Schema::dropIfExists('docuperfect_template_releases');
    }
};
