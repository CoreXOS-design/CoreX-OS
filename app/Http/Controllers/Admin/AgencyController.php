<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\Request;
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
     * Permanently delete an agency. Unlike operational data (contacts,
     * deals, documents) agencies are tenant definitions, and the `slug`
     * column is uniquely indexed — a soft-deleted row keeps the slug
     * reserved and blocks the admin from re-creating an agency with the
     * same identifier. So this is a hard delete, guarded by:
     *   - no remaining branches,
     *   - no remaining users,
     *   - never the last agency in the platform.
     * The branch/user guard also prevents us from orphaning operational
     * rows that still point at this agency_id.
     */
    public function destroy(Agency $agency)
    {
        $branchCount       = $agency->branches()->count();
        $userCount         = $agency->users()->count();
        $propertyCount     = \DB::table('properties')->where('agency_id', $agency->id)->whereNull('deleted_at')->count();
        $contactCount      = \DB::table('contacts')->where('agency_id', $agency->id)->whereNull('deleted_at')->count();
        $dealCount         = \DB::table('deals')->where('agency_id', $agency->id)->whereNull('deleted_at')->count();
        $presentationCount = \DB::table('presentations')->where('agency_id', $agency->id)->whereNull('deleted_at')->count();

        $blockers = array_filter([
            $branchCount       ? "{$branchCount} branch(es)"          : null,
            $userCount         ? "{$userCount} user(s)"               : null,
            $propertyCount     ? "{$propertyCount} property(ies)"     : null,
            $contactCount      ? "{$contactCount} contact(s)"         : null,
            $dealCount         ? "{$dealCount} deal(s)"               : null,
            $presentationCount ? "{$presentationCount} presentation(s)" : null,
        ]);

        if (!empty($blockers)) {
            return redirect()->route('agencies.index')->with(
                'error',
                "Cannot delete \"{$agency->name}\": it still has " . implode(', ', $blockers) . ". Re-assign or remove them first."
            );
        }

        if (Agency::count() <= 1) {
            return redirect()->route('agencies.index')->with(
                'error',
                'You cannot delete the last remaining agency.'
            );
        }

        if (session('active_agency_id') == $agency->id) {
            session()->forget('active_agency_id');
        }

        if ($agency->logo_path) {
            Storage::disk('public')->delete($agency->logo_path);
        }

        $agency->forceDelete();

        return redirect()->route('agencies.index')->with('success', "Agency \"{$agency->name}\" permanently deleted.");
    }
}
