<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-118 — immutable POPIA evidence base for the Communications Access Gate.
 *
 * Append-only forensic record of who did what, when, to whose communications.
 * Rows are immutable once created: update + delete throw at the model level
 * (mirrors LegalBlockAuditLog::save() and ESignConsentLog::delete()/update()).
 * No updated_at, no SoftDeletes — an audit trail is never edited or removed.
 *
 * Every later AT-118 step (gate, request flow, midnight reset, offboarding
 * transfer) logs through ::record() so the call site is one consistent line.
 *
 * Spec: .ai/specs/at118-communications-access-gate.md §3.5
 */
class CommsAccessAuditLog extends Model
{
    use BelongsToAgency;

    public const EVENT_REQUEST            = 'request';
    public const EVENT_GRANT              = 'grant';
    public const EVENT_DECLINE            = 'decline';
    public const EVENT_SESSION_EXPIRED    = 'session_expired';
    public const EVENT_MIDNIGHT_RESET     = 'midnight_reset';
    public const EVENT_OWNERSHIP_TRANSFER = 'ownership_transfer';
    // AT-132 — a deliberate human-actioned revoke (owner/authoriser or requester
    // self-revoke), distinct from the automatic session_expired/midnight_reset. The
    // only way to end an 'always' grant. Wave 2 adds otp_issued/otp_unlock here.
    public const EVENT_REVOKE             = 'revoke';

    public const EVENT_TYPES = [
        self::EVENT_REQUEST,
        self::EVENT_GRANT,
        self::EVENT_DECLINE,
        self::EVENT_SESSION_EXPIRED,
        self::EVENT_MIDNIGHT_RESET,
        self::EVENT_OWNERSHIP_TRANSFER,
        self::EVENT_REVOKE,
    ];

    protected $table = 'comms_access_audit_log';

    /** Append-only: created_at only, no updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'event_type',
        'actor_user_id',
        'subject_user_id',
        'contact_id',
        'communication_id',
        'detail',
        'created_at',
    ];

    protected $casts = [
        'detail'     => 'array',
        'created_at' => 'datetime',
    ];

    // ── Immutability (this is the POPIA evidence base — never edited or removed) ──

    /** Block mutation of an existing row; new inserts pass through. */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new DomainException(
                'CommsAccessAuditLog is append-only. Existing rows cannot be modified.'
            );
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new DomainException('CommsAccessAuditLog is append-only — rows are immutable.');
    }

    public function delete()
    {
        throw new DomainException(
            'CommsAccessAuditLog is append-only — rows cannot be deleted (POPIA evidence).'
        );
    }

    /** Defense-in-depth: also block Eloquent update/delete events (e.g. destroy()). */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('CommsAccessAuditLog is append-only — rows are immutable.');
        });
        static::deleting(function () {
            throw new DomainException('CommsAccessAuditLog is append-only — rows cannot be deleted.');
        });
    }

    /**
     * Canonical write path. One consistent call for every later AT-118 step.
     *
     * @param  string  $eventType  one of self::EVENT_TYPES
     * @param  array{actor_user_id?:int|null,subject_user_id?:int|null,contact_id?:int|null,communication_id?:int|null,detail?:array|null,agency_id?:int|null,created_at?:mixed}  $attributes
     */
    public static function record(string $eventType, array $attributes = []): self
    {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown comms access audit event_type: {$eventType}");
        }

        // AT-118 — under switch-user the actor_user_id reads as the impersonated
        // user; stamp the real acting admin into detail so the trail is honest.
        $detail = $attributes['detail'] ?? null;
        $actingAdminId = \App\Support\Impersonation::actingAdminId();
        if ($actingAdminId !== null) {
            $detail = (array) $detail;
            $detail['acting_as_admin_id'] = $actingAdminId;
        }

        $row = [
            'event_type'       => $eventType,
            'actor_user_id'    => $attributes['actor_user_id'] ?? null,
            'subject_user_id'  => $attributes['subject_user_id'] ?? null,
            'contact_id'       => $attributes['contact_id'] ?? null,
            'communication_id' => $attributes['communication_id'] ?? null,
            'detail'           => $detail,
            'created_at'       => $attributes['created_at'] ?? now(),
        ];

        // agency_id: BelongsToAgency auto-stamps from the authenticated user.
        // For system/console events (e.g. midnight_reset) there is no auth user,
        // so the caller passes it explicitly and the trait honours it.
        if (!empty($attributes['agency_id'])) {
            $row['agency_id'] = $attributes['agency_id'];
        }

        return static::create($row);
    }

    // ── Relationships ──

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class, 'communication_id');
    }
}
