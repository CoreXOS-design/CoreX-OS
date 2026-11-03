<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-520 step 4.3 (mic-work-tab.md 11.3): one crawl record per extension run (session) and portal, with the suburbs it covered. Derived from cc3's
 * crawl columns on prospecting_searches. Additive; nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mic_crawls')) {
            Schema::create('mic_crawls', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('session_id', 40);
                $t->string('portal', 4);
                $t->string('listing_type', 10)->default('sale');
                $t->text('search_url')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->unsignedSmallInteger('pages_read')->default(0);
                $t->unsignedSmallInteger('pages_total')->nullable();
                $t->unsignedInteger('adverts_seen')->default(0);
                $t->boolean('is_complete')->default(false);
                $t->boolean('is_filtered')->default(false);
                $t->unsignedInteger('gone_count')->default(0);
                $t->unsignedInteger('probably_sold_count')->default(0);
                $t->timestamp('gone_applied_at')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'session_id'], 'uq_mic_crawls_session');
                $t->index(['agency_id', 'portal', 'is_complete', 'finished_at'], 'idx_mic_crawls_lookup');
            });
        }
        if (! Schema::hasTable('mic_crawl_suburbs')) {
            Schema::create('mic_crawl_suburbs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('mic_crawl_id');
                $t->unsignedBigInteger('agency_id');
                $t->string('portal', 4);
                $t->unsignedBigInteger('suburb_id')->nullable();
                $t->string('suburb_name', 120)->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'portal', 'suburb_id'], 'idx_mic_crawl_suburbs_lookup');
                $t->index('mic_crawl_id', 'idx_mic_crawl_suburbs_crawl');
                $t->foreign('mic_crawl_id')->references('id')->on('mic_crawls')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_crawl_suburbs');
        Schema::dropIfExists('mic_crawls');
    }
};
