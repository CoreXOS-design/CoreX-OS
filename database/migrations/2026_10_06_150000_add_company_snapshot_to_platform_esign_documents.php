<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sent Subscription Agreement keeps the RR Technologies letterhead + party details it was sent with
 * (spec .ai/specs/platform-company-profile.md §7a). `company_snapshot` = the non-sensitive company fields + logo version
 * at send time. Agreements already sent before this column existed are pinned to the company record as it is NOW.
 *
 * Renamed from 2026_10_10_130000_… (it sorted after every other 2026_10_0x migration and out of date order). Safe to re-run on a database
 * that already ran the old name: the column is only added when missing and the backfill only touches rows that still have no snapshot.
 * Uses DB / Schema only — never an app model — so a later change to PlatformCompany can never break this migration.
 */
return new class extends Migration {
    /** Mirrors the platform company model's SNAPSHOT_FIELDS list at the time this migration was written (bank details are never pinned). */
    private const FIELDS = [
        'legal_name', 'trading_name', 'registration_number', 'vat_registered', 'vat_number', 'directors', 'physical_address',
        'postal_address', 'email_general', 'email_support', 'email_accounts', 'phones', 'websites', 'strap_line',
        'letterhead_footer', 'email_signature_html', 'logo_id',
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('platform_esign_documents', 'company_snapshot')) {
            Schema::table('platform_esign_documents', function (Blueprint $t) {
                $t->json('company_snapshot')->nullable();
            });
        }

        if (Schema::hasTable('platform_company') && ($row = DB::table('platform_company')->first())) {
            $snap = [];
            foreach (self::FIELDS as $f) {
                $v = $row->{$f} ?? null;
                if (in_array($f, ['directors', 'phones', 'websites'], true)) {
                    $v = $v === null ? null : json_decode((string) $v, true);
                } elseif ($f === 'vat_registered') {
                    $v = (bool) $v;
                } elseif ($f === 'logo_id') {
                    $v = $v === null ? null : (int) $v;
                }
                $snap[$f] = $v;
            }
            DB::table('platform_esign_documents')->whereNotNull('wording_version_id')->whereNull('company_snapshot')
                ->update(['company_snapshot' => json_encode($snap)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_esign_documents', 'company_snapshot')) {
            Schema::table('platform_esign_documents', fn (Blueprint $t) => $t->dropColumn('company_snapshot'));
        }
    }
};
