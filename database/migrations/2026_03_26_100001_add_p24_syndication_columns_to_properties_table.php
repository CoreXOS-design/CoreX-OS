<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Columns and table already created by initial migration run.
        // This file is kept for git history and future deployments.
        if (!Schema::hasColumn('properties', 'p24_syndication_enabled')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->boolean('p24_syndication_enabled')->default(false)->after('rental_price_type');
                $table->string('p24_syndication_status')->nullable()->after('p24_syndication_enabled');
                $table->string('p24_ref')->nullable()->after('p24_syndication_status');
                $table->timestamp('p24_last_submitted_at')->nullable()->after('p24_ref');
                $table->timestamp('p24_activated_at')->nullable()->after('p24_last_submitted_at');
                $table->text('p24_last_error')->nullable()->after('p24_activated_at');
                $table->timestamp('p24_images_last_synced_at')->nullable()->after('p24_last_error');
                $table->timestamp('p24_listing_last_synced_at')->nullable()->after('p24_images_last_synced_at');
            });
        }

        if (!Schema::hasTable('p24_syndication_logs')) {
            Schema::create('p24_syndication_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->string('action', 50);
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->smallInteger('status_code')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_syndication_logs');
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'p24_syndication_enabled', 'p24_syndication_status', 'p24_ref',
                'p24_last_submitted_at', 'p24_activated_at', 'p24_last_error',
                'p24_images_last_synced_at', 'p24_listing_last_synced_at',
            ]);
        });
    }
};
