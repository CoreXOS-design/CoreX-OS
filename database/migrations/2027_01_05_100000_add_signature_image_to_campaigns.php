<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-534 — image signature for a Campaigns sending address (.ai/specs/campaigns.md 9.3). Additive and nullable on both tables:
 * campaign_senders.signature_image_path = the uploaded picture (local disk); campaign_sends.body_html = the HTML version of that
 * email, snapshotted at claim time exactly like `body`, so a retry re-sends the identical message. NULL = plain-text only (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_senders') && ! Schema::hasColumn('campaign_senders', 'signature_image_path')) {
            Schema::table('campaign_senders', function (Blueprint $table) {
                $table->string('signature_image_path', 255)->nullable()->after('signature');
            });
        }

        if (Schema::hasTable('campaign_sends') && ! Schema::hasColumn('campaign_sends', 'body_html')) {
            Schema::table('campaign_sends', function (Blueprint $table) {
                $table->mediumText('body_html')->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaign_sends', 'body_html')) {
            Schema::table('campaign_sends', fn (Blueprint $table) => $table->dropColumn('body_html'));
        }
        if (Schema::hasColumn('campaign_senders', 'signature_image_path')) {
            Schema::table('campaign_senders', fn (Blueprint $table) => $table->dropColumn('signature_image_path'));
        }
    }
};
