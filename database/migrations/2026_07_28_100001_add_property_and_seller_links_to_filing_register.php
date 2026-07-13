<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-238 — the filing register learns to point at the real records.
 *
 * Today a filing row retypes facts the system already holds: the property as a
 * free-text address, the seller as a free-text name, the expiry by hand. Retyped
 * facts drift — the register says "3 Forset Walk" while the property record says
 * "3 Forest Walk", and neither knows about the other.
 *
 * So the row gains LINKS to the canonical records. It does NOT lose its free text:
 *
 *   property_address / seller_name STAY, and stay authoritative for display when
 *   there is no link. On qa1, ~42% of the 2,069 historical rows match no property
 *   at all — 2020-era files that predate the property records, plus real typos. A
 *   filing register that cannot record those is worse than one that can. Free text
 *   is the permanent fallback, not a temporary crutch.
 *
 * EXPIRY STAYS PER-ROW (Johan's ruling, 2026-07-13). `properties.expiry_date` holds
 * ONE date, but the register legitimately holds several mandate documents per
 * property — on qa1, 68 addresses carry more than one OA/EA, several with genuinely
 * DIFFERENT expiry dates. An OA and an EA are separate documents with their own
 * lifespans. So linking a property AUTO-FILLS the expiry (once, into this row's own
 * column, where the user can override it) and never rewrites it thereafter. The
 * register records what was actually filed; it is a legal record, not a cache.
 *
 * Link provenance mirrors the proven `deals` shape (2026_06_02_080001) so the
 * ambiguous-match review pattern is identical across the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_filing_register', function (Blueprint $table) {
            if (! Schema::hasColumn('document_filing_register', 'property_id')) {
                $table->foreignId('property_id')->nullable()
                    ->after('agent_id')
                    ->constrained('properties', 'id', 'dfr_property_fk')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('document_filing_register', 'seller_contact_id')) {
                $table->foreignId('seller_contact_id')->nullable()
                    ->after('property_id')
                    ->constrained('contacts', 'id', 'dfr_seller_contact_fk')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('document_filing_register', 'link_source')) {
                $table->enum('link_source', [
                    'manual',              // a human picked the property in the form
                    'auto_address_match',  // the backfill matched it unambiguously
                    'admin_review',        // resolved out of the review queue
                ])->nullable()->after('expiry_date');
            }
            if (! Schema::hasColumn('document_filing_register', 'link_confidence')) {
                $table->enum('link_confidence', ['exact', 'high', 'medium', 'low'])
                    ->nullable()->after('link_source');
            }
            if (! Schema::hasColumn('document_filing_register', 'link_reviewed_at')) {
                $table->timestamp('link_reviewed_at')->nullable()->after('link_confidence');
            }
            if (! Schema::hasColumn('document_filing_register', 'link_reviewed_by_user_id')) {
                $table->foreignId('link_reviewed_by_user_id')->nullable()
                    ->after('link_reviewed_at')
                    ->constrained('users', 'id', 'dfr_link_reviewer_fk')
                    ->nullOnDelete();
            }
        });

        Schema::table('document_filing_register', function (Blueprint $table) {
            $table->index('property_id', 'dfr_property_idx');
            $table->index('seller_contact_id', 'dfr_seller_contact_idx');
        });
    }

    public function down(): void
    {
        Schema::table('document_filing_register', function (Blueprint $table) {
            $table->dropIndex('dfr_property_idx');
            $table->dropIndex('dfr_seller_contact_idx');
            $table->dropForeign('dfr_property_fk');
            $table->dropForeign('dfr_seller_contact_fk');
            $table->dropForeign('dfr_link_reviewer_fk');
            $table->dropColumn([
                'property_id', 'seller_contact_id',
                'link_source', 'link_confidence', 'link_reviewed_at', 'link_reviewed_by_user_id',
            ]);
        });
    }
};
