<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\OneEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

class AccountSetupController extends Controller
{
    /**
     * AT-423 — a sub-user's set-up link first asks for their username (the link may have
     * been forwarded from a shared inbox, so the username proves it reached the right
     * person). 5 wrong tries per link + IP locks it for 15 minutes.
     * Spec: .ai/specs/one-email-sub-users.md §6.3.
     */
    private const USERNAME_MAX_TRIES = 5;
    private const USERNAME_LOCK_SECONDS = 900;

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

        return view('auth.account-setup', [
            'user'          => $user,
            'formAction'    => $formAction,
            'needsUsername' => $this->needsUsername($request, $user),
        ]);
    }

    public function store(Request $request, User $user)
    {
        // Signature validated by the 'signed' middleware on the route. Re-check
        // the account is still pending so a valid link can never overwrite the
        // password of an already-active account (the guard show() already has).
        if ($user->email_verified_at) {
            return redirect()->route('login')->with('status', 'Your account is already set up. Please sign in.');
        }

        if ($this->needsUsername($request, $user)) {
            return $this->checkUsername($request, $user);
        }

        $request->validate([
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ]);

        // Set password directly (the 'hashed' cast on User auto-hashes, so don't Hash::make)
        $user->password = $request->password;
        $user->email_verified_at = now();
        $user->save();

        $request->session()->forget($this->sessionKey($user));

        return redirect()->route('login')->with('status', $user->isSubUser()
            ? "Your password has been set. Sign in with your username {$user->email}."
            : 'Your password has been set. You can now sign in.');
    }

    private function needsUsername(Request $request, User $user): bool
    {
        return $user->isSubUser() && !$request->session()->get($this->sessionKey($user), false);
    }

    private function checkUsername(Request $request, User $user)
    {
        $limiterKey = 'subuser-setup:' . $user->id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::USERNAME_MAX_TRIES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            return back()->withErrors(['username' => "Too many wrong tries. Please wait {$minutes} minute(s) and try again."]);
        }

        if (blank($request->input('username'))) {
            return back()->withErrors(['username' => 'Enter your username.']);
        }

        if (!app(OneEmailService::class)->usernameMatches($user, $request->input('username'))) {
            RateLimiter::hit($limiterKey, self::USERNAME_LOCK_SECONDS);

            return back()->withInput($request->only('username'))
                ->withErrors(['username' => "That username doesn't match this invitation."]);
        }

        RateLimiter::clear($limiterKey);
        $request->session()->put($this->sessionKey($user), true);

        // back() is the signed GET link the invitee opened — it now shows the password step.
        return back();
    }

    private function sessionKey(User $user): string
    {
        return 'account_setup_username_ok.' . $user->id;
    }
}
