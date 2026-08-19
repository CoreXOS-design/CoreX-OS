<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC — property row comments (spec .ai/specs/mic-property-row-comments.md).
 *
 * Agency-wide, cross-agent visibility of comments on a specific tracked
 * property — keyed to tracked_property_id (the enduring spine), not to any
 * one prospecting_listing or claim, so a comment survives relisting, claim
 * churn, and portal-ref rotation.
 *
 * Multi-tenancy: agency_id present, BelongsToAgency on the model.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_property_comments', function (Blueprint $table) {
            $table->comment('Agency-wide comments on a tracked property, surfaced via the MIC Work-tab row comment chip.');

            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('tracked_property_id')->constrained('tracked_properties')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');
            $table->timestamp('edited_at')->nullable()
                  ->comment('Set when the author edits their comment; null if never edited.');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'tracked_property_id', 'deleted_at'], 'idx_tpc_agency_tp_deleted');
            $table->index(['agency_id', 'user_id'], 'idx_tpc_agency_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_property_comments');
    }
};
