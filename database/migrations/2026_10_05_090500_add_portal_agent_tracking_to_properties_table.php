<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal Agent Mismatch Guard (.ai/specs/portal-agent-mismatch-guard.md §3).
 *
 * *_portal_agent_ids — the agent id(s) each portal currently holds for the
 * listing, so CoreX can tell before a send whether the portal shows someone
 * other than the CoreX listing agent. NULL = unknown (never recorded).
 *
 * *_agent_conflict — why the last send was stopped on an agent problem. Drives
 * the listing panel warning and the "Portal agent" filter on the listings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('p24_portal_agent_ids')->nullable()->after('p24_image_signature');
            $table->json('p24_agent_conflict')->nullable()->after('p24_portal_agent_ids');
            $table->json('pp_portal_agent_ids')->nullable()->after('pp_last_error');
            $table->json('pp_agent_conflict')->nullable()->after('pp_portal_agent_ids');
        });

        // Backfill the one thing already known for certain: a Property24
        // reactivation that P24 refused names, in its error, the agent(s) P24
        // holds on the listing ("... not active. AgentIds: 191056."). Recording
        // them lets the listing panel offer the switch to the CoreX listing agent
        // instead of leaving the listing stuck with no way forward.
        DB::table('properties')
            ->where('p24_last_error', 'like', 'Reactivation failed:%AgentIds:%')
            ->select(['id', 'p24_last_error'])
            ->orderBy('id')
            ->each(function ($row) {
                if (!preg_match('/AgentIds:\s*([\d,\s]+)/', (string) $row->p24_last_error, $m)) {
                    return;
                }
                $ids = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'))));
                if (empty($ids)) {
                    return;
                }
                DB::table('properties')->where('id', $row->id)->update(['p24_portal_agent_ids' => json_encode($ids)]);
            });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['p24_portal_agent_ids', 'p24_agent_conflict', 'pp_portal_agent_ids', 'pp_agent_conflict']);
        });
    }
};
