<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-451 round 2 — the live-alert popup asks "anything for this user newer than <time>?" every 15-30 seconds from every open
 * staff tab. The stock Laravel index (notifiable_type, notifiable_id) cannot answer a created_at range, so a user with a large
 * history (user 22 on QA1: 71,646 unread) would be read end to end on every poll. This composite index lets MySQL jump straight
 * to the rows newer than the cursor: (notifiable_type, notifiable_id, created_at).
 */
return new class extends Migration
{
    private const INDEX = 'notifications_feed_index';

    public function up(): void
    {
        if (! Schema::hasTable('notifications') || $this->hasIndex()) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications') && $this->hasIndex()) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }

    private function hasIndex(): bool
    {
        return collect(Schema::getIndexes('notifications'))->contains(fn ($i) => ($i['name'] ?? null) === self::INDEX);
    }
};
