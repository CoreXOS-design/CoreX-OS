<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-235 (S2, slice a — Leads) — register `lead.portal_received`.
 *
 * One real-world fact ("a portal enquiry landed on your listing") was being sent by
 * TWO uncoordinated listeners on the same event:
 *
 *   EmailPortalLeadToAgent   → $agent->notify(…)   in-app + email
 *   PushNewPortalLeadToMobile → push->sendToUserIds(…)   FCM
 *
 * Neither consulted the notification preferences, and the push path **never read
 * `notify_push` at all** — so an agent who had turned push OFF still got pushed
 * (AT-235 C10). Nothing was written to the dispatch ledger by either.
 *
 * They collapse into a single gateway send: one fact, one notification, three
 * channels resolved once, in one place. The agent can now switch it off, choose its
 * channels, and we can prove what was delivered.
 *
 * All three channels are supported: NewPortalLeadAgentNotification has toArray(),
 * toMail() AND (now) toFcmPayload().
 */
return new class extends Migration
{
    private const KEY = 'lead.portal_received';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        if (DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return; // idempotent; never resurrect a retired row
        }

        DB::table('notification_event_types')->insert([
            'key'               => self::KEY,
            'pillar'            => 'contact',
            'group_label'       => 'Leads',
            'label'             => 'New portal lead on your listing',
            'description'       => 'A buyer enquired through Property24 or Private Property about one of your listings.',
            'default_enabled'   => 1,
            'threshold_unit'    => 'none',
            'default_threshold' => null,
            'threshold_min'     => null,
            'threshold_max'     => null,
            'supports_in_app'   => 1,
            'supports_email'    => 1,
            'supports_push'     => 1,
            'is_adapter'        => 0,
            'adapter_column'    => null,
            'sort_order'        => 28,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')
            ->where('key', self::KEY)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
