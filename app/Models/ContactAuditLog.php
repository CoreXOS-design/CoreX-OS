<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-321-C — one immutable, attributable audit row per meaningful contact change.
 * Mirror of PropertyAuditLog. SoftDeletes per Non-Negotiable #1 (append-only in
 * practice; never user-deletable).
 */
class ContactAuditLog extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, BelongsToAgency, SoftDeletes;

    /** A child's branch is its parent's branch, never the acting user's. */
    protected function branchParent(): array
    {
        return [\App\Models\Contact::class, 'contact_id'];
    }

    protected $table = 'contact_audit_log';

    /** created_at is set explicitly by the writer; no updated_at on an audit row. */
    public $timestamps = false;

    protected $fillable = [
        'contact_id', 'user_id', 'agency_id', 'branch_id',
        'actor_type', 'actor_label', 'source',
        'event_category', 'event_type',
        'old_values', 'new_values', 'metadata',
        'human_summary', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function scopeForContact($q, int $contactId) { return $q->where('contact_id', $contactId); }
    public function scopeForCategory($q, string $category) { return $q->where('event_category', $category); }
}
