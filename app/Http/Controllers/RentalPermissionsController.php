<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RentalPermissionsController extends Controller
{
    private function assertAccess()
    {
        abort_unless(Auth::user()?->hasPermission('manage_rentals'), 403);
    }

    public function index()
    {
        $this->assertAccess();

        $users = User::orderBy('name')->get();

        return view('rentals.permissions', compact('users'));
    }

    public function update(Request $request)
    {
        $this->assertAccess();

        $allowedIds = collect($request->input('can_capture_rentals', []))
            ->map(fn($id) => (int)$id)
            ->values()
            ->all();

        // Set all to false first
        User::query()->update(['can_capture_rentals' => 0]);

        // Then enable selected
        if (!empty($allowedIds)) {
            User::whereIn('id', $allowedIds)
                ->update(['can_capture_rentals' => 1]);
        }

        return redirect()
            ->route('rentals.permissions')
            ->with('success', 'Rental permissions updated');
    }
}
