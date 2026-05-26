<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    private const BRAND_DEFAULTS = [
        'sidebar' => '#0ea5e9',
        'icon'    => '#0ea5e9',
        'default' => '#0b2a4a',
        'button'  => '#0ea5e9',
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['authenticated' => false], 401);
        }

        $agencyId = $user->effectiveAgencyId();
        $agency = $agencyId
            ? Agency::withoutGlobalScope(AgencyScope::class)->find($agencyId)
            : null;

        $roles = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->all()
            : [];

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'roles'      => $roles,
                'theme'      => $user->theme ?? 'dark',
                'ffc_status' => $user->ffc_status ?? null,
                'branch_id'  => $user->effectiveBranchId(),
                'branch'     => $user->branch?->name,
            ],
            'agency' => $agency ? [
                'id'             => $agency->id,
                'name'           => $agency->name,
                'slug'           => $agency->slug ?? null,
                'sidebar_color'  => $agency->sidebar_color ?? null,
                'icon_color'     => $agency->icon_color ?? null,
                'default_color'  => $agency->default_color ?? null,
                'button_color'   => $agency->button_color ?? null,
                'split_branches' => (bool) ($agency->split_branches_enabled ?? false),
            ] : null,
            'branding' => $this->branding($agency),
            'permissions' => $this->collectPermissions($user),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function branding(?Agency $agency): array
    {
        $colors = [
            'sidebar' => $agency->sidebar_color ?? self::BRAND_DEFAULTS['sidebar'],
            'icon'    => $agency->icon_color    ?? self::BRAND_DEFAULTS['icon'],
            'default' => $agency->default_color ?? self::BRAND_DEFAULTS['default'],
            'button'  => $agency->button_color  ?? self::BRAND_DEFAULTS['button'],
        ];

        return [
            'logo_url' => $agency && $agency->logo_path
                ? url('storage/' . ltrim($agency->logo_path, '/'))
                : null,
            'colors' => $colors,
            'roles'  => [
                'sidebar' => ['hex' => $colors['sidebar'], 'description' => 'Sidebar/nav hover and active highlight only. The sidebar background itself is theme-controlled, not branded.'],
                'icon'    => ['hex' => $colors['icon'],    'description' => 'Icons, links, accents, focus rings, small visual highlights.'],
                'default' => ['hex' => $colors['default'], 'description' => 'Profile headers, page headers, general agency branding surfaces.'],
                'button'  => ['hex' => $colors['button'],  'description' => 'Primary buttons, CTAs, submit actions.'],
            ],
        ];
    }

    private function collectPermissions($user): array
    {
        $keys = array_keys(config('corex-permissions', []) ?? []);
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (bool) $user->hasPermission($key);
        }
        return $out;
    }
}
