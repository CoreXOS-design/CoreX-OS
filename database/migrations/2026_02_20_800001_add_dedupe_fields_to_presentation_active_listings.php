<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->string('external_key', 255)->nullable()->after('extraction_method');
            $table->char('fingerprint', 64)->nullable()->after('external_key');
            $table->timestamp('first_seen_at')->nullable()->after('fingerprint');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            $table->boolean('is_active')->default(true)->after('last_seen_at');
            $table->unsignedTinyInteger('source_rank')->default(50)->after('is_active');

            $table->index('external_key');
            $table->index('fingerprint');
            $table->index(['presentation_id', 'external_key']);
            $table->index(['presentation_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->dropIndex(['presentation_id', 'is_active']);
            $table->dropIndex(['presentation_id', 'external_key']);
            $table->dropIndex(['fingerprint']);
            $table->dropIndex(['external_key']);
            $table->dropColumn([
                'external_key', 'fingerprint',
                'first_seen_at', 'last_seen_at',
                'is_active', 'source_rank',
            ]);
        });
    }
};
