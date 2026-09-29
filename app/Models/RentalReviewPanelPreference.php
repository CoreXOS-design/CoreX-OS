<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-430 §3.1 — "Open/collapsed state is remembered per user per section."
 * One row per user; panel_state is a {panel_key: bool} map. No agency_id —
 * same convention as calendar_user_preferences (scoped by user_id alone).
 */
class RentalReviewPanelPreference extends Model
{
    protected $fillable = ['user_id', 'panel_state'];

    protected $casts = [
        'panel_state' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Defaults per AT-430 §3.1: Finances open, Checklist open, everything else collapsed. */
    public const DEFAULTS = [
        'finances' => true,
        'checklist' => true,
    ];

    public static function stateFor(int $userId): array
    {
        $row = static::where('user_id', $userId)->first();

        return array_merge(self::DEFAULTS, $row?->panel_state ?? []);
    }

    public static function setFor(int $userId, string $panelKey, bool $open): void
    {
        $row = static::firstOrNew(['user_id' => $userId]);
        $state = $row->panel_state ?? [];
        $state[$panelKey] = $open;
        $row->panel_state = $state;
        $row->save();
    }
}
