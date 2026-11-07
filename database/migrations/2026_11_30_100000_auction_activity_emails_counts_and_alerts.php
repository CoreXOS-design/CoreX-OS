<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2 (.ai/specs/auctions.md §19): who is emailed about new registrations / enquiries (agency setting), the "last looked at the
 * Bids screen" marker behind the sidebar number, and the two in-app alert types (new bid, new enquiry).
 */
return new class extends Migration
{
    private const TYPES = [
        ['auction.bid_placed', 'New bid on your auction lot', 'A buyer placed a bid on one of your auction lots.', 60],
        ['auction.enquiry_received', 'New auction enquiry', 'A buyer sent an enquiry through your public auction page.', 61],
    ];

    public function up(): void
    {
        if (Schema::hasTable('agency_auction_settings')) {
            Schema::table('agency_auction_settings', function (Blueprint $t) {
                if (! Schema::hasColumn('agency_auction_settings', 'notify_emails_enabled')) {
                    $t->boolean('notify_emails_enabled')->nullable();   // null = on
                }
                if (! Schema::hasColumn('agency_auction_settings', 'notify_emails_to')) {
                    $t->text('notify_emails_to')->nullable();           // null = every administrator of the agency
                }
            });
        }

        if (! Schema::hasTable('auction_screen_visits')) {
            Schema::create('auction_screen_visits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('screen', 30);
                $t->timestamp('seen_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'screen']);
            });
        }

        if (Schema::hasTable('notification_event_types')) {
            foreach (self::TYPES as [$key, $label, $description, $sort]) {
                if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                    continue;
                }
                DB::table('notification_event_types')->insert([
                    'key' => $key, 'pillar' => 'property', 'group_label' => 'Auctions', 'label' => $label, 'description' => $description,
                    'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                    'supports_in_app' => 1, 'supports_email' => 0, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                    'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->whereIn('key', array_column(self::TYPES, 0))->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        Schema::dropIfExists('auction_screen_visits');
        if (Schema::hasTable('agency_auction_settings')) {
            Schema::table('agency_auction_settings', function (Blueprint $t) {
                foreach (['notify_emails_enabled', 'notify_emails_to'] as $c) {
                    if (Schema::hasColumn('agency_auction_settings', $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
