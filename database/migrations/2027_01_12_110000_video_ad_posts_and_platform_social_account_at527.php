<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-527 - post a CoreX video ad to the CoreX Facebook page (spec .ai/specs/training-videos.md 9.22). Additive only:
 *  - agent_social_accounts.is_platform: the CoreX page connected by a System Owner (platform = 'facebook'); every agency row stays false. The CoreX page belongs to no
 *    agency, so agency_id becomes NULLABLE (it was NOT NULL; the FK stays, every existing row keeps its agency).
 *  - video_ad_posts: one row per Post now / Schedule of a video ad. Platform records, no agency_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE agent_social_accounts MODIFY agency_id BIGINT UNSIGNED NULL');
        if (! Schema::hasColumn('agent_social_accounts', 'is_platform')) {
            Schema::table('agent_social_accounts', function (Blueprint $t) {
                $t->boolean('is_platform')->default(false)->after('is_active');
            });
        }

        Schema::create('video_ad_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('video_ad_id')->constrained('video_ads')->cascadeOnDelete();
            $t->unsignedBigInteger('social_account_id')->nullable();
            $t->string('format', 8);                       // which of the ad's formats is posted: 9:16 | 1:1 | 16:9
            $t->text('message');                           // the post text as it was when the post was made
            $t->string('status', 12)->default('scheduled'); // scheduled | publishing | published | failed | cancelled
            $t->dateTime('scheduled_for')->nullable();    // UTC wall clock (App\Casts\UtcDateTime)
            $t->dateTime('published_at')->nullable();
            $t->string('platform_post_id', 120)->nullable();
            $t->boolean('dry_run')->default(false);        // true = nothing was sent to Facebook, the call only logged what it would send
            $t->string('failure_reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_ad_posts');
        // agency_id stays nullable on the way down: making it NOT NULL again would fail if a platform row exists.
        if (Schema::hasColumn('agent_social_accounts', 'is_platform')) {
            Schema::table('agent_social_accounts', fn (Blueprint $t) => $t->dropColumn('is_platform'));
        }
    }
};
