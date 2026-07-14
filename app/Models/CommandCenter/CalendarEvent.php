<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Contact;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\Scopes\LivePropertyScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    /**
     * Hide events whose linked property has been soft-deleted (see
     * LivePropertyScope). Keeps a gone property's viewings/attention events
     * off Today / Calendar / reminders. Opt out with withoutGlobalScope().
     *
     * Also bust the owner's Today cockpit cache on every write so a resolved /
     * dismissed / rescheduled event drops off Today immediately —
     * CommandCentreService::assembleForUser caches for 300s, and a stale
     * cockpit kept resolved items visible even after a hard refresh.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new LivePropertyScope());

        $bust = fn (self $event) => $event->forgetCockpitCache();
        static::saved($bust);
        static::deleted($bust);
        static::restored($bust);
    }

    /** Forget the Today cockpit cache for this event's owner (and prior owner on reassignment). */
    public function forgetCockpitCache(): void
    {
        foreach (array_unique(array_filter([
            $this->user_id,
            $this->getOriginal('user_id'),
        ])) as $userId) {
            \Illuminate\Support\Facades\Cache::forget("command_centre_{$userId}");
        }
    }

    protected $fillable = [
        'user_id', 'created_by_id', 'event_type', 'category', 'title', 'description',
        'event_date', 'end_date', 'all_day', 'priority', 'send_reminder', 'status', 'colour',
        'source_type', 'source_id',
        'property_id', 'contact_id', 'branch_id', 'agency_id',
        'reminder_offsets', 'reminder_channels', 'reminders_sent',
        'is_recurring', 'recurrence_rule', 'parent_event_id',
        'metadata',
        'created_by_ai', 'ai_source', 'ai_transcript',
    ];

    protected $casts = [
        'event_date'       => 'datetime',
        'end_date'         => 'datetime',
        'all_day'          => 'boolean',
        'send_reminder'    => 'boolean',
        'is_recurring'     => 'boolean',
        'reminder_offsets' => 'array',
        'reminder_channels' => 'array',
        'reminders_sent'   => 'array',
        'metadata'         => 'array',
        'created_by_ai'    => 'boolean',
    ];

    // ── Colour map by event type ──
    public const TYPE_COLOURS = [
        'deal'        => '#3b82f6', // blue
        'lease'       => '#10b981', // green
        'compliance'  => '#f59e0b', // amber
        'document'    => '#8b5cf6', // purple
        'prospecting' => '#06b6d4', // cyan
        'portal'      => '#ec4899', // pink
        'property'    => '#f97316', // orange
        'manual'      => '#6b7280', // grey
    ];

    public const PRIORITY_ORDER = ['critical' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id');
    }

    /**
     * Pillar tag for visual grouping: 'property' | 'contact' | 'deal' | null.
     * Derived from FKs first, then event_type for deal/compliance signal.
     */
    public function pillarTag(): ?string
    {
        if ($this->property_id) return 'property';
        if (in_array($this->event_type, ['deal', 'lease'], true)) return 'deal';
        if ($this->contact_id)  return 'contact';
        return null;
    }

    public function remindersLog(): HasMany
    {
        return $this->hasMany(CalendarReminderLog::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CommandTask::class, 'id', 'calendar_event_id');
    }

    // ── Polymorphic links (M2.2) ──

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'calendar_event_id');
    }

    public function linkedProperties(): MorphToMany
    {
        return $this->morphedByMany(Property::class, 'linkable', 'calendar_event_links', 'calendar_event_id')
            ->wherePivot('role', CalendarEventLink::ROLE_SUBJECT_PROPERTY);
    }

    public function linkedContacts(): MorphToMany
    {
        // CAL-7 Class 3 — surface EVERY contact link, regardless of pivot.role.
        // Previously this whitelisted role IN [attendee, buyer_contact,
        // seller_contact]. On staging's live-copy DB, legacy calendar_event_
        // links rows exist with role=NULL or other historical values, and
        // when CalendarEventClassSetting rows are missing (no seed), newly
        // saved links default to 'attendee' — but the same Class 1 path
        // could land any role here in future. The polymorphic
        // linkable_type=Contact::class predicate is already correct
        // scoping; the role filter was a duplicate of intent that excluded
        // valid contacts.
        return $this->morphedByMany(Contact::class, 'linkable', 'calendar_event_links', 'calendar_event_id');
    }

    public function linkedDeals(): MorphToMany
    {
        return $this->morphedByMany(DealV2::class, 'linkable', 'calendar_event_links', 'calendar_event_id')
            ->wherePivot('role', CalendarEventLink::ROLE_RELATED_DEAL);
    }

    public function getLinkedPropertyAttribute()
    {
        return $this->linkedProperties->first();
    }

    public function getLinkedDealAttribute()
    {
        return $this->linkedDeals->first();
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(CalendarEventAuditEntry::class)->orderBy('performed_at', 'desc');
    }

    // ── Scopes ──

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Role-driven visibility scope for the Calendar / Today page.
     * Honours the per-role Data Scope set in Role Manager
     * (command_center.calendar.view → own | branch | all | none).
     * Agency isolation is already applied by BelongsToAgency, so this
     * only narrows within the current agency.
     *
     *   own    → events owned by this user
     *   branch → events in the user's branch (falls back to own if no branch)
     *   all    → no extra narrowing (whole agency)
     *   none   → nothing (no access)
     */
    public function scopeVisibleTo($query, User $user, ?string $scope)
    {
        // AT-267 — an assistant's 'own' is their Assigned Agent's; everyone else: [$user->id].
        $identityIds = $user->dataIdentityIds();

        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->where('branch_id', $user->effectiveBranchId())
                : $query->whereIn('user_id', $identityIds),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->whereIn('user_id', $identityIds), // 'own' or null
        };
    }

    public function scopeUpcoming($query)
    {
        return $query->where('event_date', '>=', now())
                     ->where('status', 'pending')
                     ->orderBy('event_date');
    }

    public function scopeOverdue($query)
    {
        return $query->where('event_date', '<', now())
                     ->where('status', 'pending');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('event_date', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('event_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeInDateRange($query, $start, $end)
    {
        // Include events that START in range OR SPAN into range (multi-day)
        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('event_date', [$start, $end])
              ->orWhere(function ($q2) use ($start, $end) {
                  $q2->where('event_date', '<', $start)
                     ->where('end_date', '>=', $start);
              });
        });
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    // ── Helpers ──

    public function getColourAttribute($value): string
    {
        return $value ?? (self::TYPE_COLOURS[$this->event_type] ?? '#6b7280');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->event_date->isPast();
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markDismissed(): void
    {
        $this->update(['status' => 'dismissed']);
    }

    // ── Private event redaction (view-time; ITEM 4) ──────────────────────────

    /** Runtime flag: this in-memory instance has been redacted for a non-creator. */
    public bool $isPrivacyRedacted = false;

    /** A personal time-block whose detail only its creator may see. */
    public function isPrivateClass(): bool
    {
        return $this->category === 'private';
    }

    /** The creator — the only user who sees a private event in full. */
    public function privateOwnerId(): ?int
    {
        return $this->created_by_id ?: $this->user_id;
    }

    /**
     * True when this is a private event and $viewer is NOT its creator.
     * Deliberately role-blind: admins, owners, branch managers and super_admins
     * are all "someone else" — a private block never reveals detail to anyone
     * but its creator (no override).
     */
    public function isPrivateHiddenFrom(?User $viewer): bool
    {
        if (!$this->isPrivateClass()) {
            return false;
        }
        if (!$viewer) {
            return true;
        }
        return (int) $viewer->id !== (int) $this->privateOwnerId();
    }

    /**
     * Redact a private event IN MEMORY for a non-creator viewer: the time slot,
     * colour and class stay (so the block still shows as busy) but the title
     * becomes "Private" and every detail/link is stripped so nothing leaks.
     * Idempotent; a display transform only — never persisted.
     */
    public function applyPrivacyFor(?User $viewer): static
    {
        if (!$this->isPrivateHiddenFrom($viewer)) {
            return $this;
        }
        $this->title         = 'Private';
        $this->description   = null;
        $this->metadata      = null;
        $this->property_id   = null;
        $this->contact_id    = null;
        $this->created_by_ai = false;
        // Pre-set relations to empty so a blade/JSON lazy-load cannot re-fetch detail.
        $this->setRelation('property', null);
        $this->setRelation('contact', null);
        $this->setRelation('linkedProperties', collect());
        $this->setRelation('linkedContacts', collect());
        $this->setRelation('linkedDeals', collect());
        $this->setRelation('links', collect());
        $this->isPrivacyRedacted = true;
        return $this;
    }

    // ── Event nature (actionable = "requires feedback" / informational) ──────

    /**
     * The EFFECTIVE actionable/informational nature for THIS event.
     *
     * Per-event choice wins: the create/edit form stores the user's selection in
     * metadata['event_nature'] (no new column — extends the existing metadata
     * JSON). When the user made no choice, fall back to the agency-configurable
     * class default on calendar_event_class_settings.event_nature ('actionable'
     * when even that is missing). This is the single source of truth every
     * consumer (colour/red gate, feedback CTA, overdue marker) reads.
     */
    public function effectiveEventNature(): string
    {
        $override = is_array($this->metadata ?? null) ? ($this->metadata['event_nature'] ?? null) : null;
        if (in_array($override, ['actionable', 'informational'], true)) {
            return $override;
        }
        $cfg = CalendarEventClassSetting::forAgencyAndClass($this->agency_id, (string) ($this->category ?? ''));
        return $cfg?->event_nature ?? 'actionable';
    }

    /** Informational = a marker/time-block: never goes red/overdue, never asks for feedback. */
    public function isInformational(): bool
    {
        return $this->effectiveEventNature() === CalendarEventClassSetting::NATURE_INFORMATIONAL;
    }

    // ── Reminders (AT-178) ───────────────────────────────────────────────────

    /** Valid reminder channels. Contacts are never notified — internal scheduling only. */
    public const REMINDER_CHANNELS = ['popup', 'email'];

    /**
     * The EFFECTIVE lead-time offsets (minutes-before) for THIS event.
     *
     * Resolution (doctrine: no hardcoding — per-event beats class beats system):
     *   1. per-event `reminder_offsets` if the user set one,
     *   2. else the agency class default (calendar_event_class_settings),
     *   3. else the system default ([60]).
     * Always sanitised to a sorted, unique, non-negative int list.
     *
     * @return int[]
     */
    public function effectiveReminderOffsets(): array
    {
        $offsets = $this->sanitiseOffsets($this->reminder_offsets);
        if ($offsets !== null) {
            return $offsets;
        }

        $cfg = CalendarEventClassSetting::forAgencyAndClass($this->agency_id, (string) ($this->category ?? ''));
        $classOffsets = $this->sanitiseOffsets($cfg?->default_reminder_offsets);
        if ($classOffsets !== null) {
            return $classOffsets;
        }

        return \App\Models\AgencyContactSettings::DEFAULT_EVENT_REMINDER_OFFSETS;
    }

    /**
     * The EFFECTIVE reminder channels for THIS event. Same three-tier resolution as
     * offsets; system default is popup-only (Johan: popup on, email off).
     *
     * @return string[] subset of REMINDER_CHANNELS
     */
    public function effectiveReminderChannels(): array
    {
        $channels = $this->sanitiseChannels($this->reminder_channels);
        if ($channels !== null) {
            return $channels;
        }

        $cfg = CalendarEventClassSetting::forAgencyAndClass($this->agency_id, (string) ($this->category ?? ''));
        $classChannels = $this->sanitiseChannels($cfg?->default_reminder_channels);
        if ($classChannels !== null) {
            return $classChannels;
        }

        return \App\Models\AgencyContactSettings::DEFAULT_EVENT_REMINDER_CHANNELS;
    }

    /**
     * Normalise an offsets value to a sorted unique non-negative int list, or null
     * when the source is unset/empty (so callers can fall through to the next tier).
     */
    private function sanitiseOffsets($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $clean = collect($raw)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v >= 0 && $v <= 43200)
            ->unique()->sort()->values()->all();

        return $clean ?: null;
    }

    /** Normalise a channels value to a valid subset of REMINDER_CHANNELS, or null. */
    private function sanitiseChannels($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $clean = collect($raw)
            ->map(fn ($v) => is_string($v) ? strtolower(trim($v)) : $v)
            ->filter(fn ($v) => in_array($v, self::REMINDER_CHANNELS, true))
            ->unique()->values()->all();

        return $clean ?: null;
    }

    /**
     * The USERS who should receive this event's reminders: the owner plus any agent
     * attendees invited to it (accounts only) whose invitation is not declined or
     * cancelled. Deduplicated by user id. Contacts are deliberately excluded.
     *
     * Call this on the REAL event row (a recurring parent), never a virtual occurrence
     * clone — invitations are keyed on the parent event id.
     *
     * @return \Illuminate\Support\Collection<int,\App\Models\User>
     */
    public function reminderRecipients(): \Illuminate\Support\Collection
    {
        $userIds = collect();

        if ($this->user_id) {
            $userIds->push((int) $this->user_id);
        }

        CalendarEventInvitation::withoutGlobalScopes()
            ->where('event_id', $this->id)
            ->whereNotNull('invitee_user_id')
            ->whereNotIn('status', ['declined', 'cancelled'])
            ->pluck('invitee_user_id')
            ->each(fn ($id) => $userIds->push((int) $id));

        $ids = $userIds->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        // Only active users receive reminders. withoutGlobalScopes so a cross-branch
        // invitee still resolves (agency isolation already held at invite time).
        return User::withoutGlobalScopes()
            ->whereIn('id', $ids->all())
            ->where('is_active', 1)
            ->get()
            ->keyBy('id')
            ->values();
    }
}
