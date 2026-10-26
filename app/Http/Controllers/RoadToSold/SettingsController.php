<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsSettingAuditEntry;
use App\Services\RoadToSold\AreaService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\Support\Routing;
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

    /** Sales or rentals: decided by where these screens are mounted (see Support\Routing). */
    private function track(): string
    {
        return Routing::track();
    }

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

        $settings = SettingsService::forAgency($agencyId, $this->track());

        return view(Routing::base() . 'index', [
            'settings'   => $settings,
            'defaults'   => RtsSetting::SCALAR_DEFAULTS,
            'canManage'  => $user->hasPermission('road_to_sold.manage_settings'),
            'tabs'       => self::tabsFor($user, 'general'),
            'warnings'   => $this->warnings($agencyId, $settings),
            'recent'     => RtsSettingAuditEntry::where('agency_id', $agencyId)
                ->where('track', $this->track())
                ->with('changedBy')->latest('id')->limit(5)->get(),
            'labels'     => self::FIELD_LABELS,
            'listChoices'      => RtsSetting::listChoices(),
            'holidayCountries' => $this->countryNames(),
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

        // The Training & flags tab saves its thresholds through this same writer and comes back to its own tab.
        $home = $request->input('back_to') === 'flags' && \Illuminate\Support\Facades\Route::has(Routing::base() . 'flags.index') ? 'flags.index' : 'index';

        return redirect()->route(Routing::base() . $home)->with('success', 'Road to Sold settings saved.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $user     = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')
                ->with('error', 'Switch into an agency first - Road to Sold settings belong to one agency.');
        }

        // "Back to the defaults" archives the saved row (soft delete): no row = the defaults, and the next
        // save brings the row back starting from the defaults. The audit row records what was undone.
        $this->persist($agencyId, (int) $user->id, [], archive: true);

        return redirect()->route(Routing::base() . 'index')->with('success', 'Road to Sold settings are back to the defaults.');
    }

    /**
     * The onboarding wizard's saver (config/agency-onboarding-copy.php, step 'road_to_sold').
     * The wizard names its seven default-week inputs default_target_1..7; this maps them onto the
     * settings page's default_targets[] array and calls the SAME saver, so there is one write path.
     * Every field is optional: whatever the step did not post is left exactly as it was (spec 6.1).
     * A 403 (no permission) is raised before anything is written so the wizard can absorb it.
     */
    public function saveFromWizard(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('road_to_sold.manage_settings'), 403);

        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403);

        $week = [];
        foreach (array_keys(RtsSetting::DEFAULT_WEEKDAY_TARGETS) as $day) {
            if ($request->has("default_target_{$day}")) {
                $week[$day] = $request->input("default_target_{$day}");
            }
        }
        // The week is saved only when the step carried all seven days; a partial week would be refused
        // by the saver ("enter a number for every day"), and a step that never rendered it must not error.
        if (count($week) === count(RtsSetting::DEFAULT_WEEKDAY_TARGETS)) {
            $request->merge(['default_targets' => $week]);
        }

        // The four monthly-target defaults: each is optional, whatever the step did not post is left as it was.
        $monthly = [];
        foreach (array_keys(RtsSetting::DEFAULT_MONTHLY_TARGETS) as $key) {
            if ($request->has("default_monthly_{$key}")) {
                $monthly[$key] = $request->input("default_monthly_{$key}");
            }
        }
        if ($monthly !== []) {
            $request->merge(['default_monthly' => $monthly]);
        }

        // The two portal toggles become the settings page's refresh_portals[] list, only when the step carried
        // both (a step that never rendered them must not touch the setting). Both off is refused by the saver.
        if ($request->has('refresh_portal_p24') && $request->has('refresh_portal_pp')) {
            $request->merge(['refresh_portals' => array_values(array_filter([
                $request->boolean('refresh_portal_p24') ? 'p24' : null,
                $request->boolean('refresh_portal_pp') ? 'pp' : null,
            ]))]);
        }

        $this->saveFor($request, $agencyId, (int) $user->id);
    }

    /**
     * Canonical saver (also invoked by the onboarding wizard with a SUBSET of fields).
     * Only keys present in the request are validated and written.
     *
     * @throws ValidationException
     */
    public function saveFor(Request $request, int $agencyId, int $userId): void
    {
        $current = SettingsService::forAgency($agencyId, $this->track());

        $rules = [
            'lock_agents_to_areas'         => ['sometimes', 'boolean'],
            'half_day_leave_halves_target' => ['sometimes', 'boolean'],
            'area_refresh_warn_days'       => ['sometimes', 'integer', 'min:1', 'max:365'],
            'public_holiday_country'       => ['sometimes', 'string', 'size:2', \Illuminate\Validation\Rule::in($this->holidayCountries())],
            'add_to_day_max'               => ['sometimes', 'integer', 'min:1', 'max:500'],
            'scan_page_size'               => ['sometimes', 'integer', 'min:10', 'max:2000'],
            'min_area_properties'          => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'hot_pocket_weight_buyers'     => ['sometimes', 'integer', 'min:0', 'max:100'],
            'hot_pocket_weight_leads'      => ['sometimes', 'integer', 'min:0', 'max:100'],
            'hot_pocket_weight_sales'      => ['sometimes', 'integer', 'min:0', 'max:100'],
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
            'summary_ahead_from_pct'       => ['sometimes', 'integer', 'min:100', 'max:500'],
            'capture_back_date_hours'      => ['sometimes', 'integer', 'min:1', 'max:720'],
            'capture_needs_review'         => ['sometimes', 'boolean'],
            'levels_signoff_required'      => ['sometimes', 'boolean'],
            'level_periods_required'       => ['sometimes', 'integer', 'min:1', 'max:52'],
            'flag_min_sample'              => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'flag_below_pct'               => ['sometimes', 'integer', 'min:1', 'max:100'],
            'flag_window_days'             => ['sometimes', 'integer', 'min:7', 'max:366'],
            'suggest_window_weeks'         => ['sometimes', 'integer', 'min:2', 'max:52'],
            'suggest_growth_pct'           => ['sometimes', 'integer', 'min:0', 'max:100'],
            'selections'                   => ['sometimes', 'array'],
            'summary_steps'                => ['sometimes', 'array'],
            // The agency's default week: when sent, all seven days are required (0 is fine).
            'default_targets'              => ['sometimes', 'array'],
            // The agency default for the monthly targets: any of the four may be sent; the rest keep their value.
            'default_monthly'              => ['sometimes', 'array'],
            'default_monthly.points'       => ['sometimes', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'default_monthly.deals'        => ['sometimes', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'default_monthly.listings'     => ['sometimes', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'default_monthly.value'        => ['sometimes', 'numeric', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_VALUE],
        ];
        // The four tick-lists: at least one choice, only known choices. A posted blank (the hidden marker
        // that lets "everything unticked" reach the server) is dropped before the check.
        foreach (RtsSetting::listChoices() as $field => $choices) {
            if ($request->has($field)) {
                $request->merge([$field => array_values(array_filter((array) $request->input($field), fn ($v) => $v !== null && $v !== ''))]);
            }
            $rules[$field]         = ['sometimes', 'array', 'min:1'];
            $rules["{$field}.*"]   = ['string', \Illuminate\Validation\Rule::in(array_keys($choices))];
        }
        if ($request->has('public_holiday_country')) {
            $request->merge(['public_holiday_country' => strtoupper(trim((string) $request->input('public_holiday_country')))]);
        }
        foreach (array_keys(RtsSetting::DEFAULT_WEEKDAY_TARGETS) as $day) {
            $rules["default_targets.{$day}"] = ['required_with:default_targets', 'integer', 'min:0', 'max:' . \App\Models\RoadToSold\RtsWeekdayTarget::MAX_TARGET];
        }

        $messages = [];
        foreach (['points' => 'points', 'deals' => 'deals', 'listings' => 'listings', 'value' => 'sales value'] as $k => $word) {
            foreach (['integer', 'numeric', 'min', 'max'] as $rule) {
                $messages["default_monthly.{$k}.{$rule}"] = "Agency default monthly {$word}: enter a number from 0 up (a whole number" . ($k === 'value' ? ' or an amount' : '') . ').';
            }
        }
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
            $messages["{$field}.size"]    = "{$label}: pick one of the choices shown.";
        }
        foreach (RtsSetting::listChoices() as $field => $choices) {
            $label = self::FIELD_LABELS[$field] ?? $field;
            $messages["{$field}.min"]   = "{$label}: tick at least one.";
            $messages["{$field}.*.in"]  = "{$label}: pick from the choices shown.";
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
        foreach (array_keys(RtsSetting::listChoices()) as $field) {
            if (array_key_exists($field, $data)) {
                $order = array_keys(RtsSetting::listChoices()[$field]);
                $changes[$field] = array_values(array_filter($order, fn ($c) => in_array($c, $data[$field], true)));
            }
        }
        if (array_key_exists('selections', $data)) {
            $changes['selections'] = RtsSetting::normaliseOrderedToggles($data['selections'], RtsSetting::selectionCatalogue($this->track()));
        }
        if (array_key_exists('default_targets', $data)) {
            $week = [];
            foreach (array_keys(RtsSetting::DEFAULT_WEEKDAY_TARGETS) as $day) {
                $week[$day] = (int) $data['default_targets'][$day];
            }
            $changes['default_weekday_targets'] = $week;
        }
        if (array_key_exists('default_monthly', $data)) {
            $merged = $current->defaultMonthlyTargets();
            foreach (array_keys(RtsSetting::DEFAULT_MONTHLY_TARGETS) as $key) {
                if (array_key_exists($key, $data['default_monthly'])) {
                    $merged[$key] = $key === 'value' ? round((float) $data['default_monthly'][$key], 2) : (int) $data['default_monthly'][$key];
                }
            }
            $changes['default_monthly_targets'] = $merged;
        }
        if (array_key_exists('summary_steps', $data)) {
            $changes['summary_steps'] = RtsSetting::normaliseOrderedToggles($data['summary_steps'], RtsSetting::summaryStepCatalogue($this->track()));
        }

        $this->persist($agencyId, $userId, $changes);
    }

    /**
     * Write the changed fields in one transaction and append one audit row.
     * No change = no write and no audit noise.
     *
     * @param array<string,mixed> $changes
     */
    private function persist(int $agencyId, int $userId, array $changes, bool $archive = false): void
    {
        DB::transaction(function () use ($agencyId, $userId, $changes, $archive) {
            // withTrashed: an archived row (after "back to the defaults") is brought back, never duplicated.
            $row = RtsSetting::withTrashed()->where('agency_id', $agencyId)->where('track', $this->track())->lockForUpdate()->first();
            $live = $row && ! $row->trashed();

            if ($archive) {
                if (! $live) {
                    return; // already on the defaults - nothing to undo, nothing to audit
                }
                $old = [];
                $new = [];
                foreach (array_keys($this->allDefaults()) as $field) {
                    $before = $row->{$field} ?? $this->defaultFor($field);
                    if ($before != $this->defaultFor($field)) {
                        $old[$field] = $before;
                        $new[$field] = $this->defaultFor($field);
                    }
                }
                $row->updated_by_user_id = $userId;
                $row->save();
                $row->delete();
                if ($new !== []) {
                    $this->audit($agencyId, $userId, $old, $new);
                }

                return;
            }

            $old = [];
            $new = [];
            foreach ($changes as $field => $value) {
                $before = $live ? $row->{$field} : null;
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
                $row->track     = $this->track();
            } elseif ($row->trashed()) {
                $row->restore();
                // Coming back from "all defaults": start from the defaults, not from what was archived.
                foreach (RtsSetting::SCALAR_DEFAULTS as $field => $default) {
                    $row->{$field} = $default;
                }
                foreach ([...array_keys(RtsSetting::LIST_DEFAULTS), 'selections', 'summary_steps', 'default_weekday_targets', 'default_monthly_targets'] as $field) {
                    $row->{$field} = null; // NULL = the code default
                }
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

            $this->audit($agencyId, $userId, $old, $new);
        });

        SettingsService::forget($agencyId, $this->track());
    }

    /** One append-only audit row (rts_settings_audit has no soft delete and is never edited). */
    private function audit(int $agencyId, int $userId, array $old, array $new): void
    {
        RtsSettingAuditEntry::create([
            'agency_id'          => $agencyId,
            'track'              => $this->track(),
            'changed_by_user_id' => $userId,
            'old_values'         => $old,
            'new_values'         => $new,
            'changed_at'         => now(),
        ]);
    }

    /** Every saved field with its default (lists default to their code list). @return array<string,mixed> */
    private function allDefaults(): array
    {
        return RtsSetting::SCALAR_DEFAULTS
            + RtsSetting::LIST_DEFAULTS
            + ['selections' => null, 'summary_steps' => null, 'default_weekday_targets' => null, 'default_monthly_targets' => null];
    }

    /** @return array<string,string> code => display name for every country the holidays table holds */
    private function countryNames(): array
    {
        $out = [];
        foreach ($this->holidayCountries() as $code) {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $code, 'en') : '';
            $out[$code] = ($name !== '' && $name !== $code) ? $name : $code;
        }
        asort($out);

        return $out;
    }

    /** Countries the public_holidays table holds (ZA always offered). @return string[] */
    private function holidayCountries(): array
    {
        return array_values(array_unique(array_merge(
            [RtsSetting::SCALAR_DEFAULTS['public_holiday_country']],
            \Illuminate\Support\Facades\DB::table('public_holidays')->distinct()->pluck('country_code')->map(fn ($c) => strtoupper((string) $c))->all()
        )));
    }

    private function defaultFor(string $field): mixed
    {
        return match ($field) {
            'selections'    => RtsSetting::normaliseOrderedToggles(null, RtsSetting::selectionCatalogue($this->track())),
            'summary_steps' => RtsSetting::normaliseOrderedToggles(null, RtsSetting::summaryStepCatalogue($this->track())),
            'default_weekday_targets' => RtsSetting::DEFAULT_WEEKDAY_TARGETS,
            'default_monthly_targets' => RtsSetting::DEFAULT_MONTHLY_TARGETS,
            default         => RtsSetting::SCALAR_DEFAULTS[$field] ?? RtsSetting::LIST_DEFAULTS[$field] ?? null,
        };
    }

    private function canOpenHub($user): bool
    {
        return $user && (
            $user->hasPermission('road_to_sold.manage_settings')
            || $user->hasPermission('road_to_sold.manage_areas')
            || $user->hasPermission('road_to_sold.manage_targets')
            || $user->hasPermission('road_to_sold.manage_recipe')
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
            ['general', 'General', 'road_to_sold.manage_settings', Routing::base() . 'index'],
            ['areas', 'Areas', 'road_to_sold.manage_areas', Routing::base() . 'areas.index'],
            ['budgets', 'Budgets', 'road_to_sold.manage_targets|road_to_sold.manage_settings', Routing::base() . 'budgets.index'],
            ['targets', 'Targets', 'road_to_sold.manage_targets', Routing::base() . 'targets.index'],
            ['recipe', 'Recipe', 'road_to_sold.manage_recipe', Routing::base() . 'recipe.index'],
            ['task-targets', 'Task targets', 'road_to_sold.manage_targets', Routing::base() . 'task-targets.index'],
            ['flags', 'Training & flags', 'road_to_sold.manage_settings', Routing::base() . 'flags.index'],
        ];

        // Budgets and Targets are reachable without the Road to Sold feature; General, Areas and Recipe are the call list's own.
        $rtsOn = \App\Services\RoadToSold\Scope::callListOn($user);

        $tabs = [];
        foreach ($defs as [$key, $label, $permission, $route]) {
            if (! $rtsOn && in_array($key, ['general', 'areas', 'recipe', 'task-targets', 'flags'], true)) {
                continue;
            }
            if (\Illuminate\Support\Facades\Route::has($route) && collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermission($p))) {
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
            $without = app(AreaService::class)->agentsWithoutAreas($agencyId, $this->track());
            if ($without->isNotEmpty()) {
                $names = $without->take(8)->map(fn ($u) => e($u->name))->implode(', ');
                $more  = $without->count() > 8 ? ' and ' . ($without->count() - 8) . ' more' : '';
                $link  = \Illuminate\Support\Facades\Route::has(Routing::base() . 'areas.index')
                    ? ' <a class="underline" href="' . e(route(Routing::base() . 'areas.index', ['no_areas' => 1])) . '">Give them suburbs</a>.'
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
        'summary_ahead_from_pct'  => 'Ahead from (%)',
        'capture_back_date_hours' => 'Hours a captured task may be back-dated',
        'capture_needs_review'    => 'Captured tasks wait for a manager\'s approval',
        'levels_signoff_required' => 'A manager signs off each level',
        'level_periods_required'  => 'Weeks on target before a level is put forward',
        'flag_min_sample'         => 'Fewest events before a ratio can be flagged',
        'flag_below_pct'          => 'Flag a ratio below (% of the agency median)',
        'flag_window_days'        => 'Days the funnel and its flags look back',
        'suggest_window_weeks'    => 'Weeks a suggested target learns from',
        'suggest_growth_pct'      => 'Growth a suggested target allows (%)',
        'missed_items_mode'       => 'Missed items',
        'public_holiday_country'  => 'Public holidays of',
        'add_to_day_max'          => 'Most properties one "Add to my day" adds',
        'scan_page_size'          => 'Properties read per page',
        'min_area_properties'     => 'Smallest number of properties for a working area',
        'hot_pocket_weight_buyers' => 'Hottest pockets - weight of buyers',
        'hot_pocket_weight_leads'  => 'Hottest pockets - weight of leads',
        'hot_pocket_weight_sales'  => 'Hottest pockets - weight of sales',
        'refresh_portals'         => 'Portals we capture from',
        'buyer_match_tiers'       => 'Buyer matches that count',
        'active_buyer_states'     => 'Buyers that count as active',
        'on_market_statuses'      => 'Statuses that mean on the market',
    ];
}
