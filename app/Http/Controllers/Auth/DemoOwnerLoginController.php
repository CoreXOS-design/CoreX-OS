<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DemoOwnerLoginController extends Controller
{
    public function create(): View
    {
        $this->assertDemoModeAvailable();
        return view('auth.demo-owner-login');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertDemoModeAvailable();

        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (!Auth::guard('web')->attempt($credentials, false)) {
            return back()->withErrors(['email' => 'Invalid credentials.'])->withInput($request->only('email'));
        }

        $user = Auth::guard('web')->user();

        if (!$user->is_active) {
            Auth::guard('web')->logout();
            return back()->withErrors(['email' => 'Account is inactive.']);
        }

        if (!$user->isOwnerRole()) {
            Auth::guard('web')->logout();
            return back()->withErrors(['email' => 'This login is for System Owner accounts only.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function assertDemoModeAvailable(): void
    {
        if (!DemoLoginController::isEnabled()) {
            throw new NotFoundHttpException('Demo mode is not enabled.');
        }
    }
}
