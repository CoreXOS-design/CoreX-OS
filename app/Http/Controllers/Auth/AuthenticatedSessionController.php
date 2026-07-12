<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Middleware\EnsureDemoGrant;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Instance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * AT-230 — THE PERSONA BUTTONS LIVE BEHIND THE GATE.
     *
     * `auth.demo-login` is nothing but four passwordless "Sign in as …" buttons. On
     * a demo instance it must therefore never render to a visitor who has not been
     * through /demo/gate: `login` is on EnsureDemoGrant::EXEMPT (the staff door), so
     * this page is one of the few an uninvited prospect can actually reach, and
     * showing them "Pick a role. No password required." before they have redeemed an
     * invitation advertises the whole demo to anyone who guesses the URL.
     *
     * It was never an auth BYPASS — DemoLoginController::login() re-checks the grant
     * cookie and bounces the POST to the gate — but it disclosed the demo surface,
     * and it read like a way in. The cookie-presence check here mirrors that
     * controller's own guard exactly; presence is the right test because the cookie
     * is signed, and EnsureDemoGrant re-verifies the token against primary on every
     * non-exempt request anyway.
     *
     * Ungated on a demo box we fall back to the ordinary password form rather than
     * redirecting to the gate: `login` is exempt precisely so a password-holding
     * human can always get in, and a redirect would take that door away.
     */
    public function create(Request $request): View
    {
        if (DemoLoginController::isEnabled()) {
            $gated = Instance::isDemo() && ! $request->cookie(EnsureDemoGrant::COOKIE);

            return $gated ? view('auth.login') : view('auth.demo-login');
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        if (!auth()->user()?->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'Your account is inactive. Please contact the administrator.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
