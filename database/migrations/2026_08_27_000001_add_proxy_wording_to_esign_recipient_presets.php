<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESIGN recipient presets — proxy wording (Johan 2026-08-16). A PROXY signer
 * (a representative marked signs_as_proxy) must render differently from an
 * ordinary representative: "as duly authorised representative of {entity} …"
 * rather than "herein represented by …". Preset-configurable, agency-scoped,
 * nullable (falls back to the ordinary phrasing/caption when empty). Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('esign_recipient_presets', function (Blueprint $table) {
            if (! Schema::hasColumn('esign_recipient_presets', 'proxy_phrasing_template')) {
                $table->text('proxy_phrasing_template')->nullable()->after('signature_caption');
            }
            if (! Schema::hasColumn('esign_recipient_presets', 'proxy_signature_caption')) {
                $table->text('proxy_signature_caption')->nullable()->after('proxy_phrasing_template');
            }
        });
    }

    public function down(): void
    {
        Schema::table('esign_recipient_presets', function (Blueprint $table) {
            foreach (['proxy_phrasing_template', 'proxy_signature_caption'] as $col) {
                if (Schema::hasColumn('esign_recipient_presets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
