<?php

namespace App\Models\TitleDeeds;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/title-deed-register.md — one title deed the agency holds at the office for its owner(s).
 *
 * A SIBLING of DocumentFiling, not an extension of it. Status moves only through TitleDeedService / the handover
 * service — never by editing a field. Archived = soft delete.
 */
class TitleDeed extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'title_deeds';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_NOTIFIED = 'notified';
    public const STATUS_COLLECTED = 'collected';

    public const STATUS_LABELS = [
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_NOTIFIED => 'Owner notified',
        self::STATUS_COLLECTED => 'Collected',
    ];

    public const METHOD_PIN = 'pin';
    public const METHOD_ID_CHECK = 'id_check';
    public const METHOD_OWNER_CONSENT = 'owner_consent';
    public const METHOD_PHONE_CONSENT = 'phone_consent';
    public const METHOD_AUTHORITY_LETTER = 'authority_letter';

    public const METHOD_LABELS = [
        self::METHOD_PIN => 'PIN',
        self::METHOD_ID_CHECK => 'ID check',
        self::METHOD_OWNER_CONSENT => 'Owner consent',
        self::METHOD_PHONE_CONSENT => 'Phone consent',
        self::METHOD_AUTHORITY_LETTER => 'Authority letter',
    ];

    /** Columns the list can be sorted on (key => SQL). Anything else falls back to the default order. */
    public const SORTABLE = [
        'property' => 'title_deeds.property_address',
        'agent' => 'agent_name_sort',
        'received' => 'title_deeds.received_at',
        'status' => 'title_deeds.status',
        'waiting' => 'title_deeds.received_at',
        'collected' => 'title_deeds.collected_at',
    ];

    protected $fillable = [
        'agency_id', 'branch_id', 'agent_id', 'created_by', 'property_id', 'property_address', 'attorney_firm',
        'received_at', 'storage_location', 'notes', 'status', 'notified_at', 'collected_at', 'collected_by_name',
        'collected_method', 'scan_document_id', 'pin_version', 'pin_encrypted', 'pin_issued_at',
        'pin_failed_attempts', 'pin_locked_at', 'last_reminder_at', 'reminder_count',
    ];

    protected $casts = [
        'received_at' => 'date',
        'notified_at' => 'datetime',
        'collected_at' => 'datetime',
        'pin_issued_at' => 'datetime',
        'pin_locked_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'pin_version' => 'integer',
        'pin_failed_attempts' => 'integer',
        'reminder_count' => 'integer',
    ];

    protected $hidden = ['pin_encrypted'];

    /* ── Relationships ── */

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id')->withTrashed();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id')->withTrashed();
    }

    public function owners(): HasMany
    {
        return $this->hasMany(TitleDeedOwner::class, 'title_deed_id')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TitleDeedEvent::class, 'title_deed_id')->orderByDesc('id');
    }

    public function audit(): HasMany
    {
        return $this->hasMany(TitleDeedAuditLog::class, 'title_deed_id')->orderByDesc('id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(TitleDeedConsent::class, 'title_deed_id')->orderByDesc('id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(TitleDeedReceipt::class, 'title_deed_id');
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'scan_document_id');
    }

    /* ── Scopes ── */

    /**
     * OWN / BRANCH / AGENCY, at the query layer. `title_deeds.view` carries the scope (Role Manager). own = the responsible
     * agent OR the person who logged it. The agency wall is BelongsToAgency underneath; this is the narrower scoping within it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = PermissionService::getDataScope($user, 'title_deeds');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where('title_deeds.branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            $ids = $user->dataIdentityIds();

            return $query->where(function (Builder $q) use ($ids) {
                $q->whereIn('title_deeds.agent_id', $ids)->orWhereIn('title_deeds.created_by', $ids);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /** Property address, owner name, attorney firm, collector name. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title_deeds.property_address', 'like', $like)
                ->orWhere('title_deeds.attorney_firm', 'like', $like)
                ->orWhere('title_deeds.collected_by_name', 'like', $like)
                ->orWhereExists(function ($sub) use ($like) {
                    $sub->selectRaw('1')->from('title_deed_owners as o')
                        ->join('contacts as c', 'c.id', '=', 'o.contact_id')
                        ->whereColumn('o.title_deed_id', 'title_deeds.id')
                        ->whereNull('o.deleted_at')
                        ->where(function ($w) use ($like) {
                            $w->where('o.name_snapshot', 'like', $like)
                                ->orWhere('c.first_name', 'like', $like)
                                ->orWhere('c.last_name', 'like', $like)
                                ->orWhereRaw("CONCAT_WS(' ', c.first_name, c.last_name) like ?", [$like]);
                        });
                })
                ->orWhereExists(function ($sub) use ($like) {
                    $sub->selectRaw('1')->from('title_deed_receipts as r')
                        ->whereColumn('r.title_deed_id', 'title_deeds.id')
                        ->where('r.collector_name', 'like', $like);
                });
        });
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('title_deeds.status', '!=', self::STATUS_COLLECTED);
    }

    /* ── State ── */

    public function isCollected(): bool
    {
        return $this->status === self::STATUS_COLLECTED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    /** Whole days the deed has been waiting at the office. Null once collected (the collected date says the rest). */
    public function getDaysWaitingAttribute(): ?int
    {
        if ($this->isCollected() || ! $this->received_at) {
            return null;
        }

        return max(0, (int) $this->received_at->startOfDay()->diffInDays(Carbon::today()));
    }

    /** What the property is called on screen: the live record when it still reads, else the snapshot taken when it was saved. */
    public function getPropertyDisplayAttribute(): string
    {
        $property = $this->relationLoaded('property') ? $this->property : $this->property()->first();
        $live = $property ? trim((string) $property->buildDisplayAddress()) : '';

        return $live !== '' ? $live : ((string) $this->property_address !== '' ? (string) $this->property_address : 'Property #' . $this->property_id);
    }

    /** "Copy only - original held at office" until collected, then "Original collected <date>". */
    public function getScanLabelAttribute(): ?string
    {
        if (! $this->scan_document_id) {
            return null;
        }

        return $this->isCollected()
            ? 'Original collected ' . $this->collected_at?->format('d M Y')
            : 'Copy only - original held at office';
    }
}
