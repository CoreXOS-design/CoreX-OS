<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Highlighter collection expansion, 2026-09-09 — Johan: "an agency can have
 * 10 highlighters set up, each with their own label." Full CRUD, agency-
 * owned, archive-not-delete (see the migration for why `deleted_at` IS the
 * archived flag here).
 *
 * A saved mark stores this row's `id` (not a copy of its colour) — Johan:
 * "it is the same pen, refilled with different ink." Recolouring a
 * highlighter here changes every existing mark drawn with it, because
 * rendering always looks the colour up live, never from a value frozen at
 * draw time.
 */
class RentalApplicationHighlighter extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const ROLE_AGENT = 'agent';
    public const ROLE_AUTHORISER = 'authoriser';
    public const ROLE_BOTH = 'both';
    public const ROLE_SCOPES = [self::ROLE_AGENT, self::ROLE_AUTHORISER, self::ROLE_BOTH];

    public const CAPTURE_TYPE_INCOME = 'income';
    public const CAPTURE_TYPE_EXPENSE = 'expense';
    /** The only two real values — everything else (Unpaid, Electricity, any custom label) is null: "draws, never captures," a deliberate default, not an accident. See the 2026-09-13 migration's own docblock for why this exists at all. */
    public const CAPTURE_TYPES = [self::CAPTURE_TYPE_INCOME, self::CAPTURE_TYPE_EXPENSE];

    protected $fillable = ['agency_id', 'label', 'color', 'capture_type', 'role_scope', 'sort_order', 'created_by'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Nullable and never backfilled — the 39 rows that existed before this
     * column shipped (including the six seeded defaults) have no creator on
     * record and never will; the settings screen says so honestly rather
     * than guessing.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The six starting highlighters — Johan: "sensible defaults so it works
     * out of the box... nobody should have to configure anything before the
     * feature works." Same colours this feature has shipped with all along
     * (first as the hardcoded six, then as RentalApplicationMarkColorSetting's
     * defaults) — an agency that never opens this settings screen sees no
     * visual change. `legacy_category`/`legacy_role` are NOT persisted
     * columns — they exist only so the one-time backfill migration can map
     * an old mark's `category`+`author_role` onto the matching seeded row;
     * once seeded, a highlighter is just label+colour+role_scope+order,
     * nothing else.
     */
    public const DEFAULT_SEED = [
        ['label' => 'Income', 'color' => '#a7f3cf', 'role_scope' => self::ROLE_AGENT, 'legacy_category' => 'income', 'legacy_role' => 'agent'],
        ['label' => 'Expense', 'color' => '#fde8a8', 'role_scope' => self::ROLE_AGENT, 'legacy_category' => 'expense', 'legacy_role' => 'agent'],
        ['label' => 'Unpaid', 'color' => '#fbcdc9', 'role_scope' => self::ROLE_AGENT, 'legacy_category' => 'unpaid', 'legacy_role' => 'agent'],
        ['label' => 'Income', 'color' => '#4ec99a', 'role_scope' => self::ROLE_AUTHORISER, 'legacy_category' => 'income', 'legacy_role' => 'authoriser'],
        ['label' => 'Expense', 'color' => '#f2b33d', 'role_scope' => self::ROLE_AUTHORISER, 'legacy_category' => 'expense', 'legacy_role' => 'authoriser'],
        ['label' => 'Unpaid', 'color' => '#ee7c72', 'role_scope' => self::ROLE_AUTHORISER, 'legacy_category' => 'unpaid', 'legacy_role' => 'authoriser'],
    ];

    /**
     * Seeds the six starting highlighters for one agency. Shared by the
     * one-time backfill migration (existing agencies) AND
     * SeedDefaultRentalApplicationHighlighters (new agencies, via the
     * existing AgencyCreated domain event) — Johan: "use the existing
     * mechanism rather than inventing a parallel one." One seeding
     * function, two callers, so they can never drift apart.
     *
     * $existingColors, when given, is RentalApplicationMarkColorSetting-
     * shaped (['agent' => ['income'=>hex,...], 'authoriser' => [...]]) — an
     * agency that already customised its six colours through the settings
     * screen this feature replaces keeps those exact colours, not the
     * shipped defaults. Idempotent: does nothing if this agency already has
     * any highlighter row (seeded or hand-created), so it is safe to call
     * from a migration that might run more than once in a recovery scenario.
     *
     * @param array{agent?: array<string,string>, authoriser?: array<string,string>} $existingColors
     */
    public static function seedDefaultsFor(int $agencyId, array $existingColors = []): void
    {
        if (static::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        $rows = [];
        foreach (self::DEFAULT_SEED as $order => $seed) {
            $color = $existingColors[$seed['legacy_role']][$seed['legacy_category']] ?? $seed['color'];
            $rows[] = [
                'agency_id' => $agencyId,
                'label' => $seed['label'],
                'color' => $color,
                // 2026-09-13 — cc2's finding: capture status must be a
                // stable identity, never derived from the label text (see
                // the migration adding this column). The seeded Income/
                // Expense rows are the two REAL capture types this whole
                // feature exists for; 'unpaid' (and anything else) is
                // deliberately null — drawn, never captured.
                'capture_type' => in_array($seed['legacy_category'], self::CAPTURE_TYPES, true) ? $seed['legacy_category'] : null,
                'role_scope' => $seed['role_scope'],
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert() goes straight to the query builder, bypassing
        // Eloquent's creating() hooks entirely (including BelongsToAgency's
        // auto-stamp) — agency_id is already explicit on every row above.
        static::insert($rows);
    }

    /** Choosable for a NEW mark — not archived, visible to $role (its own scope, or 'both'), ordered. Never includes archived rows: that is the entire point of archiving. */
    public static function pickerFor(int $agencyId, string $role): \Illuminate\Support\Collection
    {
        $role = $role === self::ROLE_AUTHORISER ? self::ROLE_AUTHORISER : self::ROLE_AGENT;

        return static::where('agency_id', $agencyId)
            ->whereIn('role_scope', [$role, self::ROLE_BOTH])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Every highlighter for this agency, INCLUDING archived — for resolving
     * an existing mark's colour, which must keep working after archiving
     * (Johan: "still renders its existing marks perfectly"), and for the
     * settings screen's own list (which shows an archived section).
     */
    public static function allFor(int $agencyId): \Illuminate\Support\Collection
    {
        return static::withTrashed()
            ->where('agency_id', $agencyId)
            ->with('creator')
            ->orderBy('sort_order')
            ->get();
    }
}
