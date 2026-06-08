<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — public website contact block additions.
 *
 * Adds the public-facing address and a repeatable open-hours list to the
 * Company Settings → Website tab. Both are served (when non-empty) via
 * GET /api/v1/website/agency so the agency website renders a contact /
 * opening-hours block. The legacy website_url / website_tagline /
 * website_about columns are retained but no longer edited or exposed.
 *
 * Spec: .ai/specs/agency-public-api.md §3.7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'website_address')) {
                $table->string('website_address')->nullable()->after('website_contact_phone');
            }
            if (!Schema::hasColumn('agencies', 'website_open_hours')) {
                // [{ "days": "Monday – Friday", "hours": "08:00 – 17:00" }, …]
                $table->json('website_open_hours')->nullable()->after('website_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['website_address', 'website_open_hours'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
