<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;

/**
 * Evaluation Certificate — Phase 1 foundation (/tools/cma redesign).
 * Spec: .ai/specs/EVALUATION_CERTIFICATE_REDESIGN.md
 *
 * TERMINOLOGY (legal, non-negotiable): "evaluation", never "valuation".
 *
 * A persisted, independently-editable record. When property_id is set, the
 * fields below were prefilled from that Property at creation time but are
 * NOT kept in sync afterward — editing/saving a certificate never writes
 * back to the source property.
 *
 * Canonical field shape — cc6 (Phase 2/3), cc1 (Phase 4), cc5 (Phase 4b/5/6)
 * all build against this exact shape. created_by/signed_by/authorised_by
 * were extended to the codebase's standard `_user_id` FK suffix convention
 * (matches every other user-reference column in this schema); use the full
 * names below, not the short forms from the original spec ask.
 */
class EvaluationCertificate extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT                 = 'draft';
    public const STATUS_PENDING_AUTHORISATION = 'pending_authorisation';
    public const STATUS_AUTHORISED            = 'authorised';
    public const STATUS_REJECTED              = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_AUTHORISATION,
        self::STATUS_AUTHORISED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'agency_id',
        'property_id',
        'contact_id',
        'address',
        'property_type',
        'analysis_date',
        'estimated_market_value',
        'bedrooms',
        'bathrooms',
        'parking',
        'key_features',
        'status',
        'created_by_user_id',
        'signed_by_user_id',
        'authorised_by_user_id',
        'reject_note',
        'signed_pdf_path',
    ];

    protected $casts = [
        'analysis_date'           => 'date',
        'estimated_market_value'  => 'integer',
        'bedrooms'                => 'integer',
        'bathrooms'               => 'integer',
        'parking'                 => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingAuthorisation(): bool
    {
        return $this->status === self::STATUS_PENDING_AUTHORISATION;
    }

    public function isAuthorised(): bool
    {
        return $this->status === self::STATUS_AUTHORISED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
