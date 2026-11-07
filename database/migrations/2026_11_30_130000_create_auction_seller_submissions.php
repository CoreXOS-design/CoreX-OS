<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 (.ai/specs/auctions.md §22): the "Sell at auction" seller questionnaire - its submissions, two agency settings and one alert type. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auction_seller_submissions')) {
            Schema::create('auction_seller_submissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('status', 20)->default('new');                    // new | contacted | closed
                $t->string('seller_type', 30);
                $t->string('name', 120);
                $t->string('email', 190);
                $t->string('phone', 30);
                $t->string('preferred_contact', 20)->nullable();
                $t->string('property_type', 30);
                $t->string('address', 255);
                $t->string('suburb', 120);
                $t->unsignedBigInteger('price_expectation')->nullable();
                $t->string('auction_format', 20)->nullable();
                $t->boolean('marketing_opt_in')->default(false);
                $t->string('ip', 45)->nullable();
                $t->json('answers');                                          // everything posted except the honeypot
                $t->unsignedBigInteger('contact_id')->nullable();
                $t->unsignedBigInteger('tracked_property_id')->nullable();
                $t->unsignedBigInteger('assigned_user_id')->nullable();
                $t->timestamp('contacted_at')->nullable();
                $t->softDeletes();
                $t->timestamps();
                $t->index(['agency_id', 'status', 'created_at']);
            });
        }

        if (Schema::hasTable('agency_auction_settings')) {
            Schema::table('agency_auction_settings', function (Blueprint $t) {
                if (! Schema::hasColumn('agency_auction_settings', 'seller_form_enabled')) {
                    $t->boolean('seller_form_enabled')->nullable();           // null = on
                }
                if (! Schema::hasColumn('agency_auction_settings', 'seller_response_text')) {
                    $t->string('seller_response_text', 120)->nullable();      // null = "within one business day"
                }
            });
        }

        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', 'auction.seller_submission')->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => 'auction.seller_submission', 'pillar' => 'property', 'group_label' => 'Auctions', 'label' => 'New "Sell at auction" submission',
                'description' => 'A seller completed the questionnaire on your public auction page.',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 0, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => 62, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', 'auction.seller_submission')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        Schema::dropIfExists('auction_seller_submissions');
        foreach (['seller_form_enabled', 'seller_response_text'] as $c) {
            if (Schema::hasColumn('agency_auction_settings', $c)) {
                Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
