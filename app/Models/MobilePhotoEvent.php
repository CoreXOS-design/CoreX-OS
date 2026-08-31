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

    /**
     * `dropped` reasons that mean the AGENT chose to remove the photo — the only
     * ones the report may subtract from "never arrived".
     *
     * The app also emits `dropped` for an enqueue failure and for camera-close on
     * the no-target path. Those are genuine losses wearing the same label, so the
     * list is an explicit allow-list rather than an exclusion: a new reason, or a
     * drop with no reason at all, counts as a loss until someone decides it isn't.
     * Over-reporting a loss is recoverable; hiding one is how a photo-loss bug
     * survives four days.
     */
    public const AGENT_DROP_REASONS = [
        'removed_in_review',
        'discarded_by_agent',
    ];

    /**
     * Collapse a drop reason to a spelling-insensitive key.
     *
     * The client is Dart: an enum's `.name` serialises camelCase
     * (`removedInReview`), while this list and every other reason in CoreX are
     * snake_case. Nobody has verified which one actually goes on the wire, and
     * the failure mode of guessing wrong is silent and bad — an unmatched reason
     * makes every deliberate deletion count as a LOST photo, which is worse than
     * the bug the reason was introduced to fix.
     *
     * So the server refuses to care. Case and underscores are stripped from both
     * sides before comparison, which accepts `removed_in_review`,
     * `removedInReview` and `Removed_In_Review` alike. Fixing the class beats
     * agreeing a convention across two repos and hoping it holds.
     */
    public static function dropReasonKey(?string $reason): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', trim((string) $reason)));
    }

    /** Is this drop reason one the agent chose — i.e. NOT a lost photo? */
    public static function isAgentDropReason(?string $reason): bool
    {
        if ($reason === null || trim($reason) === '') {
            return false; // no reason = a loss, by design
        }

        return in_array(
            self::dropReasonKey($reason),
            array_map([self::class, 'dropReasonKey'], self::AGENT_DROP_REASONS),
            true
        );
    }

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
