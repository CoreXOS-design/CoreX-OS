<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-inspections.md §3.5 — the two windows, agency-
 * configurable, never hardcoded (§0.12). Read-time default pattern, matching
 * RentalApplicationQualifyingSetting: a null column resolves to the
 * DEFAULT_* constant, never written on read.
 */
class RentalInspectionSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_FAULT_REPORT_WINDOW_DAYS = 7;
    public const DEFAULT_SIGNING_WINDOW_DAYS = 7;

    /**
     * §15.6 — neutral, multi-agency-safe default. 'other' is always present
     * and always last, never agency-removable (§15.5's mandatory-reason
     * guarantee depends on an escape valve existing) — enforced in
     * refusalReasonPresetsFor() below, not left to agency-edited JSON to
     * get right.
     */
    public const DEFAULT_REFUSAL_REASON_PRESETS = [
        ['key' => 'disputes_condition', 'label' => 'Disputes the recorded condition'],
        ['key' => 'not_present', 'label' => 'Not present for the walkthrough'],
        ['key' => 'refused_no_reason', 'label' => 'Refused outright, no reason given'],
        ['key' => 'other', 'label' => 'Other'],
    ];

    /**
     * Johan, 2026-09-20 — a starting point, not a ruling: which of the
     * EXISTING property feature catalog's labels
     * (config('property-spaces.feature_categories')) are physical things an
     * inspector would actually check are present and working, versus a
     * marketing/legal/policy descriptor with nothing to inspect. Every
     * label here must exist verbatim in that catalog — this constant never
     * introduces a label of its own, only selects from the existing one, so
     * it can never drift out of sync with what Property24/Private Property
     * syndication and the property edit screen both already key on.
     *
     * The agency owns this list from here — Johan's own reasoning for why
     * this is a tick rather than an inferred rule: "Built-in Cupboards" is
     * both a feature AND an inspection item, "Investment" is neither, and
     * no rule reliably tells the two apart. This is one agency's starting
     * guess at that split, not a universal one.
     */
    public const DEFAULT_INSPECTION_FEATURE_LABELS = [
        // The Property
        'Air Conditioned', 'Balcony',
        // Security — physical installations, not access policies or area
        // classifications (e.g. "Gated Community", "24 Hour Guard").
        'Alarm System', 'Boomed Area', 'Burglar Bars', 'CCTV', 'Electric Fence',
        'Electric Gate', 'Guard House', 'Indoor Beams', 'Intercom', 'Outdoor Beams',
        'Perimeter Wall', 'Safe', 'Security Gate', 'Automated Garage Doors',
        // Connectivity — the physical outlet/equipment, not the service
        // subscription behind it (e.g. "ADSL", "Cable TV" stay off).
        'Fibre', 'Internet Port', 'Satellite Dish', 'Telephone Port', 'TV Port', 'Wi-Fi',
        // Sustainability — every one of these is a physical installation.
        'Backup Battery', 'Backup Water', 'Borehole', 'Gas Geyser', 'Gas Hob',
        'Gas Oven', 'Generator', 'Inverter', 'Septic Tank', 'Solar Geyser',
        'Solar Heating', 'Solar Panel', 'Water Tank',
    ];

    /**
     * .ai/specs/rental-inspections.md §45.4 item 1 (Build I-2) — the neutral,
     * floor-to-ceiling baseline every inspection checklist starts from.
     * Johan, 2026-09-20: "ceiling, walls, floors, windows, doors — that should
     * be a std"; approved 6 Oct 2026 (Q9a) as a floor-to-ceiling default list.
     * Replaces the thin five-item list this constant used to hold (45 of the 50
     * space types fell back to it: no skirting, no lights, no sockets).
     *
     * This is also the generic fallback for any room type no family below
     * claims — a future addition to config('property-spaces.all_space_types'),
     * or an agency's own custom room type — so nothing ever seeds an empty
     * checklist.
     */
    public const DEFAULT_ROOM_TYPE_ITEMS = [
        'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
        'Light fittings', 'Light switches', 'Plug sockets',
    ];

    /**
     * §45.4 item 1 — the baseline per room FAMILY: the building elements every
     * interior shares (DEFAULT_ROOM_TYPE_ITEMS) plus the family's own
     * fittings. Which family a room type belongs to is ROOM_TYPE_FAMILY below;
     * a type in neither map is the 'other' family. An agency's own saved list
     * for a type (customRoomTypeOverridesFor()) always wins over all of this —
     * defaults never overwrite an agency override.
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_ROOM_FAMILY_ITEMS = [
        'living' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets', 'Curtain rails and blinds',
        ],
        'bedroom' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets', 'Built-in cupboards', 'Curtain rails and blinds',
        ],
        'kitchen' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets',
            'Sink and taps', 'Stove, hob and oven', 'Extractor', 'Cupboards and tops',
        ],
        'bathroom' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets',
            'Bath', 'Shower', 'Basin', 'Toilet', 'Taps', 'Extractor fan', 'Mirror', 'Geyser access',
        ],
        'outbuilding' => [
            'Ceiling', 'Walls', 'Floors', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets',
        ],
        'covered_outdoor' => [
            'Roof or ceiling', 'Floor or paving', 'Walls or pillars', 'Railings and gates', 'Doors',
            'Light fittings', 'Plug sockets',
        ],
        'outdoor' => [
            'Fences and gates', 'Boundary and retaining walls', 'Paving and floors', 'Garden and lawn',
            'Roof, gutters and downspouts', 'Outside taps and irrigation', 'Light fittings', 'Plug sockets',
        ],
        'other' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets',
        ],
    ];

    /**
     * §45.4 item 1 — which family each of the 50 standard space types
     * (config('property-spaces.all_space_types')) belongs to. Every one of the
     * 50 is listed explicitly (a test pins that), so a type is never in the
     * 'other' family by an accident of being forgotten here.
     *
     * @var array<string, string>
     */
    public const ROOM_TYPE_FAMILY = [
        'Lounge' => 'living', 'TV Room' => 'living', 'Dining Room' => 'living', 'Reception Room' => 'living',
        'Bar' => 'living', 'Boardroom' => 'living', 'Braai Room' => 'living', 'Study' => 'living',
        'Office' => 'living', 'Loft' => 'living', 'Flatlet' => 'living', 'Studio' => 'living',
        'Gym' => 'living', 'Clubhouse' => 'living',
        'Bedroom' => 'bedroom', 'Domestic Room' => 'bedroom',
        'Kitchen' => 'kitchen', 'Scullery' => 'kitchen', 'Laundry Room' => 'kitchen',
        'Bathroom' => 'bathroom', 'Domestic Bathroom' => 'bathroom', 'Outside Toilet' => 'bathroom',
        'Garage' => 'outbuilding', 'Parking' => 'outbuilding', 'Storeroom' => 'outbuilding', 'Shed' => 'outbuilding',
        'Workshop' => 'outbuilding', 'Cellar' => 'outbuilding', 'Stable' => 'outbuilding', 'Pool Shed' => 'outbuilding',
        'Wendy House' => 'outbuilding', 'Boathouse' => 'outbuilding', 'Greenhouse' => 'outbuilding',
        'Sauna' => 'outbuilding', 'Changing Room' => 'outbuilding',
        'Patio' => 'covered_outdoor', 'Veranda' => 'covered_outdoor', 'Courtyard' => 'covered_outdoor',
        'Gazebo' => 'covered_outdoor', 'Lapa' => 'covered_outdoor',
        'Garden' => 'outdoor', 'Pool' => 'outdoor', 'Jacuzzi' => 'outdoor', 'Squash Court' => 'outdoor',
        'Tennis Court' => 'outdoor', 'Boat Launch' => 'outdoor', 'Jetty' => 'outdoor', 'Yard' => 'outdoor',
        'Entrance Hall' => 'other', 'Linen Room' => 'other',
    ];

    /**
     * §45.4 item 1 — the few types whose own fittings differ from their
     * family's. Everything not named here takes its family's list. (This
     * constant used to hold the transcribed lists for Kitchen/Bathroom/
     * Bedroom/Garage/Yard as the system default; those are now covered by
     * the families above, and any agency that saved its own list for a type —
     * Home Finders Coastal did, for 33 types — keeps it untouched.)
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE = [
        'Garage' => [
            'Ceiling', 'Walls', 'Floors', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets', 'Garage door and motor',
        ],
        'Parking' => ['Floors', 'Roof or shade structure', 'Boundary walls and gates', 'Light fittings'],
        'Pool' => [
            'Pool surface and tiles', 'Pump and motor', 'Pipes and fittings', 'Pool cleaner',
            'Pool cover or net', 'Pool fence and gate', 'Pool lights',
        ],
        'Scullery' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets', 'Sink and taps', 'Cupboards and tops',
        ],
        'Laundry Room' => [
            'Ceiling', 'Walls', 'Skirting', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Plug sockets', 'Sink and taps', 'Cupboards and tops',
        ],
        'Outside Toilet' => [
            'Ceiling', 'Walls', 'Floor covering', 'Windows', 'Doors',
            'Light fittings', 'Light switches', 'Toilet', 'Basin', 'Taps',
        ],
    ];

    /**
     * Johan, 2026-09-21, property 5792: rooms rendered in creation order —
     * "no logical way to line up the rooms as the inspection goes" —
     * despite rooms now carrying a real type (the room-type picker fix).
     * A sensible DEFAULT is a walking order by type: entrance/reception,
     * living/social, kitchen/domestic, bedrooms/private, bathrooms,
     * outside/leisure, then utility/storage/vehicle. Agency-configurable
     * (§16.4, `/corex/settings/rental-inspections`) — this is a starting
     * point for every new agency, never a single order forced on all of
     * them.
     *
     * Verified to cover config('property-spaces.all_space_types') exactly —
     * every one of its 50 types appears here once, no gaps, no extras — so
     * roomTypeWalkingOrderFor() never has to guess a fallback position for
     * a type this constant simply forgot.
     */
    public const DEFAULT_ROOM_TYPE_WALKING_ORDER = [
        // Entrance & Reception
        'Entrance Hall', 'Reception Room',
        // Living & Social
        'Lounge', 'TV Room', 'Dining Room', 'Bar', 'Boardroom', 'Braai Room', 'Lapa',
        // Kitchen & Domestic
        'Kitchen', 'Scullery', 'Laundry Room', 'Domestic Room', 'Domestic Bathroom', 'Linen Room',
        // Bedrooms & Private
        'Bedroom', 'Study', 'Office', 'Loft', 'Flatlet',
        // Bathrooms
        'Bathroom', 'Outside Toilet',
        // Outside & Leisure
        'Garden', 'Pool', 'Pool Shed', 'Jacuzzi', 'Patio', 'Veranda', 'Courtyard', 'Gazebo',
        'Greenhouse', 'Sauna', 'Gym', 'Squash Court', 'Tennis Court', 'Clubhouse', 'Boat Launch',
        'Boathouse', 'Jetty', 'Wendy House',
        // Utility, Storage & Vehicle
        'Garage', 'Parking', 'Storeroom', 'Shed', 'Workshop', 'Cellar', 'Stable',
        'Changing Room', 'Studio', 'Yard',
    ];

    /**
     * Johan, 2026-09-21, from Retha's real paper out-inspection form:
     * "Missing" means should-be-here-and-isn't (a deposit argument); N/A
     * means was-never-here (not an argument at all) — two different
     * meanings the app could previously only say one of. Her form also
     * uses a different vocabulary entirely (Good / OK / Bad) from ours —
     * the SET itself is agency-configurable, this constant is only the
     * starting point for a NEW agency, never forced on Retha's or anyone
     * else's.
     *
     * `requires_notes` generalizes §0.3's old hardcoded "anything but Good
     * needs a reason" rule beyond a literal 'good' key — an agency that
     * reduces or renames this set entirely still expresses which of ITS
     * states need a reason on record.
     *
     * Property 5792 progression-gate build, Johan, explicit ruling on the
     * default: "the conditions that assert something adverse — Damaged,
     * Not working, Missing, Other — require a note; Good, Fair and N/A do
     * not." Corrects 'fair' from true to false — this shipped default had
     * never actually been enforced anywhere until this build, so nothing
     * that already relied on it existed to break; an agency that has
     * already customized its own condition_states is entirely unaffected
     * either way (this constant is only ever read when that column is
     * still null).
     *
     * `severity` — .ai/specs/rental-inspections.md §36 (condition colours,
     * 2026-09-28, Johan's ruling on property 5294: "a condition and its
     * note must JUMP OUT"). Supersedes the original §27.2 `needs_attention`
     * boolean — that flag and this one were answering the same underlying
     * question ("does this condition mean the space needs attention")
     * through two different lenses, and letting both persist independently
     * would have been exactly the duplicate-source-of-truth this codebase's
     * own Architectural Laws forbid. One of four values: `blue` (calm —
     * Good/Fair), `red` (a real issue — Damaged/Not working/Missing),
     * `amber` (caution — Other), `grey` (neutral — N/A, "was never here,
     * not an argument at all"). Drives the selected condition button's own
     * colour in every rendering (editable, read-only, compare/predecessor
     * cell, signed PDF) AND — via conditionNeedsAttentionFor() below —
     * the recording screen's "Needs attention" filter and per-room issue
     * count: red/amber is what needs attention now, never a second,
     * independently-configurable flag that could silently disagree with
     * the colour an agent is looking at.
     *
     * @var array<int, array{key: string, label: string, requires_notes: bool, severity: string}>
     */
    public const DEFAULT_CONDITION_STATES = [
        ['key' => 'good', 'label' => 'Good', 'requires_notes' => false, 'severity' => 'blue'],
        ['key' => 'fair', 'label' => 'Fair', 'requires_notes' => false, 'severity' => 'blue'],
        ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => true, 'severity' => 'red'],
        ['key' => 'not_working', 'label' => 'Not working', 'requires_notes' => true, 'severity' => 'red'],
        ['key' => 'missing', 'label' => 'Missing', 'requires_notes' => true, 'severity' => 'red'],
        ['key' => 'other', 'label' => 'Other', 'requires_notes' => true, 'severity' => 'amber'],
        // Johan: "not an argument at all" — unlike every state above it,
        // N/A needs no justification on record, and (§27.2) nothing to
        // follow up on either.
        ['key' => 'n_a', 'label' => 'N/A', 'requires_notes' => false, 'severity' => 'grey'],
    ];

    /**
     * §36 — the four severity buckets a condition state can carry, and the
     * literal colour each one prints as. Shared by the settings edit form
     * (the picker's own options) and the signed PDF report (which cannot
     * use the live app's CSS custom properties — DomPDF has no theme, a
     * printed page has no dark mode) — the live screens themselves use the
     * equivalent CSS tokens (--brand-button/--ds-crimson/--ds-amber/
     * --text-secondary) directly, never this constant, so the two stay
     * visually aligned without the PDF depending on a browser stylesheet.
     */
    public const SEVERITY_COLORS = [
        'blue' => '#0ea5e9',
        'red' => '#c41e3a',
        'amber' => '#f59e0b',
        'grey' => '#6b7280',
    ];

    /** Human labels for the severity picker on the settings edit form. */
    public const SEVERITY_LABELS = [
        'blue' => 'Calm (blue)',
        'red' => 'Issue (red)',
        'amber' => 'Caution (amber)',
        'grey' => 'Neutral (grey)',
    ];

    /**
     * Johan, 2026-09-22 (Inspections-tab rebuild, item 5) — "All Good" bulk-
     * fill must use the agency's own baseline/quick-fill condition, never a
     * hardcoded 'good' string. Defaults to the 'good' key when present in
     * the agency's own vocabulary (DEFAULT_CONDITION_STATES' own baseline);
     * an agency that renamed or removed 'good' entirely still gets a sane
     * fallback via baselineConditionKeyFor()'s own resolution below.
     */
    public const DEFAULT_BASELINE_CONDITION_KEY = 'good';

    /**
     * .ai/specs/rental-inspection-form.md §13 (OMR scan reader) — the
     * fraction of a tick-box's interior that must read as dark ink for the
     * reader to count it marked. 0.35 is deliberately forgiving of a
     * slightly light photocopy or a phone photo's uneven lighting while
     * still well clear of paper-texture/scan noise; an agency scanning on
     * worse equipment can raise or lower it without a code change.
     */
    public const DEFAULT_OMR_MARK_THRESHOLD = 0.35;

    /**
     * AT-433 Part C, Johan's approved default for the photo note's
     * classification. "Defect" is what the printed report's own
     * end-of-document defect-list section reads — the whole reason this
     * vocabulary exists. Agency-configurable, same "every list is a
     * setting" rule as DEFAULT_CONDITION_STATES.
     */
    public const DEFAULT_PHOTO_NOTE_CLASSIFICATIONS = [
        ['key' => 'defect', 'label' => 'Defect'],
        ['key' => 'wear_and_tear', 'label' => 'Wear and tear'],
        ['key' => 'reference', 'label' => 'Reference'],
    ];

    /**
     * Johan, 2026-09-23, approved — the public inspection-report link's
     * expiry window. 90 days: long enough to cover the real post-move-out
     * follow-up window (deposit release, a dispute raised soon after
     * handover), short enough that a leaked/forwarded link doesn't stay
     * live indefinitely. A dispute surfacing years later (§0.2) is handled
     * by the agent issuing a fresh link from the inspection's own screen,
     * not by the original one never expiring.
     */
    public const DEFAULT_PUBLIC_LINK_EXPIRY_DAYS = 90;

    /** §46 — signing an inspection by a personal link: on, and live for 30 days from when it is issued. */
    public const DEFAULT_SIGNING_LINK_ENABLED = true;
    public const DEFAULT_SIGNING_LINK_EXPIRY_DAYS = 30;

    /**
     * §49 — Johan, 8 Oct 2026: do the three signatures (every tenant, the landlord, the agent) have to be in before an
     * inspection of this type can be completed? The agency's own setting, per type. In, Out and Interim (planned) are
     * required; Routine (unplanned) is optional. Keyed by the STORED type value; the column is `signatures_required_<key>`.
     */
    public const SIGNATURE_REQUIREMENT_COLUMNS = [
        'in' => 'signatures_required_in',
        'out' => 'signatures_required_out',
        'interim' => 'signatures_required_interim',
        'ad_hoc' => 'signatures_required_routine',
    ];
    public const DEFAULT_SIGNATURES_REQUIRED = [
        'in' => true,
        'out' => true,
        'interim' => true,
        'ad_hoc' => false,
    ];

    /**
     * §41, 2026-09-28, Johan's ruling — "auto-send on/off is an agency
     * setting, default ON." When true, a completed inspection's signed
     * report is filed to the property and emailed to every party
     * automatically (RentalInspectionRecordingController::complete(),
     * via the shared SignedDocumentDistributionService) the moment
     * every required party has signed or been dispositioned. An agency
     * that turns this off keeps the manual "Resend" button as its only
     * send path — filing to the property still happens either way
     * (that part was never optional).
     */
    public const DEFAULT_AUTO_SEND_REPORT_ENABLED = true;
    /** §45.6 — the inspector and the creating agent are copied on the completed report unless the agency says otherwise. */
    public const DEFAULT_REPORT_COPY_INSPECTOR = true;
    public const DEFAULT_REPORT_COPY_CREATOR = true;
    /** Sanity ceiling on the agency copy list — a mistyped paste must not become a mass mail-out. */
    public const MAX_REPORT_AGENCY_COPY_ADDRESSES = 10;

    protected $fillable = [
        'agency_id',
        'fault_report_window_days',
        'out_inspection_signing_window_days',
        'refusal_reason_presets',
        'inspection_feature_labels',
        'room_type_item_defaults',
        'room_type_walking_order',
        'custom_room_types',
        'condition_states',
        'photo_note_classifications',
        'baseline_condition_key',
        'require_notes_blocks_progression',
        'all_items_required_to_complete',
        'attended_as_labels',
        'move_out_classification_labels',
        'omr_mark_threshold',
        'public_link_expiry_days',
        // §46 — signing by personal link.
        'signing_link_enabled',
        'signing_link_expiry_days',
        // §49 — are the three signatures required to complete an inspection of each type?
        'signatures_required_in',
        'signatures_required_out',
        'signatures_required_interim',
        'signatures_required_routine',
        'auto_pair_photos_enabled',
        'auto_send_report_enabled',
        // §45.6 (Build I-4) — who else gets a copy of a completed report.
        'report_agency_copy_emails',
        'report_copy_inspector',
        'report_copy_creator',
        // §43 — schedule/reschedule/cancel notifications.
        'notify_tenant_enabled',
        'notify_landlord_enabled',
        'notify_inspector_enabled',
        'notify_via_mail_enabled',
        'notify_via_whatsapp_enabled',
        'minimum_notice_days',
        'reminder_days_before',
        // §45.7 (Build I-5) — due dates and the agency's own loaded interim dates.
        'planned_date_lead_days',
        'out_due_lead_days',
        'raise_due_inspections_enabled',
    ];

    protected $casts = [
        'fault_report_window_days' => 'integer',
        'out_inspection_signing_window_days' => 'integer',
        'refusal_reason_presets' => 'array',
        'inspection_feature_labels' => 'array',
        'room_type_item_defaults' => 'array',
        'room_type_walking_order' => 'array',
        'custom_room_types' => 'array',
        'condition_states' => 'array',
        'photo_note_classifications' => 'array',
        'require_notes_blocks_progression' => 'boolean',
        'all_items_required_to_complete' => 'boolean',
        'attended_as_labels' => 'array',
        'move_out_classification_labels' => 'array',
        'omr_mark_threshold' => 'float',
        'public_link_expiry_days' => 'integer',
        'signing_link_enabled' => 'boolean',
        'signing_link_expiry_days' => 'integer',
        'signatures_required_in' => 'boolean',
        'signatures_required_out' => 'boolean',
        'signatures_required_interim' => 'boolean',
        'signatures_required_routine' => 'boolean',
        'auto_pair_photos_enabled' => 'boolean',
        'auto_send_report_enabled' => 'boolean',
        'report_copy_inspector' => 'boolean',
        'report_copy_creator' => 'boolean',
        'notify_tenant_enabled' => 'boolean',
        'notify_landlord_enabled' => 'boolean',
        'notify_inspector_enabled' => 'boolean',
        'notify_via_mail_enabled' => 'boolean',
        'notify_via_whatsapp_enabled' => 'boolean',
        'minimum_notice_days' => 'integer',
        'reminder_days_before' => 'integer',
        'planned_date_lead_days' => 'integer',
        'out_due_lead_days' => 'integer',
        'raise_due_inspections_enabled' => 'boolean',
    ];

    /**
     * §45.7 item 6 (Build I-5) — the due-date settings. Read-time defaults like every resolver here: nothing saved reads as
     * the default, nothing is written on read. There is deliberately NO interim interval setting (Johan, 6 Oct, Q6 — nothing
     * in CoreX computes an interim date; the agency loads its own).
     */
    public const DEFAULT_PLANNED_DATE_LEAD_DAYS = 14;
    public const DEFAULT_OUT_DUE_LEAD_DAYS = 7;
    public const DEFAULT_RAISE_DUE_INSPECTIONS_ENABLED = true;

    /** Days before a LOADED interim date that the agent is first reminded (0 = remind on the day only). */
    public static function plannedDateLeadDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_PLANNED_DATE_LEAD_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('planned_date_lead_days');

        return $value !== null ? (int) $value : self::DEFAULT_PLANNED_DATE_LEAD_DAYS;
    }

    /** Days before a lease's out-inspection is due that it starts showing as due (0 = only from the day itself). */
    public static function outDueLeadDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_OUT_DUE_LEAD_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('out_due_lead_days');

        return $value !== null ? (int) $value : self::DEFAULT_OUT_DUE_LEAD_DAYS;
    }

    /** Whether the daily scan reminds the agent about In/Out inspections that are due. On the Due tab and Command Centre regardless. */
    public static function raiseDueInspectionsEnabledFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_RAISE_DUE_INSPECTIONS_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('raise_due_inspections_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_RAISE_DUE_INSPECTIONS_ENABLED;
    }

    /**
     * Property 5792, Johan: "whether the requirement BLOCKS progression or
     * merely warns must itself be an agency setting, defaulting to
     * block." True (the default) = RentalInspection::startAwaitingSignature()/
     * markCompleted() refuse while a required note is missing. False =
     * those same checks still run but never throw — the agent sees the
     * same "which rooms and items" information, just not as a hard stop.
     */
    public const DEFAULT_REQUIRE_NOTES_BLOCKS_PROGRESSION = true;

    public static function requireNotesBlocksProgressionFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_REQUIRE_NOTES_BLOCKS_PROGRESSION;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('require_notes_blocks_progression');

        return $value !== null ? (bool) $value : self::DEFAULT_REQUIRE_NOTES_BLOCKS_PROGRESSION;
    }

    /**
     * §45.3 (Build I-1) — whether an in/out inspection can complete (or go out for signature) while a
     * checklist item is still ungraded. N/A counts as graded. Default ON; an agency that wants to
     * complete with gaps turns it off and the same list is still shown, as a warning.
     */
    public const DEFAULT_ALL_ITEMS_REQUIRED_TO_COMPLETE = true;

    public static function allItemsRequiredToCompleteFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_ALL_ITEMS_REQUIRED_TO_COMPLETE;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('all_items_required_to_complete');

        return $value !== null ? (bool) $value : self::DEFAULT_ALL_ITEMS_REQUIRED_TO_COMPLETE;
    }

    /**
     * §45.5 (Build I-3) — the words for HOW someone attended an inspection. The four keys are fixed
     * (RentalInspectionAttendance::attendedAsKeys()); only the labels are the agency's own. These
     * defaults are neutral placeholders — the printed wording is Johan's call (§45.11).
     */
    public const DEFAULT_ATTENDED_AS_LABELS = [
        'self' => 'In person',
        'representative' => 'On behalf of the party',
        'co_occupant' => 'Co-occupant',
        'other' => 'Other',
    ];

    /** @return array<string, string> key => label, always all four keys */
    public static function attendedAsLabelsFor(?int $agencyId): array
    {
        $stored = null;
        if ($agencyId) {
            $stored = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('attended_as_labels');
            $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        }

        $labels = self::DEFAULT_ATTENDED_AS_LABELS;
        if (is_array($stored)) {
            foreach ($labels as $key => $default) {
                $candidate = isset($stored[$key]) && is_string($stored[$key]) ? trim($stored[$key]) : '';
                if ($candidate !== '') {
                    $labels[$key] = $candidate;
                }
            }
        }

        return $labels;
    }

    /**
     * §45.14 — the words for the three move-out classifications an agent can record against a marked item. The keys
     * are fixed (RentalInspectionItemFinding::DISPOSITION_*) and are all the code ever reads — logic never depends
     * on wording; only the labels are the agency's own. The defaults are the wording the screen has always used.
     */
    public const DEFAULT_MOVE_OUT_CLASSIFICATION_LABELS = [
        RentalInspectionItemFinding::DISPOSITION_PRE_EXISTING => 'Pre-existing',
        RentalInspectionItemFinding::DISPOSITION_LANDLORD_COST => 'Landlord\'s responsibility',
        RentalInspectionItemFinding::DISPOSITION_CHARGE_TENANT => 'Charge to tenant',
    ];

    /** @return array<string, string> key => label, always all three keys */
    public static function moveOutClassificationLabelsFor(?int $agencyId): array
    {
        $stored = null;
        if ($agencyId) {
            $stored = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('move_out_classification_labels');
            $stored = is_string($stored) ? json_decode($stored, true) : $stored;
        }

        $labels = self::DEFAULT_MOVE_OUT_CLASSIFICATION_LABELS;
        if (is_array($stored)) {
            foreach ($labels as $key => $default) {
                $candidate = isset($stored[$key]) && is_string($stored[$key]) ? trim($stored[$key]) : '';
                if ($candidate !== '') {
                    $labels[$key] = $candidate;
                }
            }
        }

        return $labels;
    }

    /**
     * Every disposition key => the words THIS agency's screen shows: the two fixed ones (fair wear and tear /
     * flagged) as shipped, plus the three the agency may reword. Keys and order are exactly
     * RentalInspectionItemFinding::DISPOSITION_LABELS — validation keeps using that, never these labels.
     *
     * @return array<string, string>
     */
    public static function dispositionLabelsFor(?int $agencyId): array
    {
        return array_replace(RentalInspectionItemFinding::DISPOSITION_LABELS, self::moveOutClassificationLabelsFor($agencyId));
    }

    public static function faultReportWindowDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_FAULT_REPORT_WINDOW_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('fault_report_window_days');

        return $value !== null ? (int) $value : self::DEFAULT_FAULT_REPORT_WINDOW_DAYS;
    }

    public static function signingWindowDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_SIGNING_WINDOW_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('out_inspection_signing_window_days');

        return $value !== null ? (int) $value : self::DEFAULT_SIGNING_WINDOW_DAYS;
    }

    public static function publicLinkExpiryDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_PUBLIC_LINK_EXPIRY_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('public_link_expiry_days');

        return $value !== null ? (int) $value : self::DEFAULT_PUBLIC_LINK_EXPIRY_DAYS;
    }

    /** §46 — whether this agency lets parties sign an inspection from a personal link. Read-time default: on. */
    public static function signingLinkEnabledFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_SIGNING_LINK_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('signing_link_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_SIGNING_LINK_ENABLED;
    }

    /**
     * §49 — must an inspection of this type carry the three signatures before it can be completed? Read-time default per
     * type (DEFAULT_SIGNATURES_REQUIRED): In / Out / Interim required, Routine optional. An unknown type is treated as
     * required — the stricter reading — never silently optional.
     */
    public static function signaturesRequiredFor(?int $agencyId, ?string $type): bool
    {
        $column = self::SIGNATURE_REQUIREMENT_COLUMNS[(string) $type] ?? null;
        if ($column === null) {
            return true;
        }
        $default = self::DEFAULT_SIGNATURES_REQUIRED[(string) $type];
        if (! $agencyId) {
            return $default;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value($column);

        return $value !== null ? (bool) $value : $default;
    }

    /** §46 — days a personal signing link stays live from the day it is issued. Read-time default: 30. */
    public static function signingLinkExpiryDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_SIGNING_LINK_EXPIRY_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('signing_link_expiry_days');

        return $value !== null && (int) $value > 0 ? (int) $value : self::DEFAULT_SIGNING_LINK_EXPIRY_DAYS;
    }

    /**
     * .ai/specs/rental-inspections.md §24.5/§24.7 — AT-433 Part B. Johan's
     * ruling, 2026-09-26: defaults ON — "we do complicated so the user
     * does simple... pairing forty photos by hand is exactly the work we
     * are supposed to be doing for them." Governs ONLY whether a caller
     * invokes RentalInspectionPhotoAutoPairService automatically the first
     * time an item's comparison is viewed (threaded to the frontend via
     * RentalInspection::tabPayloadFor()'s own `auto_pair_photos_enabled`
     * key) — it never gates the explicit "Auto-pair" button
     * (RentalInspectionRecordingController::autoPairPhotoMatches()), which
     * always runs on request regardless of this setting.
     */
    public const DEFAULT_AUTO_PAIR_PHOTOS_ENABLED = true;

    public static function autoPairPhotosEnabledFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_AUTO_PAIR_PHOTOS_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('auto_pair_photos_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_AUTO_PAIR_PHOTOS_ENABLED;
    }

    /** §41 — read-time-default resolver, same pattern as autoPairPhotosEnabledFor() above. */
    public static function autoSendReportEnabledFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_AUTO_SEND_REPORT_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('auto_send_report_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_AUTO_SEND_REPORT_ENABLED;
    }

    /**
     * §45.6 — the agency's own copy address(es) for a completed report, valid + lower-cased + de-duplicated,
     * in the order saved. Empty list when none are set (the default — an agency chooses to add one; nothing
     * is assumed). Every address is re-validated at read time too, so a bad value that reached the column by
     * some other route can never reach the mailer.
     *
     * @return array<int, string>
     */
    public static function reportAgencyCopyEmailsFor(?int $agencyId): array
    {
        if (! $agencyId) {
            return [];
        }

        return self::parseEmailList((string) static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('report_agency_copy_emails'))['valid'];
    }

    public static function reportCopyInspectorFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_REPORT_COPY_INSPECTOR;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('report_copy_inspector');

        return $value !== null ? (bool) $value : self::DEFAULT_REPORT_COPY_INSPECTOR;
    }

    public static function reportCopyCreatorFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_REPORT_COPY_CREATOR;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('report_copy_creator');

        return $value !== null ? (bool) $value : self::DEFAULT_REPORT_COPY_CREATOR;
    }

    /**
     * Splits a typed/pasted list (commas, semicolons, spaces or new lines) into valid and invalid addresses.
     * Trimmed, lower-cased, de-duplicated; order kept.
     *
     * @return array{valid: array<int, string>, invalid: array<int, string>}
     */
    public static function parseEmailList(string $raw): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $candidate) {
            $candidate = mb_strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $candidate;
                continue;
            }
            $valid[$candidate] = $candidate;
        }

        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    /**
     * §15.6 — 'other' is guaranteed present and last regardless of what an
     * agency has edited/saved, so §15.5's mandatory-reason rule always has
     * an escape valve. Never trusted to agency-edited JSON alone.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function refusalReasonPresetsFor(?int $agencyId): array
    {
        $presets = null;
        if ($agencyId) {
            $presets = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('refusal_reason_presets');
            $presets = is_string($presets) ? json_decode($presets, true) : $presets;
        }

        $presets = is_array($presets) && $presets !== [] ? $presets : self::DEFAULT_REFUSAL_REASON_PRESETS;

        $withoutOther = array_values(array_filter($presets, fn ($p) => ($p['key'] ?? null) !== 'other'));
        $withoutOther[] = ['key' => 'other', 'label' => 'Other'];

        return $withoutOther;
    }

    /**
     * Which of the property feature catalog's labels
     * (config('property-spaces.feature_categories')) count as inspection
     * items when a checklist is seeded from a property's advertising
     * features. Intersected against the LIVE catalog on every read, so an
     * agency's saved list can never resurrect a label the catalog has since
     * dropped, and a catalog addition is simply absent until the agency
     * opts in — never silently included. An explicitly empty saved list
     * (an agency that wants nothing seeded from features) is a real,
     * preserved state, not coerced back to the default.
     *
     * @return array<int, string>
     */
    public static function inspectionFeatureLabelsFor(?int $agencyId): array
    {
        $labels = null;
        if ($agencyId) {
            $labels = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('inspection_feature_labels');
            $labels = is_string($labels) ? json_decode($labels, true) : $labels;
        }

        $labels = is_array($labels) ? $labels : self::DEFAULT_INSPECTION_FEATURE_LABELS;

        $catalog = collect(config('property-spaces.feature_categories', []))
            ->flatMap(fn ($category) => $category['features'] ?? [])
            ->all();

        return array_values(array_intersect($labels, $catalog));
    }

    /**
     * The RAW, sparse per-agency overrides only — exactly what this agency
     * has actually customized, nothing merged in. This is what the settings
     * screen's edit form seeds its editable row list from (a type NOT in
     * this list renders no row and stays on the generic baseline); the
     * merged, everything-covered view lives in roomTypeItemDefaultsFor()
     * below, which is what the seeder calls.
     *
     * @return array<string, array<int, string>>
     */
    public static function customRoomTypeOverridesFor(?int $agencyId): array
    {
        $overrides = null;
        if ($agencyId) {
            $overrides = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('room_type_item_defaults');
            $overrides = is_string($overrides) ? json_decode($overrides, true) : $overrides;
        }

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Ordered default inspection items for every known space type
     * (config('property-spaces.all_space_types')), agency overrides merged
     * on top. Built at read time from the live space-type list, never a
     * static array of type keys — a future addition to that config is
     * covered automatically, with DEFAULT_ROOM_TYPE_ITEMS, until an agency
     * customizes it.
     *
     * @return array<string, array<int, string>>
     */
    public static function roomTypeItemDefaultsFor(?int $agencyId): array
    {
        $overrides = self::customRoomTypeOverridesFor($agencyId);

        $defaults = [];
        foreach (self::knownRoomTypeKeysFor($agencyId) as $type) {
            $defaults[$type] = self::defaultItemsForType($type);
        }

        return array_merge($defaults, $overrides);
    }

    /**
     * §45.4 item 1 — the SYSTEM default checklist for one room type, before
     * any agency override: the type's own list if it has one, else its
     * family's, else the generic baseline. An agency's own custom room type
     * is in no family, so it lands on the generic baseline.
     *
     * @return array<int, string>
     */
    public static function defaultItemsForType(string $type): array
    {
        if (isset(self::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE[$type])) {
            return self::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE[$type];
        }

        $family = self::ROOM_TYPE_FAMILY[$type] ?? 'other';

        return self::DEFAULT_ROOM_FAMILY_ITEMS[$family] ?? self::DEFAULT_ROOM_TYPE_ITEMS;
    }

    /** How many custom room types an agency may hold (active + archived) — absorbs a runaway list, never errors. */
    public const MAX_CUSTOM_ROOM_TYPES = 200;

    /** Longest custom room-type label; property_rooms.type is varchar(60) and the key is derived from it. */
    public const CUSTOM_ROOM_TYPE_LABEL_MAX = 60;

    /** Every custom key starts with this, so one can never equal a standard type's name (none starts with it). */
    public const CUSTOM_ROOM_TYPE_KEY_PREFIX = 'custom_';

    /**
     * §45.4 item 3 — the agency's OWN room types: every one it has ever added,
     * archived ones included (an archived type is kept so rooms already filed
     * under it keep resolving). Read-time default: nothing saved = none.
     *
     * @return array<int, array{key: string, label: string, archived: bool}>
     */
    public static function customRoomTypesFor(?int $agencyId): array
    {
        $raw = null;
        if ($agencyId) {
            $raw = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('custom_room_types');
            $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        }

        $types = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if ($key === '' || $label === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $types[] = ['key' => $key, 'label' => $label, 'archived' => (bool) ($row['archived'] ?? false)];
        }

        return $types;
    }

    /**
     * §45.4 item 3 — what an agent may PICK for a new room: the 50 standard
     * types (key = label, as they have always been stored) then the agency's
     * own active custom types. Archived custom types are not offered.
     *
     * @return array<int, array{key: string, label: string, custom: bool}>
     */
    public static function roomTypeOptionsFor(?int $agencyId): array
    {
        $options = [];
        foreach (config('property-spaces.all_space_types', []) as $type) {
            $options[] = ['key' => $type, 'label' => $type, 'custom' => false];
        }
        foreach (self::customRoomTypesFor($agencyId) as $custom) {
            if (! $custom['archived']) {
                $options[] = ['key' => $custom['key'], 'label' => $custom['label'], 'custom' => true];
            }
        }

        return $options;
    }

    /** @return array<int, string> the keys a NEW room may be created with (validation list). */
    public static function selectableRoomTypeKeysFor(?int $agencyId): array
    {
        return array_column(self::roomTypeOptionsFor($agencyId), 'key');
    }

    /**
     * Every key a room can already carry — the standard 50 plus ALL the
     * agency's custom types, archived included. Used wherever existing rooms
     * must still resolve (walking order, item defaults, sort position).
     *
     * @return array<int, string>
     */
    public static function knownRoomTypeKeysFor(?int $agencyId): array
    {
        return array_merge(
            config('property-spaces.all_space_types', []),
            array_column(self::customRoomTypesFor($agencyId), 'key'),
        );
    }

    /** A room type's display label: a custom type's label, else the key itself (standard types ARE their label). */
    public static function roomTypeLabelFor(?int $agencyId, string $key): string
    {
        foreach (self::customRoomTypesFor($agencyId) as $custom) {
            if ($custom['key'] === $key) {
                return $custom['label'];
            }
        }

        return $key;
    }

    /**
     * §45.4 item 3 — fold a submitted custom-room-type list into the saved
     * one. Pure (no DB), so the settings page and the Setup Wizard run the
     * exact same rules and a test can drive it directly. Rules:
     *  - a submitted row whose key matches a saved type keeps that key
     *    (a rename never re-keys, so rooms already filed under it never
     *    orphan); a row with no known key is NEW and gets a generated key —
     *    a posted key is never trusted;
     *  - a saved ACTIVE type missing from the submission is ARCHIVED (the
     *    UI's "Remove" is an archive; a saved ARCHIVED one that is not
     *    submitted stays archived — the wizard never renders those);
     *  - a label that is empty is ignored; a label that duplicates a standard
     *    type or another of the agency's own (case/space-insensitive, archived
     *    included) is skipped and reported, never saved twice;
     *  - the list is capped at MAX_CUSTOM_ROOM_TYPES.
     *
     * @param  array<int, array{key: string, label: string, archived: bool}>  $existing
     * @param  array<int, mixed>  $submitted
     * @return array{types: array<int, array{key: string, label: string, archived: bool}>, skipped: array<int, array{label: string, reason: string}>}
     */
    public static function mergeCustomRoomTypes(array $existing, array $submitted): array
    {
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));

        $byKey = [];
        foreach ($existing as $row) {
            $byKey[$row['key']] = $row;
        }

        $taken = [];
        foreach (config('property-spaces.all_space_types', []) as $standard) {
            $taken[$norm($standard)] = 'standard';
        }

        $result = [];
        $seenKeys = [];
        $skipped = [];

        $submittedKeys = [];
        foreach ($submitted as $row) {
            $k = is_array($row) ? trim((string) ($row['key'] ?? '')) : '';
            if ($k !== '') {
                $submittedKeys[$k] = true;
            }
        }

        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', (string) ($row['label'] ?? '')));
            $label = mb_substr($label, 0, self::CUSTOM_ROOM_TYPE_LABEL_MAX);
            $postedKey = trim((string) ($row['key'] ?? ''));
            $archived = filter_var($row['archived'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($postedKey !== '' && isset($byKey[$postedKey])) {
                // An existing type: keep its key. A blank label leaves the saved one alone.
                $current = $byKey[$postedKey];
                $seenKeys[$postedKey] = true;
                $newLabel = $label !== '' ? $label : $current['label'];

                $clash = $taken[$norm($newLabel)] ?? null;
                foreach ($result as $kept) {
                    if ($norm($kept['label']) === $norm($newLabel)) {
                        $clash = 'custom';
                    }
                }
                if ($clash !== null && $norm($newLabel) !== $norm($current['label'])) {
                    $skipped[] = ['label' => $newLabel, 'reason' => $clash === 'standard'
                        ? 'that is already a standard room type'
                        : 'you already have a room type with that name'];
                    $newLabel = $current['label'];
                }

                $result[] = ['key' => $postedKey, 'label' => $newLabel, 'archived' => $archived];

                continue;
            }

            if ($label === '') {
                continue;
            }

            $normLabel = $norm($label);
            if (isset($taken[$normLabel])) {
                $skipped[] = ['label' => $label, 'reason' => 'that is already a standard room type'];

                continue;
            }
            $duplicate = false;
            foreach (array_merge($result, $existing) as $other) {
                if ($norm($other['label']) === $normLabel) {
                    // Removed and re-added in one save: the same type, not a duplicate — keep its key, un-archive it.
                    if (isset($byKey[$other['key']]) && ! isset($submittedKeys[$other['key']]) && ! isset($seenKeys[$other['key']]) && ! ($other['archived'] ?? false)) {
                        $seenKeys[$other['key']] = true;
                        $result[] = ['key' => $other['key'], 'label' => $label, 'archived' => false];
                        $duplicate = true;
                        break;
                    }
                    $skipped[] = ['label' => $label, 'reason' => $other['archived'] ?? false
                        ? 'you already have that room type, archived — restore it instead'
                        : 'you already have a room type with that name'];
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                continue;
            }

            if (count($result) + count(array_diff_key($byKey, $seenKeys)) >= self::MAX_CUSTOM_ROOM_TYPES) {
                $skipped[] = ['label' => $label, 'reason' => 'the limit of ' . self::MAX_CUSTOM_ROOM_TYPES . ' room types has been reached'];

                continue;
            }

            $usedKeys = array_merge(array_keys($byKey), array_column($result, 'key'));
            $slug = trim(\Illuminate\Support\Str::slug(mb_substr($label, 0, 40), '_'), '_');
            $base = self::CUSTOM_ROOM_TYPE_KEY_PREFIX . ($slug !== '' ? $slug : 'type');
            $key = $base;
            for ($n = 2; in_array($key, $usedKeys, true); $n++) {
                $key = $base . '_' . $n;
            }

            $result[] = ['key' => $key, 'label' => $label, 'archived' => false];
        }

        // Saved types the submission left out: active -> archived, archived -> stays archived.
        foreach ($existing as $row) {
            if (! isset($seenKeys[$row['key']])) {
                $result[] = ['key' => $row['key'], 'label' => $row['label'], 'archived' => true];
            }
        }

        return ['types' => array_values($result), 'skipped' => $skipped];
    }

    /**
     * Single-type convenience lookup for the inspection seeder (cc4,
     * rental-inspections rework) — one call per room instance, keyed by
     * its parent space type. Falls back to the generic baseline for a type
     * this agency hasn't customized AND for a type absent from the
     * space-type catalog entirely, so a future or renamed type never seeds
     * an empty checklist.
     *
     * @return array<int, string>
     */
    public static function roomTypeItemsFor(?int $agencyId, string $spaceType): array
    {
        $defaults = self::roomTypeItemDefaultsFor($agencyId);

        return $defaults[$spaceType] ?? self::DEFAULT_ROOM_TYPE_ITEMS;
    }

    /**
     * The agency's own room-type walking order — every space type, in the
     * order an inspector should walk them. Read-time default pattern like
     * every other resolver here: an agency that hasn't customized this
     * gets DEFAULT_ROOM_TYPE_WALKING_ORDER outright, never a partial or
     * empty list.
     *
     * A saved order can go stale in two directions as the catalog changes
     * over time: a type the agency ordered that the catalog has since
     * dropped is silently excluded (array_intersect — never sorts by a
     * type that no longer exists), and a type the catalog gained after the
     * agency last saved is appended, in the DEFAULT order's own relative
     * position, rather than left with no position to sort by at all.
     *
     * @return array<int, string>
     */
    public static function roomTypeWalkingOrderFor(?int $agencyId): array
    {
        $order = null;
        if ($agencyId) {
            $order = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('room_type_walking_order');
            $order = is_string($order) ? json_decode($order, true) : $order;
        }
        $order = is_array($order) && $order !== [] ? $order : self::DEFAULT_ROOM_TYPE_WALKING_ORDER;

        $catalog = self::knownRoomTypeKeysFor($agencyId);
        $order = array_values(array_intersect($order, $catalog));

        $missing = array_values(array_diff($catalog, $order));
        if ($missing !== []) {
            $defaultMissing = array_values(array_intersect(self::DEFAULT_ROOM_TYPE_WALKING_ORDER, $missing));
            // Then anything with no default position — an agency's own custom type.
            $order = array_merge($order, $defaultMissing, array_values(array_diff($missing, $defaultMissing)));
        }

        return $order;
    }

    /**
     * Where a room of this $type sits in the agency's walking order. A
     * type absent even after roomTypeWalkingOrderFor()'s own catalog
     * reconciliation (shouldn't happen given a real PropertyRoom.type, but
     * defends against a hand-edited/legacy value) sorts last rather than
     * throwing.
     */
    public static function roomTypeWalkingPositionFor(?int $agencyId, string $type): int
    {
        $order = self::roomTypeWalkingOrderFor($agencyId);
        $position = array_search($type, $order, true);

        return $position === false ? count($order) : $position;
    }

    /**
     * A room's default sort_order: its type's walking-order position,
     * combined with a natural-numeric read of its own label ("Bedroom 2"
     * -> 2) as a tiebreak among same-type rooms — Johan: "natural-numeric,
     * NOT alphabetical: alphabetical gives 1, 10, 2." No sibling-room query
     * needed; the tiebreak comes only from this room's own label text.
     *
     * 2026-09-21, cc1 (found live on property 5792, testing this exact
     * method): the number MUST come from the END of the label, never the
     * first digit anywhere in it. The original `/(\d+)/` matched the "1"
     * inside test data labelled "Bedroom CC1 Verify", colliding it with a
     * real "Bedroom 1" — harmless against Johan's own clean labels today,
     * but a real bug the moment any agent types a label with an incidental
     * digit in it: a unit number, a floor, "Flat 2 Bedroom", "Garage B1".
     * Anchored to the end of the string (`\s*$`) so a genuinely trailing
     * instance number ("Bedroom 1", "Garage B1") is read correctly while an
     * incidental digit earlier in the label ("Flat 2 Bedroom", "Bedroom
     * CC1 Verify") is correctly ignored.
     *
     * The numeric tiebreak is cosmetic ordering only, clamped to 0-999 and
     * never persisted as the room's identity or its `type` — a room with
     * no trailing number in its label (e.g. bare "Study") ties at 0 with
     * every other untrailing-numbered room of the same type; callers that
     * order by this value MUST add `id` as a secondary sort key (already
     * done everywhere this is consumed) so that tie has a stable,
     * predictable resolution rather than flipping between page loads.
     */
    public static function defaultRoomSortOrderFor(?int $agencyId, string $type, string $label): int
    {
        $position = self::roomTypeWalkingPositionFor($agencyId, $type);

        $numeric = 0;
        if (preg_match('/(\d+)\s*$/', $label, $matches)) {
            $numeric = min((int) $matches[1], 999);
        }

        return ($position * 1000) + $numeric;
    }

    /**
     * The agency's own condition-state vocabulary — every state an
     * inspector can grade an item as, in the order they're offered.
     * Read-time default pattern like every other resolver here: an agency
     * that hasn't customized this gets DEFAULT_CONDITION_STATES outright.
     *
     * Deliberately does NOT intersect against any external catalog (unlike
     * inspectionFeatureLabelsFor()/roomTypeWalkingOrderFor()) — this
     * vocabulary belongs entirely to the agency, not to a shared property
     * catalog, so a saved custom set (e.g. Retha's Good/OK/Bad) is trusted
     * as-is once it's shaped correctly. Malformed rows (missing key/label)
     * are dropped rather than crashing a read.
     *
     * §36 — `severity` is backfilled onto every returned row so a row saved
     * before this field existed (or before the §27.2 `needs_attention`
     * boolean it supersedes) reads identically here, in the settings edit
     * form, and client-side — one normalized shape, never three call sites
     * quietly disagreeing about what a missing key means. A row that still
     * only carries the old `needs_attention` boolean maps `true` → `red`
     * (the safe "flag it" default) and `false` → `blue` (calm); a row with
     * neither key at all defaults straight to `red` — same "unknown state
     * is never silently filtered out of view" reasoning every other
     * resolver on this class already uses.
     *
     * @return array<int, array{key: string, label: string, requires_notes: bool, severity: string}>
     */
    public static function conditionStatesFor(?int $agencyId): array
    {
        $states = null;
        if ($agencyId) {
            $states = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('condition_states');
            $states = is_string($states) ? json_decode($states, true) : $states;
        }

        if (! is_array($states) || $states === []) {
            return self::DEFAULT_CONDITION_STATES;
        }

        $states = array_values(array_filter($states, fn ($s) => is_array($s) && ! empty($s['key']) && isset($s['label'])));

        return array_map(function ($s) {
            if (in_array($s['severity'] ?? null, array_keys(self::SEVERITY_COLORS), true)) {
                return $s;
            }

            $s['severity'] = array_key_exists('needs_attention', $s)
                ? ($s['needs_attention'] ? 'red' : 'blue')
                : 'red';

            return $s;
        }, $states);
    }

    /**
     * §0.3 — does picking this condition need a reason on record. Johan on
     * N/A specifically: "not an argument at all" — unlike every problem
     * state, it needs no justification. A condition key absent from the
     * agency's own configured set (shouldn't happen given real UI input,
     * but defends a stale/replayed request) defaults to TRUE — an unknown
     * state is treated as needing an explanation, never silently waved
     * through.
     */
    public static function conditionRequiresNotesFor(?int $agencyId, string $conditionKey): bool
    {
        $state = collect(self::conditionStatesFor($agencyId))->firstWhere('key', $conditionKey);

        return $state === null ? true : (bool) ($state['requires_notes'] ?? true);
    }

    /**
     * .ai/specs/rental-inspections.md §36 — does this condition mean the
     * space needs attention (the recording screen's problem filter + the
     * per-room issue count). Derived from severity, never a second stored
     * flag: red/amber IS "needs attention", blue/grey is not. A condition
     * key absent from the agency's own configured set resolves through
     * conditionSeverityFor()'s own unknown-key default (`red`), so an
     * unknown/unconfigured state is never silently filtered out of view.
     */
    public static function conditionNeedsAttentionFor(?int $agencyId, string $conditionKey): bool
    {
        return in_array(self::conditionSeverityFor($agencyId, $conditionKey), ['red', 'amber'], true);
    }

    /**
     * §15 (AT-447) — the inspection Follow-up block's own filter: the SAME
     * underlying question as conditionNeedsAttentionFor() ("does this
     * condition mean something needs doing"), reusing the agency's EXISTING
     * configured severity — deliberately not a second, independently-
     * configurable flag, per this class's own Architectural Law against
     * exactly that (see severity's own docblock above — `needs_attention`
     * was already merged into `severity` once for this reason).
     *
     * The ONE deliberate difference from conditionNeedsAttentionFor(): an
     * unmapped/legacy condition key defaults to `false` here, not
     * conditionSeverityFor()'s `red` fallback. That fallback exists to make
     * an agent LOOK at something unclassified while actively recording —
     * the right default for a live data-entry screen. It is the WRONG
     * default for a list of actions nobody asked the system to propose:
     * Johan, 2026-10-05, QA1 property walk — an "N/A" observation (already
     * fixed by reusing severity at all: N/A is configured grey) and a
     * stray "OK CC1" value (not configured anywhere — a value that reached
     * the column through a path that bypassed the agency's own vocabulary,
     * e.g. an OMR scan import) both appeared in the Follow-up block as if
     * they were faults.
     */
    public static function conditionNeedsFollowUpFor(?int $agencyId, string $conditionKey): bool
    {
        $state = collect(self::conditionStatesFor($agencyId))->firstWhere('key', $conditionKey);
        if ($state === null) {
            return false;
        }

        return in_array($state['severity'] ?? 'blue', ['red', 'amber'], true);
    }

    /**
     * .ai/specs/rental-inspections.md §36 — the agency's own configured
     * severity for one condition key: `blue`/`red`/`amber`/`grey`, driving
     * the selected condition button's colour (every rendering — editable,
     * read-only, compare/predecessor cell, signed PDF), the item/room note
     * callout's tint, and (via conditionNeedsAttentionFor() above) the
     * "Needs attention" filter and per-room issue count. A condition key
     * absent from the agency's own configured set defaults to `red` — the
     * safe "flag it, don't hide it" default every other unknown-key path
     * on this class already uses.
     */
    public static function conditionSeverityFor(?int $agencyId, string $conditionKey): string
    {
        $state = collect(self::conditionStatesFor($agencyId))->firstWhere('key', $conditionKey);
        $severity = $state['severity'] ?? 'red';

        return in_array($severity, array_keys(self::SEVERITY_COLORS), true) ? $severity : 'red';
    }

    /**
     * AT-433 Part C — the agency's own photo-note classification
     * vocabulary. Read-time default pattern like every other resolver
     * here: an agency that hasn't customized this gets
     * DEFAULT_PHOTO_NOTE_CLASSIFICATIONS outright. Deliberately does NOT
     * intersect against any external catalog (same reasoning as
     * conditionStatesFor() — this vocabulary belongs entirely to the
     * agency). Malformed rows (missing key/label) are dropped rather than
     * crashing a read.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function photoNoteClassificationsFor(?int $agencyId): array
    {
        $states = null;
        if ($agencyId) {
            $states = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('photo_note_classifications');
            $states = is_string($states) ? json_decode($states, true) : $states;
        }

        if (! is_array($states) || $states === []) {
            return self::DEFAULT_PHOTO_NOTE_CLASSIFICATIONS;
        }

        return array_values(array_filter($states, fn ($s) => is_array($s) && ! empty($s['key']) && isset($s['label'])));
    }

    /**
     * Item 5 (2026-09-22) — which of this agency's OWN configured condition
     * states "All Good" bulk-fills unrecorded items to. Never hardcoded to
     * the word "Good": a saved key is honoured only if it still names one of
     * the agency's current condition states; otherwise this prefers a
     * configured state that needs no reason (so a one-tap bulk action can
     * never be blocked waiting on typed notes for every item it touches),
     * falling back to the agency's first configured state if every one of
     * its states requires a reason.
     */
    public static function baselineConditionKeyFor(?int $agencyId): string
    {
        $states = self::conditionStatesFor($agencyId);

        $saved = null;
        if ($agencyId) {
            $saved = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('baseline_condition_key');
        }
        if (is_string($saved) && $saved !== '' && collect($states)->contains('key', $saved)) {
            return $saved;
        }

        if (collect($states)->contains('key', self::DEFAULT_BASELINE_CONDITION_KEY)) {
            return self::DEFAULT_BASELINE_CONDITION_KEY;
        }

        $noReasonNeeded = collect($states)->first(fn ($s) => empty($s['requires_notes']));

        return $noReasonNeeded['key'] ?? ($states[0]['key'] ?? self::DEFAULT_BASELINE_CONDITION_KEY);
    }

    /** .ai/specs/rental-inspection-form.md §13 — read-time default, same pattern as every other column here. */
    public static function omrMarkThresholdFor(?int $agencyId): float
    {
        if (! $agencyId) {
            return self::DEFAULT_OMR_MARK_THRESHOLD;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('omr_mark_threshold');

        return $value !== null ? (float) $value : self::DEFAULT_OMR_MARK_THRESHOLD;
    }

    /**
     * .ai/specs/rental-inspections.md §43 — which parties are notified on
     * schedule/reschedule/cancel, which channel(s), the minimum notice an
     * agent should give (warns, never blocks — enforced by the controller,
     * not here), and whether/when a reminder fires. All default ON except
     * WhatsApp (see RentalInspectionNotificationService's own docblock for
     * why that channel is logged as queued rather than actually sent).
     */
    public const DEFAULT_NOTIFY_TENANT_ENABLED = true;
    public const DEFAULT_NOTIFY_LANDLORD_ENABLED = true;
    public const DEFAULT_NOTIFY_INSPECTOR_ENABLED = true;
    public const DEFAULT_NOTIFY_VIA_MAIL_ENABLED = true;
    public const DEFAULT_NOTIFY_VIA_WHATSAPP_ENABLED = false;
    public const DEFAULT_MINIMUM_NOTICE_DAYS = 1;
    public const DEFAULT_REMINDER_DAYS_BEFORE = 1;

    public static function notifyTenantFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_NOTIFY_TENANT_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('notify_tenant_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_NOTIFY_TENANT_ENABLED;
    }

    public static function notifyLandlordFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_NOTIFY_LANDLORD_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('notify_landlord_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_NOTIFY_LANDLORD_ENABLED;
    }

    public static function notifyInspectorFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_NOTIFY_INSPECTOR_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('notify_inspector_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_NOTIFY_INSPECTOR_ENABLED;
    }

    public static function notifyViaMailFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_NOTIFY_VIA_MAIL_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('notify_via_mail_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_NOTIFY_VIA_MAIL_ENABLED;
    }

    public static function notifyViaWhatsappFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_NOTIFY_VIA_WHATSAPP_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('notify_via_whatsapp_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_NOTIFY_VIA_WHATSAPP_ENABLED;
    }

    public static function minimumNoticeDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_MINIMUM_NOTICE_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('minimum_notice_days');

        return $value !== null ? (int) $value : self::DEFAULT_MINIMUM_NOTICE_DAYS;
    }

    /** 0 = reminder off, per spec. */
    public static function reminderDaysBeforeFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_REMINDER_DAYS_BEFORE;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('reminder_days_before');

        return $value !== null ? (int) $value : self::DEFAULT_REMINDER_DAYS_BEFORE;
    }
}
