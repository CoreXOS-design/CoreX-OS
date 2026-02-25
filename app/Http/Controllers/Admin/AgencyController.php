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
            'name'             => 'required|string|max:100',
            'slug'             => 'nullable|string|max:80|unique:agencies,slug',
            'primary_color'    => 'nullable|string|max:20',
            'secondary_color'  => 'nullable|string|max:20',
            'tertiary_color'   => 'nullable|string|max:20',
            'is_active'        => 'nullable|boolean',
        ]);

        $data['slug']            = $data['slug'] ?? Str::slug($data['name']);
        $data['primary_color']   = $data['primary_color']   ?? '#0b2a4a';
        $data['secondary_color'] = $data['secondary_color'] ?? '#00b4d8';
        $data['tertiary_color']  = $data['tertiary_color']  ?? '#1a4a73';
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
            'name'            => 'required|string|max:100',
            'primary_color'   => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'tertiary_color'  => 'nullable|string|max:20',
            'is_active'       => 'nullable|boolean',
        ]);

        $data['primary_color']   = $data['primary_color']   ?? '#0b2a4a';
        $data['secondary_color'] = $data['secondary_color'] ?? '#00b4d8';
        $data['tertiary_color']  = $data['tertiary_color']  ?? '#1a4a73';
        $data['is_active']       = (bool) ($data['is_active'] ?? false);

        $agency->update($data);

        return redirect()->route('agencies.index')->with('success', "Agency \"{$agency->name}\" updated.");
    }
}
