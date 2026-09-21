<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * AT-423 — "Choose a new password" after an admin reset a sub-user's password to a
 * temporary one (users.must_change_password). Spec: one-email-sub-users.md §6.5.
 * EnsurePasswordChanged keeps every other page closed until this is done.
 */
class PasswordChangeRequiredController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (!$request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('auth.password-change-required', ['user' => $request->user()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (!$user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $request->validate([
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ], [
            'password.required'  => 'Choose a new password.',
            'password.min'       => 'Your new password must be at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if (Hash::check($request->input('password'), (string) $user->password)) {
            return back()->withErrors(['password' => 'Choose a password that is different from the temporary one your admin gave you.']);
        }

        // The 'hashed' cast hashes on assignment (same as AccountSetupController).
        $user->password = $request->input('password');
        $user->must_change_password = false;
        $user->save();

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Your new password is set.');
    }
}
