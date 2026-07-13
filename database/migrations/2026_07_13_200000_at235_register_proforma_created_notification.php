<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-235 (S1) — register `proforma.created`, so AT-245's admin notify becomes the
 * FIRST CITIZEN of the notification gateway.
 *
 * `ProformaGenerationService` sent this notification with a raw
 * `Notification::send($admins, …)`. It therefore:
 *   - could not be switched off by an admin (no catalogue row = no settings toggle),
 *   - honoured no preference, no open-hours window and no cooldown, and
 *   - wrote nothing to notification_dispatch_log, so nothing recorded that it fired.
 *
 * The R7 build guard caught it on its first merge. This registers the key so the
 * gateway can govern it.
 *
 * CAPABILITY, NOT JUST PREFERENCE: ProformaCreatedNotification is database-only — it
 * has no toMail(). supports_email / supports_push are therefore FALSE, and the
 * gateway now intersects the user's preference with these flags (they existed but
 * nothing read them — AT-235 C11). A user who ticks "email" for this event gets
 * nothing extra rather than a 500 inside the mailer.
 */
return new class extends Migration
{
    private const KEY = 'proforma.created';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        // Idempotent — and never resurrect it if it has been deliberately retired.
        if (DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('notification_event_types')->insert([
            'key'               => self::KEY,
            'pillar'            => 'deal',
            'group_label'       => 'Finance',
            'label'             => 'Proforma invoice generated',
            'description'       => 'An agent generated a proforma invoice on a deal.',
            'default_enabled'   => 1,
            'threshold_unit'    => 'none',
            'default_threshold' => null,
            'threshold_min'     => null,
            'threshold_max'     => null,
            'supports_in_app'   => 1,
            'supports_email'    => 0, // the notification has no toMail()
            'supports_push'     => 0, // …and no toFcmPayload()
            'is_adapter'        => 0,
            'adapter_column'    => null,
            'sort_order'        => 27,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        // Soft-delete — no hard deletes, and a user's saved preference survives.
        DB::table('notification_event_types')
            ->where('key', self::KEY)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
