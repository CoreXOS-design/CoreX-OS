<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer agent articles — a cover image, an optional connecting link, and
 * hashtags/topics. Read time + word count are computed from the body (not
 * stored). Spec: .ai/specs/testimonials.md (agent linkage).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agent_articles')) {
            return;
        }

        Schema::table('agent_articles', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_articles', 'cover_image_path')) {
                $table->string('cover_image_path')->nullable()->after('excerpt');
            }
            if (!Schema::hasColumn('agent_articles', 'link_url')) {
                $table->string('link_url')->nullable()->after('body');
            }
            if (!Schema::hasColumn('agent_articles', 'tags')) {
                $table->string('tags', 500)->nullable()->after('link_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_articles')) {
            return;
        }

        Schema::table('agent_articles', function (Blueprint $table) {
            foreach (['cover_image_path', 'link_url', 'tags'] as $col) {
                if (Schema::hasColumn('agent_articles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
