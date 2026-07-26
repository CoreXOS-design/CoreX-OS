<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-267 — one row per meaningful thing an assistant did (opened / edited /
 * created / deleted a property, contact or deal). Written by
 * App\Http\Middleware\LogAssistantActivity. Append-only; read on the agent's
 * My Assistants → Activity tab.
 */
class AssistantActivityLog extends Model
{
    use BelongsToAgency, Prunable;

    protected $table = 'assistant_activity_log';

    /**
     * Retention: 12 months (AUDIT 2026-07-26, F6).
     *
     * LogAssistantActivity writes a row per successful record-scoped request — including every
     * GET — so an assistant working a full day generates a row per page view. Without a retention
     * rule this is an unbounded append-only table on a per-tenant database: nothing breaks today,
     * and in eighteen months it is the reason backups are slow.
     *
     * Twelve months matches the retention the rest of CoreX's audit surfaces use, and comfortably
     * outlives the question this log exists to answer ("what has my assistant been doing?"), which
     * the Activity tab caps at the 200 most recent rows anyway.
     *
     * withoutGlobalScopes() is required: BelongsToAgency would otherwise scope the prune to the
     * console user's agency (there isn't one), and the sweep would silently prune nothing.
     */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('created_at', '<=', now()->subMonths(12));
    }

    /** Append-only log — created_at only, no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'assistant_assignment_id',
        'assistant_user_id',
        'agent_user_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'route_name',
        'url',
        'method',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * AT-267 / AUDIT 2026-07-26 (F1) — "Show 'added by {assistant}' on things they do".
     *
     * Resolves the most recent CHANGE an assistant made to one record, for the attribution tag on
     * that record's page. Returns null — meaning "render nothing" — for every record no assistant
     * has ever touched, for a record whose last change was the agent's own, and for an assignment
     * whose agent has switched `show_attribution` off.
     *
     * WHY THIS LOG AND NOT THE RECORD. Ownership routing (spec §Decisions 2) deliberately files an
     * assistant's work under the AGENT, so the record itself carries no trace of who actually did
     * it — that is the whole point, and it is also why attribution needs a separate source. This
     * log is it: it already records assistant + agent + assignment + subject + action, written on
     * the one chokepoint every assistant request passes through.
     *
     * 'opened' is excluded on purpose. The toggle says "on things they DO"; an agent does not want
     * "viewed by Thandi" on a record nobody changed, and treating a read as a contribution would
     * make the tag meaningless within a day.
     *
     * Memoised per request: a detail page may ask for the same subject from several partials, and
     * this must never become a query per render.
     *
     * @return array{name:string, title:string, at:\Illuminate\Support\Carbon}|null
     */
    public static function attributionFor(?string $subjectType, int|string|null $subjectId): ?array
    {
        if (! $subjectType || ! $subjectId) {
            return null;
        }

        $key = $subjectType . ':' . $subjectId;

        if (array_key_exists($key, static::$attributionMemo)) {
            return static::$attributionMemo[$key];
        }

        $row = static::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('action', ['edited', 'deleted'])
            ->with(['assistant', 'assignment'])
            ->latest('created_at')
            ->first();

        // No assistant has changed this record, or the agent has switched attribution off.
        if (! $row || ! $row->assistant || ! ($row->assignment?->show_attribution)) {
            return static::$attributionMemo[$key] = null;
        }

        return static::$attributionMemo[$key] = [
            'name'  => $row->assistant->name,
            'title' => $row->assistant->assistantTitle(),
            'at'    => $row->created_at,
        ];
    }

    /** @var array<string, array|null> */
    private static array $attributionMemo = [];

    /** Test/queue hygiene — the memo is per request, and a long-lived worker is not one request. */
    public static function flushAttributionMemo(): void
    {
        static::$attributionMemo = [];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssistantAssignment::class, 'assistant_assignment_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
