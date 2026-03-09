<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\Request;
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
            'name'           => 'required|string|max:100',
            'slug'           => 'nullable|string|max:80|unique:agencies,slug',
            'sidebar_color'  => 'nullable|string|max:20',
            'icon_color'     => 'nullable|string|max:20',
            'default_color'  => 'nullable|string|max:20',
            'button_color'   => 'nullable|string|max:20',
            'is_active'      => 'nullable|boolean',
        ]);

        $data['slug']          = $data['slug'] ?? Str::slug($data['name']);
        $data['sidebar_color'] = $data['sidebar_color'] ?? '#0ea5e9';
        $data['icon_color']    = $data['icon_color']    ?? '#0ea5e9';
        $data['default_color'] = $data['default_color'] ?? '#0b2a4a';
        $data['button_color']  = $data['button_color']  ?? '#0ea5e9';
        $data['is_active']       = (bool) ($data['is_active'] ?? true);

        Agency::create($data);

        return redirect()->route('agencies.index')->with('success', "Agency \"{$data['name']}\" created.");
    }

    public function edit(Agency $agency)
    {
        return view('admin.agencies.create-edit', compact('agency'));
    }

    public function update(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'sidebar_color'  => 'nullable|string|max:20',
            'icon_color'     => 'nullable|string|max:20',
            'default_color'  => 'nullable|string|max:20',
            'button_color'   => 'nullable|string|max:20',
            'is_active'      => 'nullable|boolean',
        ]);

        $data['sidebar_color'] = $data['sidebar_color'] ?? '#0ea5e9';
        $data['icon_color']    = $data['icon_color']    ?? '#0ea5e9';
        $data['default_color'] = $data['default_color'] ?? '#0b2a4a';
        $data['button_color']  = $data['button_color']  ?? '#0ea5e9';
        $data['is_active']       = (bool) ($data['is_active'] ?? false);

        $agency->update($data);

        return redirect()->route('agencies.index')->with('success', "Agency \"{$agency->name}\" updated.");
    }
}
