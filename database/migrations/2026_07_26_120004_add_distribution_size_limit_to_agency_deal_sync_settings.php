<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-228 — agency-configurable email attachment size limit (MB). Above it, a direct-attachment
 * send auto-splits into "(Part N of M)" emails; a document is never split across parts. Default
 * 20 MB (a common provider ceiling). Nothing hardcoded — every threshold is a setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_deal_sync_settings', function (Blueprint $table) {
            $table->unsignedInteger('max_email_attachment_mb')->default(20)->after('revert_property_on_deal_declined');
        });
    }

    public function down(): void
    {
        Schema::table('agency_deal_sync_settings', function (Blueprint $table) {
            $table->dropColumn('max_email_attachment_mb');
        });
    }
};
