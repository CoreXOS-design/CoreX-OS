<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AgencyController extends Controller
{
    public function index()
    {
        $agencies = Agency::withCount(['branches', 'users'])->orderBy('name')->get();

        return view('admin.agencies.index', compact('agencies'));
    }

    public function create()
    {
        return view('admin.agencies.create-edit', ['agency' => null]);
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
            'logo'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $data['slug']          = $data['slug'] ?? Str::slug($data['name']);
        $data['sidebar_color'] = $data['sidebar_color'] ?? '#0ea5e9';
        $data['icon_color']    = $data['icon_color']    ?? '#0ea5e9';
        $data['default_color'] = $data['default_color'] ?? '#0b2a4a';
        $data['button_color']  = $data['button_color']  ?? '#0ea5e9';
        $data['is_active']       = (bool) ($data['is_active'] ?? true);

        unset($data['logo']);

        $agency = Agency::create($data);

        if ($request->hasFile('logo')) {
            $ext = $request->file('logo')->getClientOriginalExtension();
            $path = $request->file('logo')->storeAs(
                "agencies/{$agency->id}", "logo.{$ext}", 'public'
            );
            $agency->update(['logo_path' => $path]);
        }

        return redirect()->route('agencies.index')->with('success', "Agency \"{$data['name']}\" created.");
    }

    public function edit(Agency $agency)
    {
        return view('admin.agencies.create-edit', compact('agency'));
    }

    public function update(Request $request, Agency $agency)
    {
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
            'logo'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_logo'     => 'nullable|boolean',
        ]);

        $data['sidebar_color'] = $data['sidebar_color'] ?? '#0ea5e9';
        $data['icon_color']    = $data['icon_color']    ?? '#0ea5e9';
        $data['default_color'] = $data['default_color'] ?? '#0b2a4a';
        $data['button_color']  = $data['button_color']  ?? '#0ea5e9';
        $data['is_active']       = (bool) ($data['is_active'] ?? false);

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

        return redirect()->route('agencies.index')->with('success', "Agency \"{$agency->name}\" updated.");
    }

    /**
     * Delete an agency. Soft-archives every tenant-owned row in the agency
     * first (users, branches, properties, contacts, deals, presentations,
     * documents) so they disappear from the UI but remain recoverable via
     * `deleted_at`. The agency row itself is force-deleted so its unique
     * `slug` is freed for re-use.
     *
     * Guarded against deleting the last remaining agency in the platform.
     */
    public function destroy(Agency $agency)
    {
        if (Agency::count() <= 1) {
            return redirect()->route('agencies.index')->with(
                'error',
                'You cannot delete the last remaining agency.'
            );
        }

        $agencyId   = $agency->id;
        $agencyName = $agency->name;

        // Tables with agency_id + deleted_at that we soft-cascade.
        $cascadeTables = [
            'users',
            'branches',
            'properties',
            'contacts',
            'deals',
            'presentations',
            'documents',
        ];

        // Platform owners (is_owner = true roles) are never tied to a single
        // agency — they administer the whole system. Never archive their
        // accounts in a cascade, even if stray agency_id data leaked onto
        // their row.
        $ownerRoleNames = DB::table('roles')->where('is_owner', true)->pluck('name')->all();

        $counts = [];
        DB::transaction(function () use ($cascadeTables, $agencyId, $ownerRoleNames, &$counts, $agency) {
            $now = now();

            foreach ($cascadeTables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'agency_id')) {
                    continue;
                }

                $query = DB::table($table)->where('agency_id', $agencyId);

                if ($table === 'users' && ! empty($ownerRoleNames)) {
                    $query->whereNotIn('role', $ownerRoleNames);
                }

                if (Schema::hasColumn($table, 'deleted_at')) {
                    $query->whereNull('deleted_at');
                    $counts[$table] = $query->update(['deleted_at' => $now]);
                } else {
                    $counts[$table] = $query->delete();
                }
            }

            // Detach any owners whose agency_id still pointed at the
            // now-deleted agency so they don't end up orphaned.
            if (! empty($ownerRoleNames)) {
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

        Log::info('Agency cascade-deleted', [
            'agency_id'   => $agencyId,
            'agency_name' => $agencyName,
            'cascade'     => $counts,
            'deleted_by'  => auth()->id(),
        ]);

        $summary = collect($counts)
            ->filter(fn ($n) => $n > 0)
            ->map(fn ($n, $t) => "{$n} {$t}")
            ->implode(', ');

        $message = "Agency \"{$agencyName}\" deleted."
            . ($summary ? " Archived: {$summary}." : '');

        return redirect()->route('agencies.index')->with('success', $message);
    }
}
