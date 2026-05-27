<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgencyController extends Controller
{
    private const DESTROY_PASSWORD = 'Delete@corex@confirm!!';

    public function toggleActive(Agency $agency)
    {
        $agency->update(['is_active' => !$agency->is_active]);

        Log::info('Agency active toggled', [
            'agency_id'  => $agency->id,
            'is_active'  => $agency->is_active,
            'changed_by' => auth()->id(),
        ]);

        $state = $agency->is_active ? 'enabled' : 'disabled';
        return redirect()->route('agencies.index')->with('success', "Agency \"{$agency->name}\" {$state}.");
    }


    public function index()
    {
        $agencies = Agency::withCount(['branches', 'users'])->orderBy('name')->get();

        return view('admin.agencies.index', compact('agencies'));
    }

    public function create()
    {
        return view('admin.agencies.create-edit', ['agency' => null, 'branches' => collect()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'slug'             => 'nullable|string|max:80|unique:agencies,slug',
            'sidebar_color'    => 'nullable|string|max:20',
            'icon_color'       => 'nullable|string|max:20',
            'default_color'    => 'nullable|string|max:20',
            'button_color'     => 'nullable|string|max:20',
            'is_active'        => 'nullable|boolean',
            'is_demo'          => 'nullable|boolean',
            'trading_name'     => 'nullable|string|max:255',
            'tagline'          => 'nullable|string|max:255',
            'address'          => 'nullable|string|max:500',
            'phone'            => 'nullable|string|max:255',
            'phone_secondary'  => 'nullable|string|max:255',
            'fax'              => 'nullable|string|max:255',
            'email'            => 'nullable|string|max:255',
            'reg_no'           => 'nullable|string|max:255',
            'vat_no'           => 'nullable|string|max:255',
            'ffc_no'           => 'nullable|string|max:255',
            'fic_no'           => 'nullable|string|max:255',
            'p24_agency_id'    => 'nullable|string|max:32',
            'p24_agency_label' => 'nullable|string|max:100',
            'logo'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',

            // First Admin — required for live agencies, skipped for demo agencies.
            // See .ai/specs/agency-admin-rule.md.
            'admin_name'     => 'required_if:is_demo,0,false,,|nullable|string|max:191',
            'admin_email'    => 'required_if:is_demo,0,false,,|nullable|email|max:191|unique:users,email',
            'admin_password' => 'required_if:is_demo,0,false,,|nullable|string|min:8',
            'admin_cell'     => 'nullable|string|max:50',
        ]);

        $data['slug']          = $data['slug'] ?? Str::slug($data['name']);
        $data['sidebar_color'] = $data['sidebar_color'] ?? '#0ea5e9';
        $data['icon_color']    = $data['icon_color']    ?? '#0ea5e9';
        $data['default_color'] = $data['default_color'] ?? '#0b2a4a';
        $data['button_color']  = $data['button_color']  ?? '#0ea5e9';
        $data['is_active']     = (bool) ($data['is_active'] ?? true);
        $data['is_demo']       = (bool) ($data['is_demo'] ?? false);

        $isDemo = $data['is_demo'];
        $adminPayload = $isDemo ? null : [
            'name'     => $data['admin_name'],
            'email'    => $data['admin_email'],
            'password' => $data['admin_password'],
            'cell'     => $data['admin_cell'] ?? null,
        ];
        unset($data['logo'], $data['admin_name'], $data['admin_email'], $data['admin_password'], $data['admin_cell']);

        // Atomic: live agency + first Admin must succeed together. Demo agencies
        // skip the admin requirement entirely. See spec R1.
        $agency = DB::transaction(function () use ($data, $adminPayload) {
            $agency = Agency::create($data);

            if ($adminPayload) {
                User::create([
                    'name'      => $adminPayload['name'],
                    'email'     => $adminPayload['email'],
                    'password'  => Hash::make($adminPayload['password']),
                    'cell'      => $adminPayload['cell'],
                    'role'      => 'admin',
                    'agency_id' => $agency->id,
                    'is_active' => true,
                ]);
            }

            return $agency;
        });

        if ($request->hasFile('logo')) {
            $ext = $request->file('logo')->getClientOriginalExtension();
            $path = $request->file('logo')->storeAs(
                "agencies/{$agency->id}", "logo.{$ext}", 'public'
            );
            $agency->update(['logo_path' => $path]);
        }

        Log::info('Agency created', [
            'agency_id'   => $agency->id,
            'is_demo'     => $isDemo,
            'admin_email' => $adminPayload['email'] ?? null,
            'created_by'  => auth()->id(),
        ]);

        $msg = $isDemo
            ? "Demo agency \"{$data['name']}\" created (no Admin required)."
            : "Agency \"{$data['name']}\" created with Admin {$adminPayload['email']}.";

        return redirect()->route('agencies.index')->with('success', $msg);
    }

    public function edit(Agency $agency)
    {
        $this->authorizeAgencyScope($agency);
        $branches = \App\Models\Branch::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->orderBy('name')
            ->get();
        return view('admin.agencies.create-edit', compact('agency', 'branches'));
    }

    public function update(Request $request, Agency $agency)
    {
        $this->authorizeAgencyScope($agency);
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'sidebar_color'   => 'nullable|string|max:20',
            'icon_color'      => 'nullable|string|max:20',
            'default_color'   => 'nullable|string|max:20',
            'button_color'    => 'nullable|string|max:20',
            'is_active'       => 'nullable|boolean',
            'trading_name'    => 'nullable|string|max:255',
            'tagline'         => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:500',
            'phone'           => 'nullable|string|max:255',
            'phone_secondary' => 'nullable|string|max:255',
            'fax'             => 'nullable|string|max:255',
            'email'           => 'nullable|string|max:255',
            'reg_no'          => 'nullable|string|max:255',
            'vat_no'          => 'nullable|string|max:255',
            'ffc_no'          => 'nullable|string|max:255',
            'fic_no'          => 'nullable|string|max:255',
            'p24_agency_id'   => 'nullable|string|max:32',
            'p24_agency_label' => 'nullable|string|max:100',
            'p24_username'    => 'nullable|string|max:191',
            'p24_password'    => 'nullable|string|max:191',
            'p24_user_group_id' => 'nullable|string|max:64',
            'p24_enabled'     => 'nullable|boolean',
            'logo'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_logo'     => 'nullable|boolean',
        ]);

        $data['sidebar_color'] = $data['sidebar_color'] ?? '#0ea5e9';
        $data['icon_color']    = $data['icon_color']    ?? '#0ea5e9';
        $data['default_color'] = $data['default_color'] ?? '#0b2a4a';
        $data['button_color']  = $data['button_color']  ?? '#0ea5e9';
        $data['is_active']       = (bool) ($data['is_active'] ?? false);
        $data['p24_enabled']     = (bool) ($data['p24_enabled'] ?? false);

        // Don't overwrite stored password with empty string when user leaves the
        // (masked) password field blank — only update p24_password when supplied.
        if (array_key_exists('p24_password', $data) && ($data['p24_password'] === null || $data['p24_password'] === '')) {
            unset($data['p24_password']);
        }

        // Auto-enable P24 when both username and an effective password are present
        // (either newly supplied, or already stored). Stops the common footgun
        // of saving creds with the enable checkbox left unticked.
        $effectivePassword = $data['p24_password'] ?? $agency->p24_password;
        if (!empty($data['p24_username']) && !empty($effectivePassword)) {
            $data['p24_enabled'] = true;
        }

        // Detect P24 cred changes — trigger auto-sync after save.
        $credsChanged = ($agency->p24_username !== ($data['p24_username'] ?? null))
            || (isset($data['p24_password']) && $data['p24_password'] !== $agency->p24_password)
            || ($agency->p24_user_group_id !== ($data['p24_user_group_id'] ?? null))
            || ((bool) $agency->p24_enabled !== (bool) $data['p24_enabled']);

        $removeLogo = $data['remove_logo'] ?? false;
        unset($data['logo'], $data['remove_logo']);

        if ($removeLogo) {
            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }
            $data['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }
            $ext = $request->file('logo')->getClientOriginalExtension();
            $path = $request->file('logo')->storeAs(
                "agencies/{$agency->id}", "logo.{$ext}", 'public'
            );
            $data['logo_path'] = $path;
        }

        $agency->update($data);

        $extraFlash = null;
        if ($credsChanged && $agency->p24_enabled && !empty($agency->p24_username) && !empty($agency->p24_password)) {
            $agencyId = $agency->id;
            dispatch(function () use ($agencyId) {
                @set_time_limit(0);
                \Artisan::call('p24:sync-locations', ['--agency' => $agencyId]);
            })->afterResponse();
            $extraFlash = ['key' => 'success', 'msg' => 'Property24 locations sync queued. Refresh the page in a few minutes to see updated status.'];
        }

        $user = auth()->user();
        $redirect = ($user && !$user->isOwnerRole())
            ? redirect()->route('admin.company-settings')
            : redirect()->route('agencies.index');
        $redirect = $redirect->with('success', "Agency \"{$agency->name}\" updated.");
        if ($extraFlash) {
            $redirect = $redirect->with($extraFlash['key'], $extraFlash['msg']);
        }
        return $redirect;
    }

    /**
     * Test P24 credentials by hitting /echo-authenticated. JSON response.
     */
    public function testP24Connection(Agency $agency)
    {
        $this->authorizeAgencyScope($agency);

        if (empty($agency->p24_username) || empty($agency->p24_password)) {
            return response()->json(['success' => false, 'message' => 'No P24 credentials saved on this agency.'], 422);
        }

        $client = new \App\Services\Syndication\Property24\Property24ApiClient($agency);
        $result = $client->smokeTest();

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['success'] ?? false
                ? 'Connection OK — P24 credentials work.'
                : ($result['message'] ?? 'Unknown error'),
            'status'  => $result['status_code'] ?? null,
        ]);
    }

    /**
     * Manually trigger a P24 location refresh for this agency.
     */
    public function refreshP24Locations(Agency $agency)
    {
        $this->authorizeAgencyScope($agency);

        if (empty($agency->p24_username) || empty($agency->p24_password)) {
            return response()->json(['success' => false, 'message' => 'No P24 credentials saved on this agency.'], 422);
        }

        try {
            $agencyId = $agency->id;
            dispatch(function () use ($agencyId) {
                @set_time_limit(0);
                \Artisan::call('p24:sync-locations', ['--agency' => $agencyId]);
            })->afterResponse();
            return response()->json([
                'success' => true,
                'message' => 'Sync queued. Refresh the page in a few minutes to see the new last-synced timestamp.',
            ]);
        } catch (\Throwable $e) {
            $agency->forceFill(['p24_last_sync_error' => $e->getMessage()])->save();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function authorizeAgencyScope(Agency $agency): void
    {
        $user = auth()->user();
        if (!$user) {
            abort(403);
        }
        if ($user->isOwnerRole()) {
            return;
        }
        if ((int) $user->effectiveAgencyId() !== (int) $agency->id) {
            abort(403, 'You can only edit your own agency.');
        }
    }

    /**
     * Permanently delete an agency and every tenant-owned row belonging to it
     * (users, branches, properties, contacts, deals, presentations, documents,
     * and any other table with an agency_id column). Password-gated.
     *
     * Guarded against deleting the last remaining agency in the platform.
     */
    public function destroy(Request $request, Agency $agency)
    {
        if (!hash_equals(self::DESTROY_PASSWORD, (string) $request->input('delete_password'))) {
            return redirect()->route('agencies.index')->with(
                'error',
                'Incorrect delete password — agency was not deleted.'
            );
        }

        if (Agency::count() <= 1) {
            return redirect()->route('agencies.index')->with(
                'error',
                'You cannot delete the last remaining agency.'
            );
        }

        $agencyId   = $agency->id;
        $agencyName = $agency->name;

        $ownerRoleNames = DB::table('roles')->where('is_owner', true)->pluck('name')->all();

        // Find every table in the DB that has an agency_id column so we don't
        // leave orphaned tenant rows behind on hard-delete.
        $driver = DB::connection()->getDriverName();
        $tables = match ($driver) {
            'mysql', 'mariadb' => array_map(fn($r) => array_values((array)$r)[0], DB::select('SHOW TABLES')),
            'sqlite' => array_column(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name'),
            default  => [],
        };
        $cascadeTables = array_values(array_filter(
            $tables,
            fn ($t) => Schema::hasColumn($t, 'agency_id')
        ));

        $counts = [];
        DB::transaction(function () use ($cascadeTables, $tables, $agencyId, $ownerRoleNames, &$counts, $agency, $driver) {
            // Cascade rows referencing users we're about to delete (e.g. agent_scorecards)
            // which don't carry agency_id directly and would otherwise trip FK constraints.
            $userIdsToDelete = DB::table('users')
                ->where('agency_id', $agencyId)
                ->when(!empty($ownerRoleNames), fn ($q) => $q->whereNotIn('role', $ownerRoleNames))
                ->pluck('id')
                ->all();

            if (!empty($userIdsToDelete)) {
                // Discover every FK column that references users.id, regardless of name
                // (user_id, assigned_by, created_by, manager_id, etc.). Falls back to a
                // conventional column-name scan on non-MySQL drivers.
                $userRefs = [];
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $rows = DB::select(
                        "SELECT TABLE_NAME, COLUMN_NAME
                           FROM information_schema.KEY_COLUMN_USAGE
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND REFERENCED_TABLE_NAME = 'users'
                            AND REFERENCED_COLUMN_NAME = 'id'
                            AND TABLE_NAME <> 'users'"
                    );
                    foreach ($rows as $r) {
                        $userRefs[] = ['table' => $r->TABLE_NAME, 'column' => $r->COLUMN_NAME];
                    }
                } else {
                    $candidateCols = ['user_id', 'assigned_by', 'assigned_to', 'created_by', 'updated_by', 'owner_id', 'manager_id', 'agent_id'];
                    foreach ($tables as $t) {
                        if ($t === 'users') continue;
                        foreach ($candidateCols as $col) {
                            if (Schema::hasColumn($t, $col)) {
                                $userRefs[] = ['table' => $t, 'column' => $col];
                            }
                        }
                    }
                }

                foreach ($userRefs as $ref) {
                    $table  = $ref['table'];
                    $column = $ref['column'];
                    try {
                        // If the referencing table is tenant-scoped, hard-delete the rows.
                        // Otherwise just null the FK so the user delete can proceed.
                        if (Schema::hasColumn($table, 'agency_id')) {
                            $deleted = DB::table($table)->whereIn($column, $userIdsToDelete)->delete();
                            if ($deleted > 0) {
                                $counts[$table] = ($counts[$table] ?? 0) + $deleted;
                            }
                        } else {
                            DB::table($table)->whereIn($column, $userIdsToDelete)->update([$column => null]);
                        }
                    } catch (\Throwable $e) {
                        Log::error("Agency hard-delete failed on user-ref {$table}.{$column}", [
                            'agency_id' => $agencyId,
                            'error'     => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }
            }

            foreach ($cascadeTables as $table) {
                $query = DB::table($table)->where('agency_id', $agencyId);
                if ($table === 'users' && !empty($ownerRoleNames)) {
                    $query->whereNotIn('role', $ownerRoleNames);
                }
                try {
                    $counts[$table] = ($counts[$table] ?? 0) + $query->delete();
                } catch (\Throwable $e) {
                    Log::error("Agency hard-delete failed on {$table}", [
                        'agency_id' => $agencyId,
                        'error'     => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            if (!empty($ownerRoleNames)) {
                DB::table('users')
                    ->where('agency_id', $agencyId)
                    ->whereIn('role', $ownerRoleNames)
                    ->update(['agency_id' => null, 'branch_id' => null]);
            }

            if ($agency->logo_path) {
                Storage::disk('public')->delete($agency->logo_path);
            }

            $agency->forceDelete();
        });

        if (session('active_agency_id') == $agencyId) {
            session()->forget('active_agency_id');
        }

        Log::warning('Agency permanently deleted', [
            'agency_id'   => $agencyId,
            'agency_name' => $agencyName,
            'cascade'     => $counts,
            'deleted_by'  => auth()->id(),
        ]);

        $summary = collect($counts)
            ->filter(fn ($n) => $n > 0)
            ->map(fn ($n, $t) => "{$n} {$t}")
            ->implode(', ');

        $message = "Agency \"{$agencyName}\" permanently deleted."
            . ($summary ? " Removed: {$summary}." : '');

        return redirect()->route('agencies.index')->with('success', $message);
    }
}
