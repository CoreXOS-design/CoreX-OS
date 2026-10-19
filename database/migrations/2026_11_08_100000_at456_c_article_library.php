<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 Part C — Article Library (.ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.2).
 *
 *  - article_sources: the platform owner's list of RSS links. GLOBAL (not agency-scoped) by design:
 *    it is a platform-owner list, read only by the owner-only /admin/article-library screens and by
 *    the scraper. Seeded here with the five feeds that used to be hard-coded in ArticleScraperService,
 *    so a plain `git pull` + migrate deploy carries them (seeders never run on a pull deploy) and the
 *    presentations' article pool keeps getting the same feeds. Idempotent: unique url + insertOrIgnore.
 *  - library_articles: the short, original-wording briefs the pipeline writes. GLOBAL by design —
 *    read-only to agencies (same documented deviation as ai_usage_events); an agency's "Use this"
 *    makes its own agent_articles copy, which IS agency-scoped.
 *  - agent_articles.library_article_id: marks a copy as made from the library (the "used" count, and
 *    stops the same agent using the same article twice).
 */
return new class extends Migration
{
    /** The five feeds ArticleScraperService used to hard-code. */
    private const SEED_SOURCES = [
        'BusinessTech Property'   => 'https://businesstech.co.za/news/category/property/feed/',
        'Daily Investor Property' => 'https://dailyinvestor.com/category/south-africa/property/feed/',
        'IOL Property'            => 'https://www.iol.co.za/news/south-africa/rss',
        'Property24 Advice'       => 'https://www.property24.com/articles/rss',
        'MyBroadband Property'    => 'https://mybroadband.co.za/news/category/property/feed/',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('article_sources')) {
            Schema::create('article_sources', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('url', 500)->unique();
                $table->string('type', 20)->default('rss');
                // Paused sources are skipped without being archived.
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                // ok | failed
                $table->string('last_status', 10)->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedInteger('last_new_articles')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('library_articles')) {
            Schema::create('library_articles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_id')->nullable()->constrained('article_sources')->nullOnDelete();
                // Credit — kept on the row so it survives a source being renamed or archived.
                $table->string('source_name', 120);
                $table->string('source_url', 2000);
                $table->char('source_url_hash', 64)->unique();
                $table->string('title', 255);
                $table->string('excerpt', 500)->nullable();
                $table->longText('body');
                $table->json('tags_json')->nullable();
                $table->timestamp('source_published_at')->nullable();
                // ready | hidden
                $table->string('status', 10)->default('ready');
                $table->timestamp('generated_at')->nullable();
                $table->decimal('cost_zar', 10, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'source_published_at'], 'idx_libart_status_pub');
                $table->index('source_id', 'idx_libart_source');
            });
        }

        if (! Schema::hasColumn('agent_articles', 'library_article_id')) {
            Schema::table('agent_articles', function (Blueprint $table) {
                $table->foreignId('library_article_id')->nullable()->constrained('library_articles')->nullOnDelete();
                $table->index(['user_id', 'library_article_id'], 'idx_agentart_user_libart');
            });
        }

        $now = now();
        foreach (self::SEED_SOURCES as $name => $url) {
            DB::table('article_sources')->insertOrIgnore([
                'name' => $name, 'url' => $url, 'type' => 'rss', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_articles', 'library_article_id')) {
            Schema::table('agent_articles', function (Blueprint $table) {
                $table->dropForeign(['library_article_id']);
                $table->dropIndex('idx_agentart_user_libart');
                $table->dropColumn('library_article_id');
            });
        }

        Schema::dropIfExists('library_articles');
        Schema::dropIfExists('article_sources');
    }
};
