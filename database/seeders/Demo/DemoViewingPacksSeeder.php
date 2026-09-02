<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\ViewingPack;
use App\Models\ViewingPackProperty;
use Illuminate\Support\Facades\DB;

/**
 * Webinar-eve gap fix (2026-09-02) — Johan found zero viewing packs on demo,
 * a screen with an admin-visible-to-all scope (PermissionService data-scope
 * 'all' for the admin role) that renders empty on a fresh checkout.
 *
 * viewing_packs requires agency_id/contact_id(buyer)/agent_id NOT NULL;
 * status is 'draft'|'ready' only (no third state — confirmed from the
 * migration's own column default and model constants).
 *
 * Idempotent: identified by title prefix '[DEMO] Viewing Pack —', archived
 * (soft-deleted, cascading to its viewing_pack_properties children) then
 * recreated fresh on every run — same pattern as CalendarDemoSeeder / the
 * DR2 pipeline seeder's archive-then-recreate, since a pack's tour_at date
 * must stay relative to now() and can't be safely left stale in place.
 */
final class DemoViewingPacksSeeder
{
    private const TITLE_PREFIX = '[DEMO] Viewing Pack — ';

    private const PLAN = [
        // [agentCursor, contactCursor, status, tourDaysFromNow, propertyCount]
        ['agentCursor' => 0, 'contactCursor' => 0, 'status' => 'ready', 'tourDays' => 3, 'title' => 'Sea-view shortlist'],
        ['agentCursor' => 1, 'contactCursor' => 1, 'status' => 'ready', 'tourDays' => -5, 'title' => 'Family home tour (completed)'],
        ['agentCursor' => 2, 'contactCursor' => 2, 'status' => 'draft', 'tourDays' => 7, 'title' => 'Retirement downsize options'],
    ];

    public function run(int $agencyId): array
    {
        $this->archivePriorBatch($agencyId);

        $agentIds = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['agent', 'branch_manager'])
            ->pluck('id')
            ->all();
        $branchByAgent = DB::table('users')->where('agency_id', $agencyId)->pluck('branch_id', 'id');
        $contactIds = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(30)
            ->pluck('id')
            ->all();
        $propertyIds = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('listing_type', 'sale')
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(60)
            ->pluck('id')
            ->all();

        if (empty($agentIds) || empty($contactIds) || count($propertyIds) < 12) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} lacks agents, contacts, or active sale stock."];
        }

        $inserted = 0;
        $propertyCursor = 0;

        foreach (self::PLAN as $plan) {
            $agentId = $agentIds[$plan['agentCursor'] % count($agentIds)];
            $contactId = $contactIds[$plan['contactCursor'] % count($contactIds)];

            $pack = ViewingPack::create([
                'agency_id' => $agencyId,
                'contact_id' => $contactId,
                'agent_id' => $agentId,
                'branch_id' => $branchByAgent[$agentId] ?? null,
                'tour_at' => now()->addDays($plan['tourDays']),
                'status' => $plan['status'],
                'title' => self::TITLE_PREFIX . $plan['title'],
            ]);
            $inserted++;

            $packPropertyCount = 4;
            for ($p = 0; $p < $packPropertyCount; $p++) {
                $propertyId = $propertyIds[$propertyCursor++ % count($propertyIds)];
                ViewingPackProperty::create([
                    'agency_id' => $agencyId,
                    'viewing_pack_id' => $pack->id,
                    'property_id' => $propertyId,
                    'sort_order' => $p,
                    'source' => 'ad_hoc',
                ]);
            }
        }

        return ['inserted' => $inserted];
    }

    private function archivePriorBatch(int $agencyId): void
    {
        DB::transaction(function () use ($agencyId) {
            $now = now();
            $packIds = DB::table('viewing_packs')
                ->where('agency_id', $agencyId)
                ->where('title', 'like', self::TITLE_PREFIX . '%')
                ->whereNull('deleted_at')
                ->pluck('id');

            if ($packIds->isEmpty()) {
                return;
            }

            DB::table('viewing_pack_properties')
                ->whereIn('viewing_pack_id', $packIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            DB::table('viewing_packs')
                ->whereIn('id', $packIds)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
        });
    }
}
