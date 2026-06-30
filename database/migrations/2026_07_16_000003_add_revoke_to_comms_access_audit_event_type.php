<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-132 Wave 1, Step 6 — add 'revoke' to the comms_access_audit_log event_type enum.
 *
 * Closes the audit gap: an explicit owner/admin (or requester self-) revoke of a
 * grant — especially an 'always' grant, which the midnight/logout sweeps skip —
 * must be POPIA-logged (Johan req 1: the trail is never silent). 'session_expired'
 * (logout) and 'midnight_reset' already cover the automatic session endings; this
 * is the deliberate, human-actioned revoke. Additive enum change.
 *
 * Wave 2 (AT-130 OTP break-glass) will add 'otp_issued'/'otp_unlock' here — NOT now.
 *
 * Spec: .ai/specs/at132-perthread-comms-gate.md §F
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE comms_access_audit_log MODIFY event_type ENUM("
            . "'request','grant','decline','session_expired','midnight_reset',"
            . "'ownership_transfer','revoke') NOT NULL"
        );
    }

    public function down(): void
    {
        // Revert to the pre-AT-132 set. Safe: 'revoke' rows are append-only history;
        // if any exist, narrowing the enum would error — but down() is dev-only and
        // a fresh enum without 'revoke' matches the original AT-118 schema.
        DB::statement(
            "ALTER TABLE comms_access_audit_log MODIFY event_type ENUM("
            . "'request','grant','decline','session_expired','midnight_reset',"
            . "'ownership_transfer') NOT NULL"
        );
    }
};
