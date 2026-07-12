<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDemoGrant;
use App\Models\DevSetting;
use App\Models\User;
use App\Support\Instance;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DemoLoginController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'branch_manager', 'agent', 'viewer'];

    public function login(Request $request, string $role): RedirectResponse
    {
        $this->assertDemoModeAvailable();

        // AT-230 — THIS IS THE DOOR PROSPECTS WALK THROUGH.
        //
        // These role logins take NO password: hitting this route signs you in as a
        // real demo user. On a demo instance it must therefore require a live demo
        // grant, or the whole gate is decoration — a prospect could skip /demo/gate
        // and POST straight here.
        //
        // The grant cookie is set by DemoGateController only after primary has
        // verified an emailed credential, so its presence is the proof. The
        // EnsureDemoGrant middleware has already validated it against primary for
        // any non-exempt request; this route is exempt (it lives in the `guest`
        // auth group), so it checks for itself.
        if (Instance::isDemo() && ! $request->cookie(EnsureDemoGrant::COOKIE)) {
            return redirect()->route('demo.gate')
                ->with('demo_gate_message', 'Sign in with your invitation to explore the demo.');
        }

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new NotFoundHttpException();
        }

        $user = User::where('role', $role)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$user) {
            return redirect()->route('login')
                ->withErrors(['demo' => "No active demo user found with role '{$role}'. Run the demo seeder."]);
        }

        Auth::guard('web')->login($user);
        session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Is the demo-mode surface (role buttons + owner login) available here?
     *
     * AT-230: gates on Instance::isDemo(), NOT on APP_ENV.
     *
     * This used to require !app()->environment('production') — but the demo host
     * (demo1.corexos.co.za) RUNS APP_ENV=production. So the check was false on the
     * very box it exists to describe, which silently 404'd the System Owner login
     * on the demo and made every demo-mode surface unreachable there.
     *
     * COREX_INSTANCE_ROLE is the real predicate. The non-production clause is kept
     * so local/staging dev boxes still get demo mode without setting the role.
     */
    public static function isEnabled(): bool
    {
        return (Instance::isDemo() || !app()->environment('production'))
            && DevSetting::bool('demo_mode_enabled');
    }

    private function assertDemoModeAvailable(): void
    {
        if (!self::isEnabled()) {
            throw new NotFoundHttpException('Demo mode is not enabled.');
        }
    }
}
