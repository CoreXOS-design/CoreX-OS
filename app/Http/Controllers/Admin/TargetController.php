<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Target;
use App\Models\DailyActivity;
use App\Models\ActivityTarget;
use App\Models\User;
use App\Models\Deal;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\PermissionService;

class TargetController extends Controller
{
    /**
     * The Targets screen moved to Road to Sold settings (re-home step 4): this address redirects there, keeping the month.
     * The route name `admin.targets` is kept so every older link still resolves.
     */
    public function index(Request $request)
    {
        $params = array_filter(['period' => $request->query('period')]);

        return redirect()->to(route('road-to-sold.settings.targets.index', $params) . '#monthly', 301);
    }

    /**
     * Kept so a Targets page that was open before the move can still post. It writes through the ONE writer
     * (TargetService::saveMonthly) which only writes the boxes that were posted - the old handler wrote all four
     * columns from a form that posted one and so zeroed deals, value and listings for every agent on the page.
     */
    public function save(Request $request)
    {
        // Monthly targets: manage_targets permission required
        abort_unless(auth()->user()->hasPermission('manage_targets'), 403);

        $period = (string)$request->input('period');
        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $period)) {
            return back()->withErrors('Invalid period.')->withInput();
        }

        $auth = auth()->user();
        $data = $request->input('targets', []);
        if (!is_array($data)) $data = [];
        $visible = \App\Services\RoadToSold\VisibleAgents::for($auth);
        $service = app(\App\Services\RoadToSold\TargetService::class);

        DB::transaction(function () use ($data, $period, $auth, $visible, $service) {
            foreach ($data as $userId => $row) {
                $agent = $visible->get((int) $userId);   // own / branch / agency breadth, decided in the query
                if (! $agent || ! is_array($row)) continue;
                $posted = array_intersect_key($row, array_flip(\App\Services\RoadToSold\TargetService::MONTHLY_FIELDS));
                $service->saveMonthly($auth, $agent, $period, $posted);
            }
        });

        return redirect()->to(route('road-to-sold.settings.targets.index', ['period' => $period]) . '#monthly')
            ->with('success', 'Targets saved.');
    }

    
    public function agentDaily(Request $request)
    {
        $auth = auth()->user();
        abort_unless($auth->hasPermission('targets.view'), 403);

        $agentScope = PermissionService::getDataScope($auth, 'targets');

        // Agents: dedicated daily capture screen
        // BM/Admin: redirect to the Targets page (which already contains Daily Activity capture and scopes correctly)
        if ($agentScope !== 'own') {
            $dailyDate = (string)($request->get('date') ?: \Illuminate\Support\Carbon::now()->toDateString());
            if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dailyDate)) {
                $dailyDate = \Illuminate\Support\Carbon::now()->toDateString();
            }
            $period = substr($dailyDate, 0, 7);

            return redirect()->route('admin.targets', ['period' => $period, 'date' => $dailyDate]);
        }

        $userId = (int)($auth?->id ?? 0);
        if ($userId <= 0) abort(403);

        $dailyDate = (string)($request->get('date') ?: Carbon::now()->toDateString());
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dailyDate)) {
            $dailyDate = Carbon::now()->toDateString();
        }

        // === AGENT_DAILY_WEEK_PATCH:START ===
        // Week range (Mon–Sun) + 7-day history strip
        $tz = config('app.timezone') ?: 'UTC';
        $selectedDate = Carbon::parse($dailyDate, $tz)->startOfDay();
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd   = $weekStart->copy()->addDays(6)->endOfDay();

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $weekDays[] = [
                'date' => $d->toDateString(),
                'label' => $d->format('D j M'),
                'is_selected' => $d->toDateString() === $selectedDate->toDateString(),
                'is_today' => $d->isSameDay(Carbon::now($tz)),
            ];
        }
        // === AGENT_DAILY_WEEK_PATCH:END ===

        $period = substr($dailyDate, 0, 7);
        $branchId = (int)($auth?->branch_id ?? 0);

        // Branch name lookup (for display)
        $branchNames = Branch::query()->pluck('name', 'id')->all();

        // Effective daily columns for THIS agent (branch override if exists, else global default)
        $dailyCols = DB::table('activity_columns as ac')
            ->leftJoin('branch_activity_columns as bac', function ($join) use ($branchId) {
                $join->on('bac.key', '=', 'ac.key')
                     ->where('bac.branch_id', '=', $branchId);
            })
            ->selectRaw('
                ac.key as key,
                ac.label as label,
                COALESCE(bac.points_weight, ac.points_weight) as points_weight,
                ac."group" as "group",
                COALESCE(bac.is_enabled, ac.default_enabled) as is_enabled,
                COALESCE(bac.sort_order, ac.sort_order) as sort_order
            ')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($r) {
                return [
                    'key' => (string)$r->key,
                    'label' => (string)$r->label,
                    'group' => $r->group !== null ? (string)$r->group : null,
                    'is_enabled' => (int)$r->is_enabled,
                    'points_weight' => (float)($r->points_weight ?? 1.0),
                    'sort_order' => (int)$r->sort_order,
                ];
            })
            ->filter(function ($c) { return (int)$c['is_enabled'] === 1; })
            ->values()
            ->all();

        // Daily activity row for this agent
        $dailyActivities = DailyActivity::query()
            ->where('activity_date', $dailyDate)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('user_id');

        // === AGENT_DAILY_WEEK_PATCH:WEEKLOAD ===
        // Load 7 days of daily_activities for this agent (Mon–Sun)
        $dailyWeek = DailyActivity::query()
            ->where('user_id', $userId)
            ->whereBetween('activity_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->keyBy(function ($row) { return (string)$row->activity_date; });

        // Points per day = sum( value(field) * points_weight ) across enabled columns
        $pointsByDay = [];
        foreach ($weekDays as $d) {
            $date = (string)$d['date'];
            $row = $dailyWeek->get($date);
            $sum = 0.0;
            if ($row) {
                foreach ($dailyCols as $c) {
                    $key = (string)($c['key'] ?? '');
                    if ($key === '') continue;
                    $w = (float)($c['points_weight'] ?? 1.0);
                    $val = (float)($row->{$key} ?? 0);
                    $sum += ($val * $w);
                }
            }
            $pointsByDay[$date] = $sum;
        }


        // Provide $users collection shaped like the targets page expects (single row)
        $users = User::query()->where('id', $userId)->get();


        // === AGENT_DAILY_UI_PATCH:WEEK_AND_POINTS ===
        // Week (Mon–Sun) + 7-day history + points per day (based on activity_columns.points_weight)
        $tz = config('app.timezone') ?: 'UTC';
        $selectedDateObj = \Carbon\Carbon::parse($dailyDate, $tz)->startOfDay();
        $weekStart = $selectedDateObj->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->startOfDay();
        $weekEnd   = $weekStart->copy()->addDays(6)->endOfDay();

        // Pull this agent's activities for the whole week
        $weekRows = \App\Models\DailyActivity::query()
            ->where('user_id', $userId)
            ->whereBetween('activity_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();

        $weekByDate = $weekRows->keyBy(function ($r) {
            return \Carbon\Carbon::parse($r->activity_date)->toDateString();
        });

        // Build points weights map from the effective daily columns
        $weights = [];
        foreach ($dailyCols as $c) {
            $k = (string)($c['key'] ?? '');
            if ($k === '' || $k === 'notes') continue;
            $weights[$k] = (float)($c['points_weight'] ?? 1);
        }

        $pointsByDay = [];
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $ds = $d->toDateString();

            $row = $weekByDate->get($ds);
            if (!$row) {
                $pointsByDay[$ds] = null;
            } else {
                $sum = 0.0;
                foreach ($weights as $k => $w) {
                    $v = 0;
                    if (isset($row->$k)) {
                        $v = (float)$row->$k;
                    }
                    $sum += ($v * $w);
                }
                $pointsByDay[$ds] = $sum;
            }

            $weekDays[] = [
                'date' => $ds,
                'label' => $d->format('D j M'),
                'is_selected' => ($ds === $selectedDateObj->toDateString()),
                'is_today' => $d->isSameDay(\Carbon\Carbon::now($tz)),
            ];
        }

        return view('agent.daily', [
            'period' => $period,
            'dailyDate' => $dailyDate,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'weekDays' => $weekDays,
            'pointsByDay' => $pointsByDay,
            'dailyCols' => $dailyCols,
            'dailyActivities' => $dailyActivities,
            'users' => $users,
            'branchNames' => $branchNames,

            'isAdmin' => false,
            'isBM' => false,
            'isAgent' => true,
        
            // Week UI
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'weekDays' => $weekDays,
            'pointsByDay' => $pointsByDay,
]);
    }

    public function saveDaily(Request $request)
    {
        // Daily activity: permission-based access
        $auth = auth()->user();
        abort_unless($auth->hasPermission('targets.view'), 403);

        $dailyScope = PermissionService::getDataScope($auth, 'targets');

        $activityDate = (string)$request->input('activity_date');
        if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $activityDate)) {
            return back()->withErrors('Invalid activity date.')->withInput();
        }

        $period = substr($activityDate, 0, 7);

        $branchId = $auth?->branch_id;

        $data = $request->input('daily', []);
        if (!is_array($data)) $data = [];

        // Accept both payload shapes:
        // 1) Admin grid: daily[user_id][field] = value
        // 2) Agent simple: daily[field] = value  (assume current user)
        $authId = (int)($auth?->id ?? 0);
        $looksLikeGrid = false;
        foreach ($data as $k => $v) {
            // If any top-level key is numeric, we assume grid format
            if (is_string($k) && ctype_digit($k)) { $looksLikeGrid = true; break; }
            if (is_int($k)) { $looksLikeGrid = true; break; }
        }
        if (!$looksLikeGrid && $authId > 0) {
            $data = [$authId => $data];
        }

        // Whitelist columns from the actual table (prevents unexpected keys)
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('daily_activities');
        $allowed = array_fill_keys(array_map('strval', $cols), true);

        // Disallow meta/system columns from being set via payload
        $blocked = [
            'id' => true,
            'activity_date' => true,
            'period' => true,
            'user_id' => true,
            'branch_id' => true,
            'created_at' => true,
            'updated_at' => true,
        ];
        DB::transaction(function () use ($data, $activityDate, $period, $auth, $branchId, $allowed, $blocked, $dailyScope) {
            foreach ($data as $userId => $row) {
                $userId = (int)$userId;
                if ($userId <= 0) continue;

                // Own-scoped users can ONLY save their own row
                if ($dailyScope === 'own') {
                    if ($userId !== (int)$auth->id) continue;
                }

                $u = User::find($userId);
                if (!$u) continue;

                // Branch-scoped users can only save for their branch
                if ($dailyScope === 'branch') {
                    if ((int)$u->branch_id !== (int)$branchId) continue;
                }

                $payload = [];
                if (is_array($row)) {
                    foreach ($row as $k => $v) {
                        $k = (string)$k;
                        if ($k === '') continue;
                        if (!isset($allowed[$k])) continue;
                        if (isset($blocked[$k])) continue;

                        if ($k === 'notes') {
                            $payload[$k] = is_array($v) ? '' : trim((string)$v);
                            continue;
                        }

                        $n = (int)$v;
                        if ($n < 0) $n = 0;
                        $payload[$k] = $n;
                    }
                }

                // Audit
                if (isset($allowed['created_by']) && !isset($blocked['created_by'])) {
                    $payload['created_by'] = (int)($auth?->id ?? null);
                }
                if (isset($allowed['updated_by']) && !isset($blocked['updated_by'])) {
                    $payload['updated_by'] = (int)($auth?->id ?? null);
                }

                DailyActivity::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'activity_date' => $activityDate,
                    ],
                    array_merge(
                        ['period' => $period, 'branch_id' => $u->branch_id],
                        $payload
                    )
                );
            }
        });

        if ($dailyScope === 'own') {
            return redirect()->route('agent.daily', ['date' => $activityDate])
                ->with('status', 'Daily activity saved.');
        }

        return redirect()->route('admin.targets', ['period' => $period, 'date' => $activityDate])
            ->with('status', 'Daily activity saved.');

    }
    
    // =========================
    // V2: Activity Definitions (new model)
    // =========================
    public function activityDefinitions(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_targets'), 403);

        $auth = auth()->user();
        $defScope = PermissionService::getDataScope($auth, 'targets');
        $agencyId = $auth?->effectiveAgencyId();
        abort_unless($agencyId, 403);

        // Tenant-isolation fix — this used to read the single shared
        // scope='system' row set, so every agency's admin saw (and edited,
        // see activityDefinitionsSave()) the exact same global list. Each
        // agency now has its OWN scope='agency' copy, seeded from the
        // system template the first time this loads (safe/idempotent — see
        // ActivityDefinitionDefaultsService).
        app(\App\Services\ActivityDefinitions\ActivityDefinitionDefaultsService::class)->ensureDefaults($agencyId);

        $definitions = DB::table('activity_definitions')
            ->where('scope', 'agency')
            ->where('agency_id', $agencyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.targets.activity-definitions', [
            'definitions' => $definitions,
            'branchId' => null,
            'isAdmin' => $defScope === 'all',
            'isBM' => $defScope === 'branch',
        ]);
    }



    
    public function activityDefinitionsSave(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_targets'), 403);

        $auth = auth()->user();
        $agencyId = $auth?->effectiveAgencyId();
        abort_unless($agencyId, 403);

        $name = trim((string)$request->input('name'));
        if ($name === '') {
            return back()->withErrors('Name is required.');
        }

        $weight = (float)$request->input('weight', 1);
        if ($weight < 0) $weight = 0;

        $order = (int)$request->input('sort_order', 100);
        if ($order < 0) $order = 0;

        $mode = strtolower(trim((string)$request->input('scoring_mode', 'count')));
        if (!in_array($mode, ['count', 'once'], true)) {
            $mode = 'count';
        }

        $isActive = $request->has('is_enabled') ? 1 : 0;

        $id = $request->input('id');

        $payload = [
            'name' => $name,
            'weight' => $weight,
            'sort_order' => $order,
            'scoring_mode' => $mode,
            'is_enabled' => $isActive,
            'updated_at' => now(),
        ];

        if ($id) {
            // Tenant-isolation fix — this used to update by bare id with no
            // ownership check, so any agency's admin could edit the ONE
            // shared scope='system' row set (or, now, another agency's own
            // row by guessing its id). Only this agency's own row may be
            // updated.
            $updated = DB::table('activity_definitions')
                ->where('id', (int)$id)
                ->where('scope', 'agency')
                ->where('agency_id', $agencyId)
                ->update($payload);

            abort_unless($updated > 0, 403, 'You can only edit your own agency\'s activity definitions.');
        } else {
            // Tenant-isolation fix — this used to hardcode scope='system',
            // agency_id=null, so "adding" a new activity actually created a
            // new GLOBAL row visible to (and editable by) every agency.
            DB::table('activity_definitions')->insert(array_merge($payload, [
                'scope' => 'agency',
                'agency_id' => $agencyId,
                'branch_id' => null,
                'created_at' => now(),
            ]));
        }

        return redirect()
            ->route('admin.targets.activity.definitions')
            ->with('status', 'Activity added.');
    }



    public function activitySetup(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_targets'), 403);

        $auth = auth()->user();
        $setupScope = PermissionService::getDataScope($auth, 'targets');

        // Branch context:
        // - Branch-scoped: always their branch
        // - All-scoped: optional branch_id querystring to edit overrides; otherwise edits global defaults
        $branchId = null;
        if ($setupScope === 'branch') {
            $branchId = (int)($auth?->branch_id ?? 0);
        } elseif ($setupScope === 'all') {
            $branchId = (int)($request->get('branch_id') ?? 0);

            // AT-SEC: 'all' data-scope is grantable to per-agency admin/owner-flagged
            // roles, not just platform System Owners — a non-owner with that scope
            // must not be able to view another agency's branch overrides by supplying
            // a foreign branch_id. Mirrors AgencyApiKeyController::authorizeAgency() /
            // BranchAssignmentController::createBranch()'s isOwnerRole()+effectiveAgencyId() idiom.
            if ($branchId > 0 && !$auth->isOwnerRole()) {
                $ownsBranch = Branch::withoutGlobalScopes()
                    ->whereKey($branchId)
                    ->where('agency_id', $auth->effectiveAgencyId())
                    ->exists();
                abort_unless($ownsBranch, 403, 'You can only manage activity setup for your own agency.');
            }
        }
        if ($branchId <= 0) $branchId = null;

        $columns = DB::table('activity_columns')
            ->select('key', 'label', 'group', 'input_type', 'default_enabled', 'sort_order', 'points_weight')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        $branchOverrides = [];
        if ($branchId !== null) {
            $rows = DB::table('branch_activity_columns')
                ->select('key', 'is_enabled', 'sort_order', 'points_weight')
                ->where('branch_id', $branchId)
                ->get();

            foreach ($rows as $r) {
                $branchOverrides[(string)$r->key] = [
                    'is_enabled' => (int)$r->is_enabled,
                    'sort_order' => $r->sort_order === null ? null : (int)$r->sort_order,
                    'points_weight' => $r->points_weight === null ? null : (float)$r->points_weight,
                ];
            }
        }

        // Cross-agency isolation audit 2026-08-20, finding M2: this raw
        // DB::table() query bypassed Branch's BelongsToAgency global scope
        // entirely, listing every branch across every agency into this
        // dropdown -- 20 lines below (897-903), the SAME setupScope==='all'
        // branch selection is correctly checked against the caller's own
        // agency, but this listing query was missed. Branch::query() is
        // auto-scoped, matching the pattern already used elsewhere in this
        // controller (see the branchNames lookups above).
        $branches = [];
        if ($setupScope === 'all') {
            $branches = Branch::query()->select('id', 'name')->orderBy('name')->get();
        }

        return view('admin.targets.activity-setup', [
            'columns' => $columns,
            'branchId' => $branchId,
            'branchOverrides' => $branchOverrides,
            'branches' => $branches,
            'isAdmin' => $setupScope === 'all',
            'isBM' => $setupScope === 'branch',
        ]);
    }

    public function activitySetupSave(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_targets'), 403);

        $auth = auth()->user();
        $setupSaveScope = PermissionService::getDataScope($auth, 'targets');

        $branchId = null;
        if ($setupSaveScope === 'branch') {
            $branchId = (int)($auth?->branch_id ?? 0);
        } elseif ($setupSaveScope === 'all') {
            $branchId = (int)($request->input('branch_id') ?? 0);

            // AT-SEC: 'all' data-scope is grantable to per-agency admin/owner-flagged
            // roles, not just platform System Owners — a non-owner with that scope
            // must not be able to overwrite another agency's branch_activity_columns
            // by supplying a foreign branch_id. Verify the target branch belongs to
            // the caller's own agency before it is used for anything (lookup below,
            // and the updateOrInsert write). Mirrors AgencyApiKeyController::authorizeAgency()
            // / BranchAssignmentController::createBranch()'s isOwnerRole()+effectiveAgencyId() idiom.
            if ($branchId > 0 && !$auth->isOwnerRole()) {
                $ownsBranch = Branch::withoutGlobalScopes()
                    ->whereKey($branchId)
                    ->where('agency_id', $auth->effectiveAgencyId())
                    ->exists();
                abort_unless($ownsBranch, 403, 'You can only manage activity setup for your own agency.');
            }
        }
        if ($branchId <= 0) $branchId = null;

        $rows = $request->input('cols', []);
        if (!is_array($rows)) $rows = [];

        // Build allow-list from activity_columns to prevent unexpected keys
        $allowed = DB::table('activity_columns')->pluck('key')->all();
        $allowed = array_map('strval', $allowed);
        $allowedSet = array_fill_keys($allowed, true);

        DB::transaction(function () use ($rows, $allowedSet, $branchId) {

            if ($branchId === null) {
                // GLOBAL save (admin only)
                foreach ($rows as $key => $row) {
                    $key = (string)$key;
                    if (!isset($allowedSet[$key])) continue;

                    $label = isset($row['label']) ? trim((string)$row['label']) : '';
                    if ($label === '') $label = $key;

                    $group = isset($row['group']) ? trim((string)$row['group']) : null;
                    if ($group === '') $group = null;

                    $enabled = isset($row['enabled']) ? 1 : 0;
                    $order = isset($row['order']) ? (int)$row['order'] : 100;

                    $weight = isset($row['weight']) ? (float)$row['weight'] : 1.0;
                    if ($weight < 0) $weight = 0;

                    if ($order < 0) $order = 0;

                    DB::table('activity_columns')
                        ->where('key', $key)
                        ->update([
                            'label' => $label,
                            'group' => $group,
                            'default_enabled' => $enabled,
                            'sort_order' => $order,
                            'points_weight' => $weight,
                            'updated_at' => now(),
                        ]);
                }
            } else {
                // BRANCH override save (admin w/ branch_id OR branch manager)
                foreach ($rows as $key => $row) {
                    $key = (string)$key;
                    if (!isset($allowedSet[$key])) continue;

                    $enabled = isset($row['enabled']) ? 1 : 0;
                    $order = array_key_exists('order', $row) ? (int)$row['order'] : null;

                    $weight = null;
                    if (array_key_exists('weight', $row)) {
                        $wRaw = $row['weight'];
                        if ($wRaw === '' || $wRaw === null) {
                            $weight = null;
                        } else {
                            $w = (float)$wRaw;
                            if ($w < 0) $w = 0;
                            $weight = $w;
                        }
                    }

                    if ($order !== null && $order < 0) $order = 0;

                    DB::table('branch_activity_columns')
                        ->updateOrInsert(
                            ['branch_id' => $branchId, 'key' => $key],
                            [
                                // branch_activity_columns.agency_id is NOT NULL; raw
                                // updateOrInsert gets no BelongsToAgency stamp. Derive
                                // from the branch being written — its tenant (AT-203).
                                'agency_id' => \App\Models\Branch::withoutGlobalScopes()->whereKey($branchId)->value('agency_id'),
                                'is_enabled' => $enabled,
                                'sort_order' => $order,
                                'points_weight' => $weight,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                }
            }
        });

        $redir = ['status' => 'Activity columns saved.'];
        if ($branchId !== null) {
            return redirect()->route('admin.targets.activity.setup', ['branch_id' => $branchId])->with($redir);
        }
        return redirect()->route('admin.targets.activity.setup')->with($redir);
    }


    public function activityColumnCreate(Request $request)
    {
        // All-scoped only: create a new activity column definition
        $colScope = PermissionService::getDataScope(auth()->user(), 'targets');
        abort_unless($colScope === 'all', 403);

        $key = strtolower(trim((string)$request->input('key', '')));
        $label = trim((string)$request->input('label', ''));
        $group = trim((string)$request->input('group', ''));
        $inputType = strtolower(trim((string)$request->input('input_type', 'number')));

        $enabled = $request->boolean('default_enabled', true) ? 1 : 0;
        $order = (int)$request->input('sort_order', 100);
        if ($order < 0) $order = 0;

        $weight = (float)$request->input('points_weight', 1.0);
        if ($weight < 0) $weight = 0;

        if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            return redirect()->route('admin.targets.activity.setup')
                ->withErrors(['key' => 'Key must be snake_case (letters, numbers, underscores), starting with a letter.'])
                ->withInput();
        }

        if ($label === '') $label = $key;

        // Safety: must exist as a REAL column on daily_activities, otherwise capture screens break
        if (!\Illuminate\Support\Facades\Schema::hasColumn('daily_activities', $key)) {
            return redirect()->route('admin.targets.activity.setup')
                ->withErrors(['key' => "daily_activities does not have column '{$key}'. Create a migration to add it first, then add it here."])
                ->withInput();
        }

        // Uniqueness
        $exists = \Illuminate\Support\Facades\DB::table('activity_columns')->where('key', $key)->exists();
        if ($exists) {
            return redirect()->route('admin.targets.activity.setup')
                ->withErrors(['key' => "Key '{$key}' already exists."])
                ->withInput();
        }

        $row = [
            'key' => $key,
            'label' => $label,
            'group' => ($group === '' ? null : $group),
            'input_type' => ($inputType === '' ? 'number' : $inputType),
            'default_enabled' => $enabled,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // points_weight exists via later migration, only write it if the column exists
        if (\Illuminate\Support\Facades\Schema::hasColumn('activity_columns', 'points_weight')) {
            $row['points_weight'] = $weight;
        }

        \Illuminate\Support\Facades\DB::table('activity_columns')->insert($row);

        return redirect()->route('admin.targets.activity.setup')
            ->with('status', "Added activity column: {$key}");
    }


}
