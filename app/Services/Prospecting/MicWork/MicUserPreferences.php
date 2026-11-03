<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\MicAgencySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-528 (mic-work-tab.md 13.2): each user's remembered MIC view (adverts | list) and last filter set. The URL query string is ALWAYS the source of
 * truth: a request that carries a view or any filter wins and is what gets remembered; only a bare request falls back to the memory, then to the agency default.
 */
final class MicUserPreferences
{
    /** @return array{view:?string, filters:array<string,mixed>} */
    public function get(User $user, int $agencyId): array
    {
        if (! Schema::hasTable('mic_user_preferences')) {
            return ['view' => null, 'filters' => []];
        }
        $row = DB::table('mic_user_preferences')->where('agency_id', $agencyId)->where('user_id', $user->id)->first();

        return ['view' => $row->view ?? null, 'filters' => $row && $row->filters ? (json_decode((string) $row->filters, true) ?: []) : []];
    }

    /** The view to show: ?view= (when valid) > the user's remembered view > the agency's default view. Returns `adverts` or `list`. */
    public function view(array $query, User $user, int $agencyId): string
    {
        $v = (string) ($query['view'] ?? '');
        if (in_array($v, ['adverts', 'list'], true)) {
            return $v;
        }
        $mine = $this->get($user, $agencyId)['view'];

        return in_array($mine, ['adverts', 'list'], true) ? $mine : MicAgencySetting::defaultViewFor($agencyId);
    }

    /**
     * The filter set to use: the query string's own when it carries any (or `clear=1` / `reset=1`, which means "no filters"), else the remembered set.
     *
     * @return array<string,mixed>
     */
    public function filters(array $query, User $user, int $agencyId): array
    {
        $own = array_intersect_key($query, array_flip(MicAdvertFilters::KEYS));
        if (MicAdvertFilters::present($own)) {
            return $own;
        }
        if (! empty($query['clear']) || ! empty($query['reset'])) {
            return [];
        }

        return $this->get($user, $agencyId)['filters'];
    }

    /** Remember what this request used (view and/or filters). A bare request leaves the memory alone; `clear=1` empties the remembered filters. */
    public function remember(array $query, User $user, int $agencyId): void
    {
        if (! Schema::hasTable('mic_user_preferences')) {
            return;
        }
        $patch = [];
        if (in_array($query['view'] ?? null, ['adverts', 'list'], true)) {
            $patch['view'] = $query['view'];
        }
        $own = array_intersect_key($query, array_flip(MicAdvertFilters::KEYS));
        if (MicAdvertFilters::present($own)) {
            $patch['filters'] = json_encode($own);
        } elseif (! empty($query['clear']) || ! empty($query['reset'])) {
            $patch['filters'] = null;
        }
        if ($patch === []) {
            return;
        }
        $keys = ['agency_id' => $agencyId, 'user_id' => $user->id];
        DB::table('mic_user_preferences')->updateOrInsert($keys, $patch + ['updated_at' => now(), 'created_at' => now()]);
    }
}
