<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed moment in one photo's life, from shutter to stored.
 *
 * Spec: .ai/specs/mobile-photo-upload-telemetry.md
 */
class MobilePhotoEvent extends Model
{
    use BelongsToAgency;

    /** Phases the CLIENT may report. `received` is server-written and refused here. */
    public const CLIENT_PHASES = [
        'captured',
        'queued',
        'upload_started',
        'upload_ok',
        'upload_failed',
        'dropped',
    ];

    /** Written by the server in uploadImage(), never accepted from a client. */
    public const PHASE_RECEIVED = 'received';

    protected $fillable = [
        'agency_id',
        'user_id',
        'property_id',
        'client_upload_id',
        'batch_id',
        'phase',
        'occurred_at',
        'meta',
    ];

    protected $casts = [
        'meta'        => 'array',
        'occurred_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a phase without ever being able to break the caller.
     *
     * Used from the live upload path, where a telemetry failure must never cost
     * an agent their photo. Any exception — table missing mid-deploy, bad JSON,
     * a lock — is swallowed deliberately: diagnostics are worth nothing if they
     * can take down the thing they are diagnosing.
     */
    public static function recordQuietly(array $attributes): void
    {
        try {
            static::updateOrCreate(
                [
                    'property_id'      => $attributes['property_id'] ?? null,
                    'client_upload_id' => $attributes['client_upload_id'] ?? null,
                    'phase'            => $attributes['phase'] ?? null,
                ],
                $attributes
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
