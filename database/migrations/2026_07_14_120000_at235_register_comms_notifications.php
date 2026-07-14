<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-235 (S2, slice b — Communications) — register the two Comms producers.
 *
 *   comms.mailbox_poll_failure   MailboxHealthRecorder      (admins: a mailbox stopped polling)
 *   comms.access_requested       CommsAccessGrantService    (approvers: someone wants thread access)
 *
 * Both were raw `Notification::send($recipients, …)` — no preference, no open-hours
 * window, no cooldown, no ledger row. An admin could not switch either off.
 *
 * Both notifications are DATABASE-ONLY (no toMail(), no toFcmPayload()), so
 * supports_email / supports_push are FALSE. The gateway intersects preference with
 * capability (AT-235 C11), so a user ticking "email" gets nothing extra rather than a
 * 500 inside the mailer.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'key'         => 'comms.mailbox_poll_failure',
            'pillar'      => 'agent',
            'group_label' => 'Communications',
            'label'       => 'Mailbox stopped receiving mail',
            'description' => 'A connected mailbox failed to poll repeatedly — incoming email may be missing.',
            'sort_order'  => 29,
        ],
        [
            'key'         => 'comms.access_requested',
            'pillar'      => 'agent',
            'group_label' => 'Communications',
            'label'       => 'Someone requested access to a conversation',
            'description' => 'An agent asked for access to a client conversation and needs your approval.',
            'sort_order'  => 30,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach (self::ROWS as $row) {
            // Idempotent; never resurrect a deliberately retired row.
            if (DB::table('notification_event_types')->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table('notification_event_types')->insert([
                'key'               => $row['key'],
                'pillar'            => $row['pillar'],
                'group_label'       => $row['group_label'],
                'label'             => $row['label'],
                'description'       => $row['description'],
                'default_enabled'   => 1,
                'threshold_unit'    => 'none',
                'default_threshold' => null,
                'threshold_min'     => null,
                'threshold_max'     => null,
                'supports_in_app'   => 1,
                'supports_email'    => 0, // database-only notifications
                'supports_push'     => 0,
                'is_adapter'        => 0,
                'adapter_column'    => null,
                'sort_order'        => $row['sort_order'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')
            ->whereIn('key', array_column(self::ROWS, 'key'))
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
