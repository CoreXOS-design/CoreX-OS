<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 "Admin decides" group (spec agency-feature-rollout.md §18): a feature in that group switches on for
 * the agency's admins only, and the admin later decides who else gets it. Whoever is given it — at any time,
 * through the rollout pop-up, the Feature rollout page or the Role Manager — gets the "New in CoreX" pop-up
 * for it once. announced_at records that per person per feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rollout_feature_users', function (Blueprint $table) {
            $table->timestamp('announced_at')->nullable()->after('early_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('rollout_feature_users', function (Blueprint $table) {
            $table->dropColumn('announced_at');
        });
    }
};
