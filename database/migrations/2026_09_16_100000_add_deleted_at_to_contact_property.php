<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan: "we have to fix it. corex is a no delete system." contact_property
 * has had at least 7 hard-delete call sites — a tenant/owner/seller/buyer/
 * landlord link, once removed, left no trace. Full investigation, both
 * portal-safety checks, and the write-side blind-insert risk are recorded
 * in .ai/specs/rental-applications.md ("The contact_property hard-delete
 * fix").
 *
 * Deliberately NO change to the existing unique(contact_id, property_id)
 * index — combining a hard unique constraint with SoftDeletes is the exact
 * landmine .ai/BUILD_STANDARD.md §5a warns about (a re-linked pair would
 * collide on its own trashed row instead of restoring it), the same
 * landmine deal_properties' own migration names and avoids the same way:
 * uniqueness among ACTIVE links is enforced in application code (find the
 * one existing row for a pair, trashed or not, and restore/update it —
 * never a blind insert), not the database. Also matches Johan's confirmed
 * rule that a contact holds exactly one role per property, ever — see the
 * spec's "confirmed business rule" section.
 *
 * No data migration — every existing row gets deleted_at = NULL by column
 * default; nothing about any existing link changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_property', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('contact_property', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
