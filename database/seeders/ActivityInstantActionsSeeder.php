<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SPINE-1 — system-default catalogue of instant-action slugs that the
 * activity-points engine can credit. Companion to M6.2's calendar-class
 * mappings; same table (activity_definition_calendar_classes), different
 * trigger_kind.
 *
 * REFERENCE seeder — runs on every deploy via DatabaseSeeder + deploy.sh
 * REF_SEEDERS. Idempotent at row level: existence check on
 * (agency_id, trigger_kind='instant', slug) BEFORE insert. Re-runs are
 * no-ops; admin-removed (soft-deleted) mappings stay removed (we do not
 * resurrect intent).
 *
 * Each slug seeds TWO rows:
 *   1. A system activity_definition  (scope=system, agency_id=NULL,
 *      weight=1, is_enabled=false) — the points source. is_enabled=false
 *      hides it from the manual-capture picker + the calendar-mapping
 *      picker so these "[Auto]" definitions don't pollute agency UIs.
 *      A future SPINE-N admin UI will query the instant catalogue
 *      directly by trigger_kind, not via the is_enabled filter.
 *   2. A system mapping row (agency_id=NULL, trigger_kind='instant',
 *      slug=...) — the lookup target for InstantPointService::credit().
 *      Agencies inherit these via the coalesce pattern; per-agency
 *      overrides win when present.
 *
 * Defaults per Johan's V1 rule:
 *   - weight = 1 on every system definition (agency tunes to taste).
 *   - value_per_event = 1 on every mapping (agency tunes to taste).
 *   - is_active per the SPINE-AUDIT §1 / §5 anti-list. Items audit
 *     called out as "too noisy / too gameable" are seeded INACTIVE
 *     (agency opts in deliberately).
 *
 * SCORE THE ACTION, NOT THE OUTCOME (Johan's V1):
 *   - presentation.won AND presentation.lost both ACTIVE — agent did
 *     the work either way.
 *   - fica.submitted ACTIVE; fica.rejected is NOT a slug (rejection
 *     does not revoke). Only fica.approved (separate positive action)
 *     gets its own credit.
 *   - Anti-list slugs (outreach.pitch_clicked, etc.) are NOT seeded.
 */
final class ActivityInstantActionsSeeder extends Seeder
{
    /**
     * Catalogue keyed by slug. Each entry:
     *   - label         — short human-readable name (also seeds the
     *                     activity_definition.name with an "[Auto] " prefix)
     *   - is_active     — default agency activation state
     *   - subject_type  — advisory: the model class the action concerns
     *   - daily_cap     — optional per-user daily limit (null = no cap)
     *   - sort_order    — for future admin UI ordering
     *
     * Category groupings (for the future SPINE-N admin UI) reflect
     * SPINE-AUDIT §4. Comments above each block name the category.
     */
    private const CATALOGUE = [
        // ── Contacts & Buyers
        'contact.captured' => [
            'label' => 'Contact captured', 'is_active' => true,
            'subject_type' => \App\Models\Contact::class, 'daily_cap' => 50, 'sort_order' => 100,
        ],

        // ── Properties & Listings
        'property.captured' => [
            'label' => 'Property captured', 'is_active' => true,
            'subject_type' => \App\Models\Property::class, 'daily_cap' => null, 'sort_order' => 200,
        ],
        'property.published' => [
            'label' => 'Property published (first time)', 'is_active' => true,
            'subject_type' => \App\Models\Property::class, 'daily_cap' => null, 'sort_order' => 210,
        ],
        'property.compliance_passed' => [
            'label' => 'Property compliance snapshot first taken', 'is_active' => true,
            'subject_type' => \App\Models\Property::class, 'daily_cap' => null, 'sort_order' => 220,
        ],

        // ── MIC / Prospecting
        'mic.claim_taken' => [
            'label' => 'MIC claim taken', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 300,
        ],
        'mic.claim_feedback' => [
            'label' => 'MIC claim feedback recorded', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 310,
        ],
        'tracked_property.promoted_to_stock' => [
            'label' => 'Tracked property promoted to agency stock', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 320,
        ],
        'map.prospect_launched' => [
            // SPINE-AUDIT: "Map exploration; idempotent per session. Likely low value."
            // Seeded INACTIVE — agency opts in.
            'label' => 'Map prospect launched', 'is_active' => false,
            'subject_type' => null, 'daily_cap' => 20, 'sort_order' => 330,
        ],

        // ── Seller Outreach
        'outreach.pitch_sent' => [
            'label' => 'Seller-outreach pitch sent', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 400,
        ],
        'outreach.outcome_logged' => [
            'label' => 'Seller-outreach outcome logged', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 410,
        ],

        // ── Presentations (action, not outcome — both win + lost credit)
        'presentation.generated' => [
            'label' => 'Presentation generated', 'is_active' => true,
            'subject_type' => \App\Models\Presentation::class, 'daily_cap' => null, 'sort_order' => 500,
        ],
        'presentation.won' => [
            'label' => 'Presentation outcome: won', 'is_active' => true,
            'subject_type' => \App\Models\Presentation::class, 'daily_cap' => null, 'sort_order' => 510,
        ],
        'presentation.lost' => [
            // V1 rule: action credit regardless of outcome.
            'label' => 'Presentation outcome: lost', 'is_active' => true,
            'subject_type' => \App\Models\Presentation::class, 'daily_cap' => null, 'sort_order' => 520,
        ],

        // ── Deals & Mandates
        'mandate.signed' => [
            'label' => 'Mandate signed', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 600,
        ],
        'deal.created' => [
            'label' => 'Deal created', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 610,
        ],
        'deal.stage_advanced' => [
            'label' => 'Deal stage advanced', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 620,
        ],
        'deal.registered' => [
            // The "won stock" event. Johan will tune the weight up at launch.
            'label' => 'Deal registered (sold)', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 630,
        ],
        'deal.commission_finalised' => [
            'label' => 'Deal commission finalised', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 640,
        ],

        // ── Compliance & FICA
        'fica.submitted' => [
            'label' => 'FICA submitted', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 700,
        ],
        'fica.approved' => [
            'label' => 'FICA approved', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 710,
        ],
        // SPINE-3 — outcome-independent reviewer credit (Johan's V1 rule).
        // Fires for BOTH FicaApproved and FicaRejected — the review work
        // scores either way. The agent's fica.submitted credit is
        // unaffected; rejection does NOT revoke. Replaces the more-
        // specific fica.approved in practice (fica.approved is kept
        // active for backward compat — agencies that prefer the
        // approve-only model can deactivate fica.reviewed via admin).
        'fica.reviewed' => [
            'label' => 'FICA reviewed (any outcome)', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 715,
        ],
        'rcr.submitted' => [
            'label' => 'RCR submission submitted', 'is_active' => true,
            'subject_type' => null, 'daily_cap' => null, 'sort_order' => 720,
        ],

        // SPINE-3 — Marketing
        'marketing.published' => [
            'label' => 'Marketing post published', 'is_active' => true,
            'subject_type' => \App\Models\PropertyMarketingPost::class,
            'daily_cap' => null, 'sort_order' => 800,
        ],

        // ── SPINE-2.5 — multi-actor deal role slugs
        // Every deal-family scoreable action credits ALL participants by
        // role. Creator is still scored under the existing single-actor
        // slugs (deal.created / deal.registered / etc.); these role-side
        // slugs credit each pivot agent (deal_user.side='listing' on V1,
        // deal_v2_agents.role='listing_agent' on V2) and the selling
        // equivalent. One row PER participant — agency tunes per-role
        // weight via value_per_event in admin (settings UI lands next).
        //
        // Per-event role slugs (instead of a single shared deal.listing_side):
        //   - keeps the (slug, subject_id, user, date) dedup key
        //     unambiguous when DealCreated and DealRegistered fire on the
        //     same deal on the same day,
        //   - lets agency weight the win moment differently from the
        //     capture moment ("doing the deal" vs "starting the deal"),
        //   - matches Johan's "per-side" instruction in SPINE-2.5 prompt
        //     for the win event explicitly.
        'deal.listing_side' => [
            'label' => 'Deal captured — listing-side participation', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 612,
        ],
        'deal.selling_side' => [
            'label' => 'Deal captured — selling-side participation', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 614,
        ],
        'deal.registered.listing_side' => [
            'label' => 'Deal registered — listing-side participation', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 632,
        ],
        'deal.registered.selling_side' => [
            'label' => 'Deal registered — selling-side participation', 'is_active' => true,
            'subject_type' => \App\Models\Deal::class, 'daily_cap' => null, 'sort_order' => 634,
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::CATALOGUE as $slug => $config) {
            // ── Step 1: ensure the system activity_definition exists.
            // Keyed by name so re-runs are idempotent; scope=system +
            // agency_id=NULL keeps it out of every agency's manual
            // capture picker; is_enabled=false hides it from the M6.2
            // calendar-mapping admin picker too. A future SPINE-N admin
            // UI will query by trigger_kind directly.
            $defName = '[Auto] ' . $slug;
            $existingDef = DB::table('activity_definitions')
                ->where('name', $defName)
                ->where('scope', 'system')
                ->first(['id']);

            if ($existingDef) {
                $defId = (int) $existingDef->id;
            } else {
                $defId = (int) DB::table('activity_definitions')->insertGetId([
                    'name'         => $defName,
                    'scope'        => 'system',
                    'agency_id'    => null,
                    'branch_id'    => null,
                    'weight'       => 1.00, // Johan V1 default — agency tunes per mapping
                    'sort_order'   => 10000 + ($config['sort_order'] ?? 0),
                    'scoring_mode' => 'count',
                    'is_enabled'   => false,  // hidden from manual/calendar pickers
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }

            // ── Step 2: ensure the instant mapping row exists.
            // Existence check is on (agency_id IS NULL, trigger_kind='instant',
            // slug) INCLUDING soft-deleted rows on purpose, matching the
            // M6.2-FIX ActivityCalendarMappingSeeder contract: never
            // duplicates, never resurrects admin-removed mappings, never
            // overwrites admin edits to value_per_event / is_active.
            $alreadyExists = DB::table('activity_definition_calendar_classes')
                ->whereNull('agency_id')
                ->where('trigger_kind', 'instant')
                ->where('slug', $slug)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            // NOTE: agency_id is NOT NULL on the M6.2 schema (created
            // via adcc_agency_fk constrained foreignId, NOT nullable).
            // Verified against migration 2026_06_16_120400 — agency_id
            // is a non-null FK. Seeding system-default rows therefore
            // requires either dropping that NOT NULL or seeding per-agency.
            // The SPINE-1 migration above is additive only — it did not
            // modify the agency_id constraint. To keep the migration
            // surgical, this seeder seeds system-default rows by
            // iterating known agencies. This matches the existing M6.2-FIX
            // pattern (per-agency seed) and means each agency gets its
            // own row at deploy time.
            $agencyIds = DB::table('agencies')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            foreach ($agencyIds as $agencyId) {
                $exists = DB::table('activity_definition_calendar_classes')
                    ->where('agency_id', $agencyId)
                    ->where('trigger_kind', 'instant')
                    ->where('slug', $slug)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('activity_definition_calendar_classes')->insert([
                    'agency_id'               => $agencyId,
                    'event_class'             => null,  // instant rows have no calendar event_class
                    'trigger_kind'            => 'instant',
                    'slug'                    => $slug,
                    'subject_type'            => $config['subject_type'],
                    'activity_definition_id'  => $defId,
                    'value_per_event'         => 1,   // Johan V1 default — agency tunes
                    'requires_feedback'       => false, // N/A for instant
                    'auto_revoke_after_hours' => null,  // N/A for instant
                    'daily_cap'               => $config['daily_cap'],
                    'back_date_limit_hours'   => 0,     // N/A for instant
                    'is_active'               => (bool) $config['is_active'],
                    'created_by'              => null,
                    'updated_by'              => null,
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ]);
            }
        }
    }
}
