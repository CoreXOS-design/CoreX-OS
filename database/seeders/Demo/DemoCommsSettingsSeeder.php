<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar prep 2026-09-02 — Johan: "Finish the comms settings work
 * (email/WhatsApp/SMS config, notification preferences, templates,
 * footers) - all inert, nothing able to send externally."
 *
 * Root cause confirmed by direct query before writing: `user_notification_preferences`
 * had 0 rows for every agency-1 user (Notification Preferences tab always
 * rendered pure defaults, not a tuned team) and `communication_wa_devices`
 * had 0 rows system-wide (WhatsApp Devices screen rendered as never-set-up).
 *
 * INERT: plain DB rows only. WhatsApp devices point at a fake WAHA session
 * name — WAHA has no API key configured in .env and is a localhost-only
 * Docker bridge (config/communications.php), so these rows cannot trigger
 * any real send; they only make the settings screen read as "connected".
 *
 * Idempotent: user_notification_preferences uses updateOrInsert keyed on
 * (user_id, notification_event_type_id). communication_wa_devices uses
 * updateOrInsert keyed on (user_id) — one device per user.
 */
final class DemoCommsSettingsSeeder
{
    public function run(int $agencyId): array
    {
        $users = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('email', '!=', 'viewer@demo.corexos.co.za')
            ->get(['id', 'name']);

        if ($users->isEmpty()) {
            return ['note' => "Skipped — agency {$agencyId} has no users."];
        }

        $eventTypes = DB::table('notification_event_types')->get(['id', 'key']);
        if ($eventTypes->isEmpty()) {
            return ['note' => 'Skipped — notification_event_types is empty.'];
        }

        // A handful of event types each user turns OFF, so preferences read
        // as individually tuned rather than a uniform bulk-insert. Deterministic
        // per user id (not random) so re-runs are stable.
        $optOutPool = ['agent.idle', 'agent.daily_digest', 'contact.birthday', 'leave.ending_soon'];

        $prefRows = 0;
        foreach ($users as $user) {
            foreach ($eventTypes as $i => $type) {
                $optedOut = in_array($type->key, $optOutPool, true)
                    && (($user->id + $i) % 3 === 0);

                DB::table('user_notification_preferences')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'notification_event_type_id' => $type->id,
                    ],
                    [
                        'enabled'       => $optedOut ? 0 : 1,
                        'threshold'     => null,
                        'channel_in_app' => 1,
                        'channel_email'  => $optedOut ? 0 : 1,
                        'channel_push'   => 0,
                        'updated_at'    => now(),
                        'created_at'    => now(),
                    ]
                );
                $prefRows++;
            }
        }

        // One connected WhatsApp device per user — realistic SA mobile numbers,
        // recent last_seen_at so the device reads as actively in use, not
        // registered-then-abandoned.
        $waRows = 0;
        foreach ($users as $i => $user) {
            $waNumber = '+2782' . str_pad((string) (1000000 + ($user->id * 137) % 8999999), 7, '0', STR_PAD_LEFT);

            DB::table('communication_wa_devices')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'agency_id'     => $agencyId,
                    'wa_number'     => $waNumber,
                    'waha_session'  => 'demo-agent-' . $user->id,
                    'device_token'  => 'demo-inert-' . bin2hex(random_bytes(8)),
                    'last_seen_at'  => now()->subMinutes(mt_rand(4, 2880)),
                    'active'        => 1,
                    'updated_at'    => now(),
                    'created_at'    => now()->subDays(mt_rand(20, 180)),
                ]
            );
            $waRows++;
        }

        return [
            'users_covered'        => $users->count(),
            'notification_prefs_rows' => $prefRows,
            'wa_devices_seeded'     => $waRows,
        ];
    }
}
