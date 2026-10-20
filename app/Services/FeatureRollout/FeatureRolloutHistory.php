<?php

namespace App\Services\FeatureRollout;

use App\Events\FeatureRollout\FeatureRolloutChanged;
use App\Events\FeatureRollout\FeatureWaveReleased;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An agency's gradual-rollout history in plain words (AT-478 §12.10): every release, skip, pause,
 * change and access review, with who and when. Read from domain_event_log, where the wildcard audit
 * listener writes FeatureRolloutChanged and FeatureWaveReleased — there is no second store.
 *
 * Always filtered by an explicit agency id (the caller's own agency, or the agency on the owner page).
 */
class FeatureRolloutHistory
{
    /** Filter groups shown on the screens. */
    public const KINDS = [
        'switched_on' => 'Switched on',
        'changes'     => 'Changes to the plan',
        'access'      => 'Who gets access',
    ];

    private const SWITCHED_ON = ['ready_switched_on', 'feature_switched_on', 'feature_switched_on_early', 'feature_switched_on_features_page', 'finished'];

    /** @var array<string,string> role name => the agency's label for it */
    private array $roleLabels = [];

    public function __construct(private readonly AgencyFeatureService $features)
    {
    }

    /**
     * Everything the shared history section (corex.feature-rollout._history) needs, from the request's own
     * h_* filters and hpage page.
     *
     * @return array{history: LengthAwarePaginator, historyFilters: array, historyClearUrl: string}
     */
    public function forRequest(\Illuminate\Http\Request $request, int $agencyId): array
    {
        $v = $request->validate([
            'h_kind' => 'nullable|in:' . implode(',', array_keys(self::KINDS)),
            'h_from' => 'nullable|date',
            'h_to'   => 'nullable|date',
            'h_sort' => 'nullable|in:newest,oldest',
        ]);
        $filters = ['kind' => $v['h_kind'] ?? null, 'from' => $v['h_from'] ?? null, 'to' => $v['h_to'] ?? null, 'sort' => $v['h_sort'] ?? 'newest'];

        return [
            'history'         => $this->forAgency($agencyId, $filters, max(1, (int) $request->query('hpage', 1)), 'hpage', $request->url(), $request->query()),
            'historyFilters'  => $filters,
            'historyClearUrl' => $request->fullUrlWithoutQuery(['h_kind', 'h_from', 'h_to', 'h_sort', 'hpage']),
        ];
    }

    /**
     * @param  array{kind?:?string, from?:?string, to?:?string, sort?:?string} $filters
     * @return LengthAwarePaginator<array{when:Carbon, who:string, what:string, kind:string}>
     */
    public function forAgency(int $agencyId, array $filters, int $page, string $pageName, string $path, array $query): LengthAwarePaginator
    {
        $q = DB::table('domain_event_log')
            ->where('agency_id', $agencyId)
            ->whereIn('event_name', [FeatureRolloutChanged::class, FeatureWaveReleased::class])
            ->when(! empty($filters['from']), fn ($q) => $q->where('occurred_at', '>=', $filters['from'] . ' 00:00:00'))
            ->when(! empty($filters['to']), fn ($q) => $q->where('occurred_at', '<=', $filters['to'] . ' 23:59:59.999999'))
            ->orderBy('occurred_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');

        $kind = $filters['kind'] ?? null;
        if ($kind === 'switched_on') {
            $q->where(fn ($w) => $w->where('event_name', FeatureWaveReleased::class)
                ->orWhereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload_snapshot, '$.action'))"), self::SWITCHED_ON));
        } elseif ($kind === 'access') {
            $q->where('event_name', FeatureRolloutChanged::class)
                ->where(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload_snapshot, '$.action'))"), 'access_reviewed');
        } elseif ($kind === 'changes') {
            $q->where('event_name', FeatureRolloutChanged::class)
                ->whereNotIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload_snapshot, '$.action'))"), [...self::SWITCHED_ON, 'access_reviewed']);
        }

        $total = (clone $q)->count();
        $rows = $q->forPage($page, 25)->get(['event_name', 'actor_user_id', 'payload_snapshot', 'occurred_at']);

        $this->roleLabels = Role::where('agency_id', $agencyId)->pluck('label', 'name')->all();
        $users = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('actor_user_id')->filter()->unique())->get()->keyBy('id');
        $waveIds = $rows->map(fn ($r) => json_decode((string) $r->payload_snapshot, true)['waveId'] ?? null)->filter()->unique();
        $waves = AgencyRolloutWave::queryWithoutAgencyScope()->withTrashed()->whereIn('id', $waveIds)->pluck('title', 'id');

        $items = $rows->map(function ($r) use ($users, $waves) {
            $p = (array) json_decode((string) $r->payload_snapshot, true);
            $user = $r->actor_user_id ? $users->get($r->actor_user_id) : null;
            $who = ! $r->actor_user_id ? 'Automatic' : (! $user ? 'A former user' : ($user->isOwnerRole() ? 'CoreX team' : ($user->name ?: $user->email)));

            return [
                'when' => Carbon::parse($r->occurred_at),
                'who'  => $who,
                'what' => $r->event_name === FeatureWaveReleased::class ? $this->released($p, $waves) : $this->changed($p),
                'kind' => $r->event_name === FeatureWaveReleased::class ? 'switched_on' : (($p['action'] ?? '') === 'access_reviewed' ? 'access' : 'changes'),
            ];
        })->all();

        return new LengthAwarePaginator($items, $total, 25, $page, ['path' => $path, 'pageName' => $pageName, 'query' => $query]);
    }

    private function label(string $key): string
    {
        return (string) ($this->features->catalogue()[$key]['label'] ?? $key);
    }

    /** @param list<string> $keys */
    private function labels(array $keys): string
    {
        return $keys === [] ? 'nothing' : implode(', ', array_map(fn ($k) => $this->label((string) $k), $keys));
    }

    private function released(array $p, $waves): string
    {
        $title = $waves[$p['waveId'] ?? 0] ?? 'A group';
        $text = "\"{$title}\" switched on: " . $this->labels((array) ($p['releasedFeatureKeys'] ?? [])) . '.';
        if (! empty($p['awaitingAdminKeys'])) {
            $text .= ' Waiting for the agency: ' . $this->labels((array) $p['awaitingAdminKeys']) . '.';
        }

        return $text;
    }

    private function changed(array $p): string
    {
        $d = (array) ($p['details'] ?? []);
        $f = isset($d['feature']) ? $this->label((string) $d['feature']) : '';
        $g = (string) ($d['group'] ?? '');

        return match ($p['action'] ?? '') {
            'started'          => 'Gradual introduction started — ' . count((array) ($d['held'] ?? [])) . ' features held back: ' . $this->labels((array) ($d['held'] ?? [])) . '.',
            'paused'           => 'Paused.',
            'resumed'          => 'Resumed. Groups still to come were re-dated from that day.',
            'finished'         => 'Everything switched on; the gradual introduction finished.',
            'pace_changed'     => 'Pace changed: ' . $this->pace($d) . '.',
            'feature_skipped'  => "{$f} skipped — it stays off.",
            'feature_switched_on'               => "{$f} switched on.",
            'feature_switched_on_early'         => "{$f} switched on ahead of its group.",
            'feature_switched_on_features_page' => "{$f} switched on from the Features page, ahead of its group.",
            'ready_switched_on' => "{$f} switched on now that its setup is done.",
            'feature_added'    => "{$f} added to \"{$g}\" (switched off until then).",
            'feature_moved'    => "{$f} moved from \"" . ($d['from'] ?? '') . "\" to \"{$g}\".",
            'group_added'      => "Group \"{$g}\" added.",
            'group_edited'     => ($d['was'] ?? $g) === $g ? "Group \"{$g}\" edited." : "Group \"" . $d['was'] . "\" renamed to \"{$g}\".",
            'group_moved'      => "Group \"{$g}\" moved " . ($d['direction'] ?? '') . '.',
            'group_archived'   => "Group \"{$g}\" archived.",
            'group_restored'   => "Group \"{$g}\" restored.",
            'reset_to_default' => 'Groups still to come rebuilt from the default list.',
            'access_reviewed'  => $this->review($d),
            'early_requested'  => "Asked for {$f} early.",
            default            => 'Changed.',
        };
    }

    private function pace(array $d): string
    {
        $parts = [];
        if (isset($d['interval_days'])) {
            $parts[] = 'every ' . (int) $d['interval_days'] . ' release day' . ((int) $d['interval_days'] === 1 ? '' : 's');
        }
        if (isset($d['weekdays_only'])) {
            $parts[] = $d['weekdays_only'] ? 'weekdays only' : 'weekends count';
        }
        if (isset($d['skip_holidays'])) {
            $parts[] = $d['skip_holidays'] ? 'public holidays skipped' : 'public holidays count';
        }
        if (isset($d['release_time'])) {
            $parts[] = 'switches on at ' . substr((string) $d['release_time'], 0, 5);
        }
        if (isset($d['side_by_side'])) {
            $parts[] = $d['side_by_side'] ? 'sales and rentals side by side' : 'one list';
        }

        return $parts === [] ? 'no change' : implode(', ', $parts);
    }

    private function review(array $d): string
    {
        $groups = implode(', ', array_map(fn ($t) => "\"{$t}\"", (array) ($d['groups'] ?? [])));
        $changes = (array) ($d['changes'] ?? []);
        if (($d['decision'] ?? '') !== 'save' || $changes === []) {
            return "Checked who gets {$groups} — kept as it was.";
        }
        $lines = array_map(function ($c) {
            [$feature, $role, $on] = array_pad(explode('|', (string) $c), 3, '');
            return ($on === 'on' ? 'gave ' : 'took away ') . $this->label($feature) . ($on === 'on' ? ' to ' : ' from ') . ($this->roleLabels[$role] ?? str_replace('_', ' ', $role));
        }, $changes);

        return "Checked who gets {$groups}: " . implode('; ', $lines) . '.';
    }
}
