<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-49 — an identifier-level marketing suppression (see the migration).
 *
 * Active = lifted_at IS NULL. Tenant-scoped via BelongsToAgency. Lifting is an
 * opt-in (sets lifted_at); rows are never hard-deleted.
 */
class MarketingSuppression extends Model
{
    use BelongsToAgency;

    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';

    public const SOURCE_SELF_SERVICE_LINK = 'self_service_link';
    public const SOURCE_UNSUBSCRIBE_PAGE  = 'unsubscribe_page';
    public const SOURCE_AGENT             = 'agent';

    protected $fillable = [
        'agency_id', 'identifier', 'identifier_type', 'contact_id',
        'source', 'reason', 'send_id',
        'suppressed_at', 'lifted_at', 'lifted_by_user_id', 'recorded_by_user_id',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
        'lifted_at'     => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('lifted_at');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function liftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }
}
