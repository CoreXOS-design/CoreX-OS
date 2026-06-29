<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-125 step 1 — lossless backfill + relax contacts.phone NOT-NULL.
 *
 * 1. Every contact with a non-empty contacts.phone → ONE contact_phones row
 *    (is_primary=true, normalised = last-9 digits). Same for contacts.email →
 *    contact_emails. Idempotent: guarded by NOT EXISTS so a re-run inserts
 *    nothing for a contact that already has rows (and never clobbers app-created
 *    identifiers added after backfill).
 * 2. Relax contacts.phone NOT-NULL → NULLABLE (email-only contacts are now
 *    valid). Run AFTER backfill so no contact loses its mirror.
 *
 * The singular contacts.phone/email columns stay as the synced-primary MIRROR —
 * post-backfill they already equal the primary child row by construction; the
 * mirror-sync mechanism keeps them correct going forward.
 *
 * Normalisation matches ContactDuplicateService exactly: phone =
 * RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),9) but NULL when <9 digits; email =
 * LOWER(TRIM(email)). Includes soft-deleted contacts (their mirror stays
 * consistent if restored).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Phones ──────────────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            INSERT INTO contact_phones
                (agency_id, contact_id, phone, phone_normalised, label, is_primary, created_at, updated_at)
            SELECT
                c.agency_id,
                c.id,
                c.phone,
                CASE
                    WHEN CHAR_LENGTH(REGEXP_REPLACE(c.phone, '[^0-9]', '')) >= 9
                    THEN RIGHT(REGEXP_REPLACE(c.phone, '[^0-9]', ''), 9)
                    ELSE NULL
                END,
                NULL,
                1,
                NOW(),
                NOW()
            FROM contacts c
            WHERE c.phone IS NOT NULL
              AND TRIM(c.phone) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM contact_phones cp WHERE cp.contact_id = c.id
              )
        SQL);

        // ── Emails ──────────────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            INSERT INTO contact_emails
                (agency_id, contact_id, email, email_normalised, label, is_primary, created_at, updated_at)
            SELECT
                c.agency_id,
                c.id,
                c.email,
                LOWER(TRIM(c.email)),
                NULL,
                1,
                NOW(),
                NOW()
            FROM contacts c
            WHERE c.email IS NOT NULL
              AND TRIM(c.email) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM contact_emails ce WHERE ce.contact_id = c.id
              )
        SQL);

        // ── Relax NOT-NULL on contacts.phone (after backfill) ───────────────
        DB::statement("ALTER TABLE contacts MODIFY phone VARCHAR(255) NULL");
    }

    public function down(): void
    {
        // Restore the prior NOT-NULL contract. Email-only contacts created after
        // this migration carry a NULL phone — default them to '' (the legacy
        // "no phone" convention used by the old create paths) so NOT-NULL can be
        // reinstated cleanly. Backfilled child rows are removed by the paired
        // table-create migration's down().
        if (Schema::hasTable('contacts')) {
            DB::statement("UPDATE contacts SET phone = '' WHERE phone IS NULL");
            DB::statement("ALTER TABLE contacts MODIFY phone VARCHAR(255) NOT NULL");
        }
    }
};
