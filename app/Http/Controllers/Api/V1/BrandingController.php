<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandingController extends Controller
{
    private const DEFAULTS = [
        'sidebar_color' => '#0ea5e9',
        'icon_color'    => '#0ea5e9',
        'default_color' => '#0b2a4a',
        'button_color'  => '#0ea5e9',
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $agencyId = $user->effectiveAgencyId();
        $agency = $agencyId
            ? Agency::withoutGlobalScope(AgencyScope::class)->find($agencyId)
            : null;

        return response()->json($this->payload($agency));
    }

    public function showBySlug(string $slug): JsonResponse
    {
        $agency = Agency::withoutGlobalScope(AgencyScope::class)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $agency) {
            return response()->json(['error' => 'agency_not_found'], 404);
        }

        return response()->json($this->payload($agency));
    }

    private function payload(?Agency $agency): array
    {
        $colors = [
            'sidebar' => $agency->sidebar_color ?? self::DEFAULTS['sidebar_color'],
            'icon'    => $agency->icon_color    ?? self::DEFAULTS['icon_color'],
            'default' => $agency->default_color ?? self::DEFAULTS['default_color'],
            'button'  => $agency->button_color  ?? self::DEFAULTS['button_color'],
        ];

        return [
            'agency' => $agency ? [
                'id'   => $agency->id,
                'slug' => $agency->slug,
                'name' => $agency->name,
                'logo_url' => $agency->logo_path
                    ? url('storage/' . ltrim($agency->logo_path, '/'))
                    : null,
            ] : null,
            'colors' => $colors,
            'roles'  => [
                'sidebar' => [
                    'hex'         => $colors['sidebar'],
                    'description' => 'Sidebar hover and active highlight only. The sidebar background itself is theme-controlled, not branded.',
                ],
                'icon' => [
                    'hex'         => $colors['icon'],
                    'description' => 'Icons, links, accents, focus rings, small visual highlights.',
                ],
                'default' => [
                    'hex'         => $colors['default'],
                    'description' => 'Profile headers, page headers, general agency branding surfaces.',
                ],
                'button' => [
                    'hex'         => $colors['button'],
                    'description' => 'Primary buttons, CTAs, submit actions.',
                ],
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }
}
