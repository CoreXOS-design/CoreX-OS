<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('pp_syndication_enabled')->default(false)->after('lease_end_date');
            $table->string('pp_syndication_status')->nullable()->after('pp_syndication_enabled');
            $table->string('pp_ref')->nullable()->after('pp_syndication_status');
            $table->string('pp_listing_feed_ref')->nullable()->after('pp_ref');
            $table->timestamp('pp_last_submitted_at')->nullable()->after('pp_listing_feed_ref');
            $table->timestamp('pp_activated_at')->nullable()->after('pp_last_submitted_at');
            $table->integer('pp_exclusive_days')->nullable()->after('pp_activated_at');
            $table->timestamp('pp_delay_until')->nullable()->after('pp_exclusive_days');
            $table->text('pp_last_error')->nullable()->after('pp_delay_until');
            $table->timestamp('pp_images_last_synced_at')->nullable()->after('pp_last_error');
            $table->timestamp('pp_listing_last_synced_at')->nullable()->after('pp_images_last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'pp_syndication_enabled',
                'pp_syndication_status',
                'pp_ref',
                'pp_listing_feed_ref',
                'pp_last_submitted_at',
                'pp_activated_at',
                'pp_exclusive_days',
                'pp_delay_until',
                'pp_last_error',
                'pp_images_last_synced_at',
                'pp_listing_last_synced_at',
            ]);
        });
    }
};
