<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several rollout plans (spec agency-feature-rollout.md §22). Each plan is a complete, separate set of
 * groups. Every existing group moves into one plan, "Standard" (the default), so nothing changes for anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_rollout_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->string('agency_type', 16)->default('any'); // sales | rentals | both | any
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_type', 'deleted_at']);
        });

        $planId = DB::table('feature_rollout_plans')->insertGetId([
            'name' => 'Standard', 'agency_type' => 'any', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('feature_rollout_waves', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->nullable()->after('id')->index();
        });
        DB::table('feature_rollout_waves')->update(['plan_id' => $planId]);
        Schema::table('feature_rollout_waves', function (Blueprint $table) {
            $table->foreign('plan_id')->references('id')->on('feature_rollout_plans')->cascadeOnDelete();
        });

        Schema::table('agency_feature_rollouts', function (Blueprint $table) {
            $table->unsignedBigInteger('source_plan_id')->nullable()->after('agency_id');
            $table->foreign('source_plan_id')->references('id')->on('feature_rollout_plans')->nullOnDelete();
        });
        // Running agencies were all started from the one list that existed until now.
        DB::table('agency_feature_rollouts')->update(['source_plan_id' => $planId]);
    }

    public function down(): void
    {
        Schema::table('agency_feature_rollouts', function (Blueprint $table) {
            $table->dropForeign(['source_plan_id']);
            $table->dropColumn('source_plan_id');
        });
        Schema::table('feature_rollout_waves', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
        });
        Schema::dropIfExists('feature_rollout_plans');
    }
};
