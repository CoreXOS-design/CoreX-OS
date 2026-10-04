<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-command-centre.md §10 — the needs-action queue's
 * collapse/expand state, remembered per user, server-side. Same pattern
 * as RentalInspectionScreenPreference: one row per user, a JSON map of
 * preference key => value.
 */
class RentalCommandCentreUserPreference extends Model
{
    protected $fillable = ['user_id', 'preference_state'];

    protected $casts = [
        'preference_state' => 'array',
    ];

    public const DEFAULTS = [
        'queue_collapsed' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
