<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §27.7 — the recording screen's
 * "photos visible" and problem-filter controls, remembered per user,
 * server-side. One row per user; preference_state is a
 * {preference_key: value} map. Same pattern as RentalReviewPanelPreference
 * (rental applications review screen) — reused deliberately rather than
 * inventing a second persistence mechanism, but kept as its own table
 * rather than sharing that one's rows: the two screens' preference keys
 * are unrelated, and mixing them into one key space risks an accidental
 * collision meaning two different things on two different screens.
 */
class RentalInspectionScreenPreference extends Model
{
    protected $fillable = ['user_id', 'preference_state'];

    protected $casts = [
        'preference_state' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Johan's ruling, §27 — photos shown by default, filter off (All) by default.
     *  §29 — the untagged-photo tray tile size defaults to small (Johan: "I don't
     *  mind the small thumbnails"). */
    public const DEFAULTS = [
        'photos_visible' => true,
        'filter_mode' => 'all',
        'tray_tile_size' => 'small',
    ];

    public static function stateFor(int $userId): array
    {
        $row = static::where('user_id', $userId)->first();

        return array_merge(self::DEFAULTS, $row?->preference_state ?? []);
    }

    public static function setFor(int $userId, string $key, $value): void
    {
        $row = static::firstOrNew(['user_id' => $userId]);
        $state = $row->preference_state ?? [];
        $state[$key] = $value;
        $row->preference_state = $state;
        $row->save();
    }
}
