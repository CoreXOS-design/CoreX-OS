<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// AT-284 — per-agency minion cadence/config. Effective values = config defaults overlaid by the row.
class MinionCaptureSettings extends Model
{
    protected $table = 'minion_capture_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'           => 'boolean',
        'alert_enabled'     => 'boolean',
        'run_days'          => 'array',
        'targets_per_night' => 'integer',
        'cycle_days'        => 'integer',
        'pace_min_seconds'  => 'integer',
        'pace_max_seconds'  => 'integer',
    ];

    /**
     * Effective settings for an agency = config('minion_capture') defaults overlaid by the stored row.
     */
    public static function resolved(int $agencyId): array
    {
        $d   = config('minion_capture');
        $row = static::where('agency_id', $agencyId)->first();

        return [
            'enabled'           => $row?->enabled           ?? $d['enabled'],
            'targets_per_night' => $row?->targets_per_night ?? $d['targets_per_night'],
            'cycle_days'        => $row?->cycle_days         ?? $d['cycle_days'],
            'run_at'            => $row?->run_at             ?? $d['run_at'],
            'run_days'          => $row?->run_days           ?? $d['run_days'],
            'pace_min_seconds'  => $row?->pace_min_seconds   ?? $d['pace_min_seconds'],
            'pace_max_seconds'  => $row?->pace_max_seconds   ?? $d['pace_max_seconds'],
            'alert_enabled'     => $row?->alert_enabled      ?? $d['alert_enabled'],
        ];
    }
}
