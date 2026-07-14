<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The P24 refresh cost contract (see .ai/specs/p24-syndication.md §"Refresh cost").
 *
 * Every listing submit used to re-push the agent's full profile AND re-upload
 * their (unchanged) profile photo — for the listing agent AND the second agent.
 * That is 2-4 P24 round-trips per Refresh, re-sending bytes P24 already holds:
 * the same bug class the `p24_image_signature` gate already fixed for the photo
 * gallery, just on the agent object instead.
 *
 * These columns are the agent-side equivalent of `properties.p24_image_signature`:
 * a fingerprint of what P24 currently holds, so an unchanged agent costs ZERO
 * calls on a refresh.
 *
 *  - p24_agent_agency_id  the P24 agency `p24_agent_id` was resolved under. The
 *                         id is only trustworthy under that agency (P24 scopes
 *                         agents per agency), so we record the pair. Having it
 *                         lets a submit skip the ~90s GET /agencies/{id}/agents
 *                         list scan entirely.
 *  - p24_profile_signature md5 of the last profile payload P24 accepted.
 *  - p24_photo_signature   fingerprint of the photo bytes P24 currently holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'p24_agent_agency_id')) {
                $table->unsignedInteger('p24_agent_agency_id')->nullable()->after('p24_agent_id');
            }
            if (!Schema::hasColumn('users', 'p24_profile_signature')) {
                $table->string('p24_profile_signature', 32)->nullable()->after('p24_agent_agency_id');
            }
            if (!Schema::hasColumn('users', 'p24_photo_signature')) {
                $table->string('p24_photo_signature', 64)->nullable()->after('p24_profile_signature');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['p24_agent_agency_id', 'p24_profile_signature', 'p24_photo_signature'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
