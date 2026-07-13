<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class AccountSetupController extends Controller
{
    public function show(Request $request, User $user)
    {
        // Signed URL validation is handled by the 'signed' middleware on the route.
        // If the user has already completed setup, redirect to login.
        if ($user->email_verified_at) {
            return redirect()->route('login')->with('status', 'Your account is already set up. Please sign in.');
        }

        // The POST that actually sets the password is ALSO signed (the route
        // carries the 'signed' middleware), so the form must submit to a signed
        // URL — otherwise an anonymous caller could POST straight to
        // account-setup/{id} and seize any account by guessing its id. The window
        // matches the 7-day invite link so a legitimate invitee is never blocked.
        $formAction = URL::temporarySignedRoute(
            'account.setup.store',
            now()->addDays(7),
            ['user' => $user->id]
        );

        return view('auth.account-setup', ['user' => $user, 'formAction' => $formAction]);
    }

    public function store(Request $request, User $user)
    {
        // Signature validated by the 'signed' middleware on the route. Re-check
        // the account is still pending so a valid link can never overwrite the
        // password of an already-active account (the guard show() already has).
        if ($user->email_verified_at) {
            return redirect()->route('login')->with('status', 'Your account is already set up. Please sign in.');
        }

        $request->validate([
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ]);

        // Set password directly (the 'hashed' cast on User auto-hashes, so don't Hash::make)
        $user->password = $request->password;
        $user->email_verified_at = now();
        $user->save();

        return redirect()->route('login')->with('status', 'Your password has been set. You can now sign in.');
    }
}
