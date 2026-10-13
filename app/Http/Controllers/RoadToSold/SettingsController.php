<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsSettingAuditEntry;
use App\Services\RoadToSold\AreaService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * AT-454 — Road to Sold settings hub + General tab.
 * Spec: .ai/specs/road-to-sold.md §4 / §5.1.
 *
 * update() is a PARTIAL update: only the fields present in the request are
 * touched. That is deliberate — it is also the canonical saver the onboarding
 * wizard calls with a subset of the fields (onboarding spec §6.1), so an absent
 * field must never be read as "false" or "0".
 */
class SettingsController extends Controller
{
    private const TRACK = RtsSetting::TRACK_SALES;

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $this->canOpenHub($user)) {
            abort(403, 'You don\'t have access to this resource.');
        }

        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')
                ->with('error', 'Switch into an agency first - Road to Sold settings belong to one agency.');
        }

        // Someone who can only manage areas / targets lands on their own tab, not a read-only General page.
        if (! $user->hasPermission('road_to_sold.manage_settings')) {
            $first = collect(self::tabsFor($user, 'general'))->first();
            if ($first && ! $first['active']) {
                return redirect($first['href']);
            }
        }

        $settings = SettingsService::forAgency($agencyId, self::TRACK);

        return view('road-to-sold.settings.index', [
            'settings'   => $settings,
            'defaults'   => RtsSetting::SCALAR_DEFAULTS,
            'canManage'  => $user->hasPermission('road_to_sold.manage_settings'),
            'tabs'       => self::tabsFor($user, 'general'),
            'warnings'   => $this->warnings($agencyId, $settings),
            'recent'     => RtsSettingAuditEntry::where('agency_id', $agencyId)
                ->where('track', self::TRACK)
                ->with('changedBy')->latest('id')->limit(5)->get(),
            'labels'     => self::FIELD_LABELS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user     = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')
                ->with('error', 'Switch into an agency first - Road to Sold settings belong to one agency.');
        }

        $this->saveFor($request, $agencyId, (int) $user->id);

        return redirect()->route('road-to-sold.settings.index')->with('success', 'Road to Sold settings saved.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $user     = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')
                ->with('error', 'Switch into an agency first - Road to Sold settings belong to one agency.');
        }

        $reset = RtsSetting::SCALAR_DEFAULTS + [
            'selections'    => RtsSetting::normaliseOrderedToggles(null, RtsSetting::selectionCatalogue(self::TRACK)),
            'summary_steps' => RtsSetting::normaliseOrderedToggles(null, RtsSetting::summaryStepCatalogue(self::TRACK)),
            'default_weekday_targets' => RtsSetting::DEFAULT_WEEKDAY_TARGETS,
        ];
        $this->persist($agencyId, (int) $user->id, $reset);

        return redirect()->route('road-to-sold.settings.index')->with('success', 'Road to Sold settings are back to the defaults.');
    }

    /**
     * Canonical saver (also invoked by the onboarding wizard with a SUBSET of fields).
     * Only keys present in the request are validated and written.
     *
     * @throws ValidationException
     */
    public function saveFor(Request $request, int $agencyId, int $userId): void
    {
        $current = SettingsService::forAgency($agencyId, self::TRACK);

        $rules = [
            'lock_agents_to_areas'         => ['sometimes', 'boolean'],
            'half_day_leave_halves_target' => ['sometimes', 'boolean'],
            'area_refresh_warn_days'       => ['sometimes', 'integer', 'min:1', 'max:365'],
            'missed_items_mode'            => ['sometimes', 'in:' . RtsSetting::MISSED_SHOW . ',' . RtsSetting::MISSED_ROLL],
            'new_on_market_days'           => ['sometimes', 'integer', 'min:1', 'max:365'],
            'long_on_market_days'          => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'owner_known_quantity'         => ['sometimes', 'integer', 'min:0', 'max:100'],
            'stuck_stock_min_days'         => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'stuck_stock_max_views'        => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'stuck_stock_max_leads'        => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'hot_pocket_lookback_days'     => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'price_drop_lookback_days'     => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'stuck_stock_lookback_days'    => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'no_answer_drop_after'         => ['sometimes', 'integer', 'min:1', 'max:20'],
            'hold_days'                    => ['sometimes', 'integer', 'min:1', 'max:365'],
            'summary_green_from_pct'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'summary_red_below_pct'        => ['sometimes', 'integer', 'min:1', 'max:100'],
            'selections'                   => ['sometimes', 'array'],
            'summary_steps'                => ['sometimes', 'array'],
            // The agency's default week: when sent, all seven days are required (0 is fine).
            'default_targets'              => ['sometimes', 'array'],
        ];
        foreach (array_keys(RtsSetting::DEFAULT_WEEKDAY_TARGETS) as $day) {
            $rules["default_targets.{$day}"] = ['required_with:default_targets', 'integer', 'min:0', 'max:' . \App\Models\RoadToSold\RtsWeekdayTarget::MAX_TARGET];
        }

        $messages = [];
        foreach (\App\Models\RoadToSold\RtsWeekdayTarget::WEEKDAYS as $day => $dayLabel) {
            $messages["default_targets.{$day}.required_with"] = "Agency default: enter a number for {$dayLabel} (0 if nobody works it).";
            $messages["default_targets.{$day}.integer"]       = "Agency default {$dayLabel}: enter a whole number from 0 to " . \App\Models\RoadToSold\RtsWeekdayTarget::MAX_TARGET . '.';
            $messages["default_targets.{$day}.min"]           = "Agency default {$dayLabel}: enter a whole number from 0 to " . \App\Models\RoadToSold\RtsWeekdayTarget::MAX_TARGET . '.';
            $messages["default_targets.{$day}.max"]           = "Agency default {$dayLabel}: enter a whole number from 0 to " . \App\Models\RoadToSold\RtsWeekdayTarget::MAX_TARGET . '.';
        }
        foreach (self::FIELD_LABELS as $field => $label) {
            $messages["{$field}.integer"] = "{$label}: enter a whole number.";
            $messages["{$field}.min"]     = "{$label}: that number is too small.";
            $messages["{$field}.max"]     = "{$label}: that number is too large.";
            $messages["{$field}.in"]      = "{$label}: pick one of the choices shown.";
        }

        $data = $request->validate($rules, $messages);

        // Cross-field: the green line must sit above the red line (final values, so a partial save is judged too).
        $green = (int) ($data['summary_green_from_pct'] ?? $current->summaryGreenFromPct());
        $red   = (int) ($data['summary_red_below_pct'] ?? $current->summaryRedBelowPct());
        if ($red >= $green) {
            throw ValidationException::withMessages([
                'summary_red_below_pct' => 'Green must start higher than red (red is below ' . $red . '%, green starts at ' . $green . '%).',
            ]);
        }

        $changes = [];
        foreach (array_keys(RtsSetting::SCALAR_DEFAULTS) as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = is_bool(RtsSetting::SCALAR_DEFAULTS[$field])
                    ? (bool) $data[$field]
                    : (is_numeric($data[$field]) ? (int) $data[$field] : (string) $data[$field]);
            }
        }
        if (array_key_exists('selections', $data)) {
            $changes['selections'] = RtsSetting::normaliseOrderedToggles($data['selections'], RtsSetting::selectionCatalogue(self::TRACK));
        }
        if (array_key_exists('default_targets', $data)) {
            $week = [];
            foreach (array_keys(RtsSetting::DEFAULT_WEEKDAY_TARGETS) as $day) {
                $week[$day] = (int) $data['default_targets'][$day];
            }
            $changes['default_weekday_targets'] = $week;
        }
        if (array_key_exists('summary_steps', $data)) {
            $changes['summary_steps'] = RtsSetting::normaliseOrderedToggles($data['summary_steps'], RtsSetting::summaryStepCatalogue(self::TRACK));
        }

        $this->persist($agencyId, $userId, $changes);
    }

    /**
     * Write the changed fields in one transaction and append one audit row.
     * No change = no write and no audit noise.
     *
     * @param array<string,mixed> $changes
     */
    private function persist(int $agencyId, int $userId, array $changes): void
    {
        DB::transaction(function () use ($agencyId, $userId, $changes) {
            $row = RtsSetting::query()->where('agency_id', $agencyId)->where('track', self::TRACK)->lockForUpdate()->first();

            $old = [];
            $new = [];
            foreach ($changes as $field => $value) {
                $before = $row ? $row->{$field} : null;
                $before = $before ?? $this->defaultFor($field);
                // Loose compare on purpose: stored "5" vs posted 5, and list-of-arrays equality.
                if ($before != $value) {
                    $old[$field] = $before;
                    $new[$field] = $value;
                }
            }

            if ($new === []) {
                return;
            }

            if (! $row) {
                $row = new RtsSetting();
                $row->agency_id = $agencyId;
                $row->track     = self::TRACK;
            }
            foreach ($new as $field => $value) {
                $row->{$field} = $value;
            }
            // First save of a row: store the full set of defaults for fields never touched, so the row is complete.
            if (! $row->exists) {
                foreach (RtsSetting::SCALAR_DEFAULTS as $field => $default) {
                    if (! array_key_exists($field, $new)) {
                        $row->{$field} = $default;
                    }
                }
            }
            $row->updated_by_user_id = $userId;
            $row->save();

            RtsSettingAuditEntry::create([
                'agency_id'          => $agencyId,
                'track'              => self::TRACK,
                'changed_by_user_id' => $userId,
                'old_values'         => $old,
                'new_values'         => $new,
                'changed_at'         => now(),
            ]);
        });

        SettingsService::forget($agencyId, self::TRACK);
    }

    private function defaultFor(string $field): mixed
    {
        return match ($field) {
            'selections'    => RtsSetting::normaliseOrderedToggles(null, RtsSetting::selectionCatalogue(self::TRACK)),
            'summary_steps' => RtsSetting::normaliseOrderedToggles(null, RtsSetting::summaryStepCatalogue(self::TRACK)),
            'default_weekday_targets' => RtsSetting::DEFAULT_WEEKDAY_TARGETS,
            default         => RtsSetting::SCALAR_DEFAULTS[$field] ?? null,
        };
    }

    private function canOpenHub($user): bool
    {
        return $user && (
            $user->hasPermission('road_to_sold.manage_settings')
            || $user->hasPermission('road_to_sold.manage_areas')
            || $user->hasPermission('road_to_sold.manage_targets')
        );
    }

    /**
     * The tab strip shared by every Road to Sold settings screen. A tab only appears when
     * the viewer holds its key AND its route exists (tabs are added as each screen lands).
     *
     * @return array<int,array{key:string,label:string,href:string,active:bool}>
     */
    public static function tabsFor($user, string $active): array
    {
        $defs = [
            ['general', 'General', 'road_to_sold.manage_settings', 'road-to-sold.settings.index'],
            ['areas', 'Areas', 'road_to_sold.manage_areas', 'road-to-sold.settings.areas.index'],
            ['targets', 'Targets', 'road_to_sold.manage_targets', 'road-to-sold.settings.targets.index'],
        ];

        $tabs = [];
        foreach ($defs as [$key, $label, $permission, $route]) {
            if (\Illuminate\Support\Facades\Route::has($route) && $user->hasPermission($permission)) {
                $tabs[] = ['key' => $key, 'label' => $label, 'href' => route($route), 'active' => $key === $active];
            }
        }

        return $tabs;
    }

    /** @return string[] plain-language warnings shown at the top of the General tab (HTML, names escaped) */
    private function warnings(int $agencyId, SettingsService $settings): array
    {
        $warnings = [];

        if ($settings->lockAgentsToAreas()) {
            $without = app(AreaService::class)->agentsWithoutAreas($agencyId, self::TRACK);
            if ($without->isNotEmpty()) {
                $names = $without->take(8)->map(fn ($u) => e($u->name))->implode(', ');
                $more  = $without->count() > 8 ? ' and ' . ($without->count() - 8) . ' more' : '';
                $link  = \Illuminate\Support\Facades\Route::has('road-to-sold.settings.areas.index')
                    ? ' <a class="underline" href="' . e(route('road-to-sold.settings.areas.index', ['no_areas' => 1])) . '">Give them suburbs</a>.'
                    : '';
                $warnings[] = '<strong>' . $without->count() . ' ' . ($without->count() === 1 ? 'agent has' : 'agents have')
                    . ' no suburbs, and "Lock agents to their areas" is on - they will get no call list:</strong> '
                    . $names . $more . '.' . $link;
            }
        }

        return $warnings;
    }

    /** Plain-English field names used in validation messages (STANDARDS F.8). */
    public const FIELD_LABELS = [
        'area_refresh_warn_days'  => 'Days before an area needs refreshing',
        'new_on_market_days'      => 'New on market (days)',
        'long_on_market_days'     => 'Long on market (days)',
        'owner_known_quantity'    => 'Owner already known - how many to show',
        'stuck_stock_min_days'    => 'Not selling - days on market',
        'stuck_stock_max_views'   => 'Not selling - views below',
        'stuck_stock_max_leads'   => 'Not selling - leads below',
        'hot_pocket_lookback_days'  => 'Hottest pockets - look back (days)',
        'price_drop_lookback_days'  => 'Price drops - look back (days)',
        'stuck_stock_lookback_days' => 'Not selling - views and leads counted over (days)',
        'no_answer_drop_after'    => 'No answers in a row before a property drops off',
        'hold_days'               => 'Days an agent keeps a property after an outcome',
        'summary_green_from_pct'  => 'Green from (%)',
        'summary_red_below_pct'   => 'Red below (%)',
        'missed_items_mode'       => 'Missed items',
    ];
}
