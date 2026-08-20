<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One TVA (The Virtual Agent) KYC person lookup capture — the person identity
 * half; TvaContactCaptureItem holds the individual phone/email rows. Matched
 * to a deeds-capture suspense record (TrackedProperty) by id_number when one
 * exists; nullable tracked_property_id means it lands standalone.
 *
 * SoftDeletes (2026-08-13) — the Deeds Capture screen's "Remove" action
 * dismisses a capture (wrong details, duplicate) without hard-purging it,
 * per Non-Negotiable #1. Same reversible model as TrackedProperty's own
 * SoftDeletes, which the property-side "Remove" action uses directly.
 */
final class TvaContactCapture extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'captured_by_user_id',
        'tracked_property_id',
        'matched_contact_id',
        'id_number',
        'first_name',
        'surname',
        'source',
        'consent_status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TvaContactCaptureItem::class);
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }

    public function matchedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'matched_contact_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    /**
     * Deeds Capture data scope (Johan, 2026-08-20) — same scope value, same
     * option set as TrackedProperty::scopeVisibleToDeedsCapture(), applied to
     * the standalone-TVA-capture block on the same screen. Unlike
     * TrackedProperty, captured_by_user_id here is NOT NULL by schema — every
     * row is genuinely attributed, no NULL-handling needed.
     */
    public function scopeVisibleToDeedsCapture($query, User $user, ?string $scope)
    {
        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->whereIn('captured_by_user_id', function ($q) use ($user) {
                        $q->select('id')->from('users')->where('branch_id', $user->effectiveBranchId());
                    })
                : ($user->hasPermission('branches.view_all')
                    ? $query
                    : $query->where('captured_by_user_id', $user->id)),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->where('captured_by_user_id', $user->id), // 'own' or null
        };
    }

    /**
     * Deeds Capture "address or contact" search, contact half only — a standalone TVA
     * capture has no address to match. Same single-box search as
     * TrackedProperty::scopeSearchDeeds(); a term that only matches an address simply
     * finds nothing here, which is correct, not a leak (TVA rows stay scope-gated
     * independently either way).
     */
    public function scopeSearchDeeds($query, string $term)
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $query->where(fn ($q) => $q
            ->where('first_name', 'like', $like)
            ->orWhere('surname', 'like', $like)
            ->orWhereRaw("CONCAT(first_name, ' ', surname) LIKE ?", [$like]));
    }
}
