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

        // AT-268 — belt-and-braces over the unusable invite password: refuse a session for a genuine
        // unredeemed invite, i.e. an account that (a) has never accepted (email_verified_at IS NULL)
        // AND (b) still carries the publicly-known 'INVITE_PENDING' constant as its password. The real
        // fix (User::pendingInvitePassword) already makes Auth::attempt fail for post-fix invites, and
        // the rotation migration scrubbed the constant from live — so this gate defends purely against
        // the exact hole reappearing.
        //
        // The bare email_verified_at IS NULL test was too broad: CoreX never enforced MustVerifyEmail,
        // so established accounts created outside the invite flow (e.g. the super_admin owner accounts)
        // legitimately carry a NULL marker with a real password, and were wrongly locked out. Requiring
        // the constant means the gate can only ever fire on the genuine vulnerability it closes.
        // authenticate() has already succeeded, so auth()->user()->password is the account's real hash.
        if (auth()->user()?->isPendingInvite()
            && \Illuminate\Support\Facades\Hash::check('INVITE_PENDING', (string) auth()->user()->password)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'Please accept your invitation first — use the setup link in your invite email.']);
        }

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
