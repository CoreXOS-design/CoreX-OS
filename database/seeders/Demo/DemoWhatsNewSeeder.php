<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\SystemUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Webinar day 2026-09-03 — orphan screen (cc3's audit). `system_updates`
 * is confirmed real, working, platform-wide (no agency_id — every CoreX
 * user sees the same feed, by design, per the model's own docblock).
 * Safe to seed: demo runs its own fully isolated database, so nothing
 * here is visible outside demo.
 *
 * A short, believable release history — nothing dated after "today"
 * (relative to now(), never hardcoded). Mostly published; one draft so
 * the admin-only "unpublished" state is visible too, not just the live
 * feed.
 *
 * Idempotent: matched on title.
 */
final class DemoWhatsNewSeeder
{
    private const PLAN = [
        // [title, type, daysAgo, body]
        ['Viewing Packs: document redaction', 'feature', 3,
            "Agents can now redact sensitive sections (ID numbers, bank details) directly on a document before it's included in a buyer's Viewing Pack — no need to leave CoreX to prepare a clean copy."],
        ['Buyer Pipeline: calendar-linked viewing history', 'improvement', 9,
            "A buyer's viewing history on their pipeline card now pulls directly from their actual calendar appointments — feedback captured after a viewing shows up automatically, no manual logging needed."],
        ['Commission split fix on double-ended deals', 'fix', 14,
            "Fixed a rounding issue where a deal with the same agent on both the listing and selling side could show a commission split that didn't add up to 100%."],
        ['Property24 & Private Property: refresh cost reduced', 'improvement', 21,
            "Refreshing an unchanged listing no longer re-uploads photos or agent profile data to either portal — a routine refresh is now a single, fast call instead of the full listing payload."],
        ['Agent Daily: activity points from calendar appointments', 'feature', 28,
            "Viewings, evaluations and listing presentations captured on your calendar now count automatically toward your monthly activity points — no separate manual capture required for those three."],
        ['Commercial Evaluations module', 'feature', 40,
            "A dedicated evaluation tool for commercial, industrial, hospitality and agricultural properties — financials, comparables, and asset schedules, alongside the standard residential CMA."],
        ['FICA document upload: mobile camera capture', 'improvement', 55,
            "Agents can now photograph an ID document or proof of residence directly from their phone during a client meeting, instead of needing a scanned file."],
        ['Deal Register: pipeline stage tracking', 'feature', 68,
            "Every deal now shows its live pipeline stage — offer accepted, bond application, guarantees, deeds office lodgement — with realistic time-in-stage tracking, not just a final status."],
        ['Seller Outreach: WhatsApp opt-out compliance', 'fix', 82,
            "Fixed an edge case where a seller who opted out via WhatsApp reply could still receive one further scheduled follow-up message before the opt-out took effect."],
        ['Setup Wizard: company branding step', 'improvement', 95,
            "New agencies now configure their logo, brand colours and letterhead as part of onboarding, instead of finding the settings screen after the fact."],
    ];

    public function run(): array
    {
        $authorId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
        if (! $authorId) {
            return ['created' => 0, 'note' => 'Skipped — no admin user found.'];
        }

        // SystemUpdateService::candidateIdsFor() — Rule 2 — "a user never sees
        // changes that predate their own account". Every demo user's created_at
        // is a seeding artifact (today or yesterday), which would silently hide
        // this entire release history from every login, including admin's. The
        // whole point of tonight's work is that this reads as a system running
        // for months — so backdate every agency-1 user's created_at ahead of the
        // oldest entry below, one-directional (never move a date forward, so a
        // real account's genuine join date is never touched).
        $oldestEntryAt = now()->subDays(max(array_column(self::PLAN, 2)) + 5);
        DB::table('users')->where('agency_id', 1)
            ->where('created_at', '>', $oldestEntryAt)
            ->update(['created_at' => $oldestEntryAt]);

        $created = 0;
        foreach (self::PLAN as $i => [$title, $type, $daysAgo, $body]) {
            $exists = SystemUpdate::where('title', $title)->exists();
            if ($exists) {
                continue;
            }

            // One entry (the most recent) stays a draft — shows the
            // admin-only unpublished state, not just the live feed.
            $isDraft = $i === 0;

            SystemUpdate::create([
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'status' => $isDraft ? SystemUpdate::STATUS_DRAFT : SystemUpdate::STATUS_PUBLISHED,
                'published_at' => now()->subDays($daysAgo),
                'created_by_user_id' => $authorId,
            ]);
            $created++;
        }

        return ['created' => $created];
    }
}
