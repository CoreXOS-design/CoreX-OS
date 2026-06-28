<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-107 Viewing Pack — Step 5b: per-agency redaction render DPI.
 *
 * The redaction tool rasterizes a source document at a fixed DPI before burning
 * black boxes (Poppler pdftoppm -r <dpi>). DPI is a per-agency SETTING, not a
 * hardcoded constant (configurability hard rule) — NULL means "use the default"
 * (150), resolved in ViewingPackRedactionService::dpiFor(). Mirrors the many
 * other agency-scalar settings that live as columns on `agencies`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('viewing_pack_redaction_dpi')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('viewing_pack_redaction_dpi');
        });
    }
};
