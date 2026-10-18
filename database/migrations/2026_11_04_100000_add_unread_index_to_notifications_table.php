<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The alert bell asks "this user's unread alerts, newest first" and "how many (up to 100)" on every poll. With read_at in the index
 * both are answered from the index alone, so a user with a long read history costs no more than one with none
 * (notifications_feed_index = notifiable_type, notifiable_id, created_at cannot skip the read rows).
 */
return new class extends Migration
{
    private const INDEX = 'notifications_unread_index';

    public function up(): void
    {
        if (! Schema::hasTable('notifications') || $this->hasIndex()) {
            return;
        }
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], self::INDEX);
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
