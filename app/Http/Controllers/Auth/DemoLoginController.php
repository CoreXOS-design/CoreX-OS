<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DevSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DemoLoginController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'branch_manager', 'agent', 'viewer'];

    public function login(string $role): RedirectResponse
    {
        $this->assertDemoModeAvailable();

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

    public static function isEnabled(): bool
    {
        return !app()->environment('production')
            && DevSetting::bool('demo_mode_enabled');
    }

    private function assertDemoModeAvailable(): void
    {
        if (!self::isEnabled()) {
            throw new NotFoundHttpException('Demo mode is not enabled.');
        }
    }
}
