<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent public profile (My Portal → Profile): an "about me", personal public
 * social links (shown on the agent's website page — distinct from the OAuth ad
 * accounts), and agent-authored articles.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage) — extends the agent's public
 * website profile surfaced via the Agency Public API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'about_me')) {
                $table->text('about_me')->nullable()->after('website');
            }
            foreach (['facebook', 'instagram', 'linkedin', 'youtube'] as $net) {
                $col = "website_social_{$net}";
                if (!Schema::hasColumn('users', $col)) {
                    $table->string($col)->nullable();
                }
            }
        });

        if (!Schema::hasTable('agent_articles')) {
            Schema::create('agent_articles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('user_id'); // the authoring agent
                $table->string('title');
                $table->string('slug')->nullable();
                $table->string('excerpt', 500)->nullable();
                $table->longText('body')->nullable();
                $table->boolean('is_published')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['agency_id', 'user_id']);
                $table->index(['user_id', 'is_published']);

                $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_articles');

        Schema::table('users', function (Blueprint $table) {
            foreach (['about_me', 'website_social_facebook', 'website_social_instagram', 'website_social_linkedin', 'website_social_youtube'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
