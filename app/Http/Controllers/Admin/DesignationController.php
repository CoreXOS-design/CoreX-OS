<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Http\Request;

class DesignationController extends Controller
{
    private function ensureAdmin(): void
    {
        abort_unless(auth()->user()?->isEffectiveAdmin(), 403);
    }

    public function index()
    {
        $this->ensureAdmin();

        $designations = Designation::orderBy('sort_order')->orderBy('name')->get();

        return view('admin.designations.index', compact('designations'));
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'name' => ['required','string','max:100'],
            'sort_order' => ['nullable','integer','min:0','max:1000000'],
            'is_enabled' => ['nullable','in:0,1'],
        ]);

        $name = trim((string)$data['name']);
        if ($name === '') return back()->withErrors('Name is required.');

        Designation::create([
            'name' => $name,
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'is_enabled' => isset($data['is_enabled']) && (string)$data['is_enabled'] === '1' ? 1 : 0,
        ]);

        return back()->with('status', 'Designation added.');
    }

    public function update(Request $request, Designation $designation)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'name' => ['required','string','max:100'],
            'sort_order' => ['nullable','integer','min:0','max:1000000'],
            'is_enabled' => ['nullable','in:0,1'],
        ]);

        $newName = trim((string)$data['name']);
        if ($newName === '') return back()->withErrors('Name is required.');

        $oldName = (string)$designation->name;

        $designation->update([
            'name' => $newName,
            'sort_order' => (int)($data['sort_order'] ?? $designation->sort_order),
            'is_enabled' => isset($data['is_enabled']) && (string)$data['is_enabled'] === '1' ? 1 : 0,
        ]);

        // If the designation name changed, sync users that were using the old string value
        if ($oldName !== $newName) {
            User::where('designation', $oldName)->update(['designation' => $newName]);
        }

        return back()->with('status', 'Designation updated.');
    }

    public function delete(Designation $designation)
    {
        $this->ensureAdmin();

        $name = (string)$designation->name;

        $inUse = User::where('designation', $name)->exists();
        if ($inUse) {
            return back()->withErrors("Cannot delete '{$name}' because at least one user is assigned to it.");
        }

        $designation->delete();

        return back()->with('status', 'Designation deleted.');
    }
}
