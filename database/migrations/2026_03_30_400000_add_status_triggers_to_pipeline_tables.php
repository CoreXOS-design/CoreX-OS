<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->string('status_trigger')->nullable()->after('notify_admin');
            $table->string('negative_status_trigger')->nullable()->after('status_trigger');
            $table->string('negative_outcome_label')->nullable()->after('negative_status_trigger');
            $table->boolean('requires_bm_approval')->default(false)->after('negative_outcome_label');
        });

        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->string('status_trigger')->nullable()->after('notify_admin');
            $table->string('negative_status_trigger')->nullable()->after('status_trigger');
            $table->string('negative_outcome_label')->nullable()->after('negative_status_trigger');
            $table->boolean('requires_bm_approval')->default(false)->after('negative_outcome_label');
            $table->enum('approval_status', ['not_required', 'pending', 'approved', 'rejected'])->default('not_required')->after('requires_bm_approval');
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->after('approval_status');
            $table->datetime('approved_at')->nullable()->after('approved_by_id');
            $table->text('approval_notes')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->dropForeign(['approved_by_id']);
            $table->dropColumn([
                'status_trigger', 'negative_status_trigger', 'negative_outcome_label',
                'requires_bm_approval', 'approval_status', 'approved_by_id', 'approved_at', 'approval_notes',
            ]);
        });

        Schema::table('deal_pipeline_steps', function (Blueprint $table) {
            $table->dropColumn([
                'status_trigger', 'negative_status_trigger', 'negative_outcome_label', 'requires_bm_approval',
            ]);
        });
    }
};
