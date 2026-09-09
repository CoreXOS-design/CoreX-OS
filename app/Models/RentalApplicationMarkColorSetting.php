<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Highlighter freehand redesign, 2026-09-09 — Johan: "admin can pick 6
 * colours - agent 3 and auth 3." Follows STANDARDS.md's safe settings
 * pattern (also used by RentalApplicationQualifyingSetting): colorsFor()
 * never creates a row on read, only returns a sensible in-memory default
 * when the agency has never opened the settings screen.
 *
 * The three category keys (income/expense/unpaid) stay fixed — only their
 * COLOUR is admin-configurable, not their meaning. An existing mark stores
 * `category` + `author_role`, never a colour value, so repainting the
 * palette here changes how every existing mark displays without touching
 * a single saved mark.
 */
class RentalApplicationMarkColorSetting extends Model
{
    use BelongsToAgency;

    public const CATEGORIES = ['income', 'expense', 'unpaid'];
    public const ROLES = ['agent', 'authoriser'];

    /**
     * Same colours this feature already shipped with tonight (the CSS
     * custom-property fallbacks in document-highlighter-script.blade.php) —
     * an agency that never opens this settings screen sees no visual
     * change at all.
     */
    public const DEFAULTS = [
        'agent' => ['income' => '#a7f3cf', 'expense' => '#fde8a8', 'unpaid' => '#fbcdc9'],
        'authoriser' => ['income' => '#4ec99a', 'expense' => '#f2b33d', 'unpaid' => '#ee7c72'],
    ];

    protected $fillable = [
        'agency_id',
        'agent_income_color', 'agent_expense_color', 'agent_unpaid_color',
        'authoriser_income_color', 'authoriser_expense_color', 'authoriser_unpaid_color',
    ];

    /**
     * @return array{agent: array{income:string,expense:string,unpaid:string}, authoriser: array{income:string,expense:string,unpaid:string}}
     */
    public static function colorsFor(?int $agencyId): array
    {
        $row = ($agencyId !== null && $agencyId > 0) ? static::where('agency_id', $agencyId)->first() : null;

        $colors = self::DEFAULTS;
        if ($row) {
            foreach (self::ROLES as $role) {
                foreach (self::CATEGORIES as $category) {
                    $value = $row->{"{$role}_{$category}_color"};
                    if ($value) {
                        $colors[$role][$category] = $value;
                    }
                }
            }
        }

        return $colors;
    }

    /** Just the requesting role's own three — never hand a screen the other role's colours. */
    public static function colorsForRole(?int $agencyId, string $role): array
    {
        $role = $role === 'authoriser' ? 'authoriser' : 'agent';

        return self::colorsFor($agencyId)[$role];
    }
}
