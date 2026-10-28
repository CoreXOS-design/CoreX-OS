<?php

namespace App\Models\Docuperfect;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/esign-template-transfer.md §13 — one release of a finished (workshop) template to an agency. The release row is history: it is
 * never deleted. agency_id is the TARGET agency, so BelongsToAgency scopes an agency user to their own releases at the query layer (the owner
 * sees every agency's).
 */
class TemplateRelease extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATE_AWAITING = 'awaiting_approval';
    public const STATE_LIVE = 'live';
    public const STATE_DECLINED = 'declined';
    public const STATE_WITHDRAWN = 'withdrawn';
    public const STATE_SUPERSEDED = 'superseded';

    public const STATES = [self::STATE_AWAITING, self::STATE_LIVE, self::STATE_DECLINED, self::STATE_WITHDRAWN, self::STATE_SUPERSEDED];

    protected $table = 'docuperfect_template_releases';

    protected $fillable = [
        'source_template_id', 'template_id', 'agency_id', 'version', 'state', 'readiness_verdict', 'readiness_snapshot', 'source_content_sha',
        'package_sha256', 'lease_link', 'released_by', 'released_at', 'decided_by', 'decided_at', 'decision_note', 'previous_release_id',
    ];

    protected $casts = [
        'readiness_snapshot' => 'array',
        'lease_link' => 'array',
        'released_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public static function stateLabel(string $state): string
    {
        return [
            self::STATE_AWAITING => 'Waiting for approval',
            self::STATE_LIVE => 'Live',
            self::STATE_DECLINED => 'Declined',
            self::STATE_WITHDRAWN => 'Withdrawn',
            self::STATE_SUPERSEDED => 'Replaced by a newer version',
        ][$state] ?? ucfirst(str_replace('_', ' ', $state));
    }

    public function sourceTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'source_template_id')->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id')->withTrashed();
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id')->withoutGlobalScopes();
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by')->withoutGlobalScopes()->withTrashed();
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withoutGlobalScopes()->withTrashed();
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_release_id')->withoutGlobalScopes();
    }

    public function events(): HasMany
    {
        return $this->hasMany(TemplateReleaseEvent::class, 'release_id')->orderBy('created_at')->orderBy('id');
    }
}
