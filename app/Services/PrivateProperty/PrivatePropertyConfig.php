<?php

namespace App\Services\PrivateProperty;

use App\Models\Agency;
use App\Models\Property;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves PP credentials with this precedence:
 *   1. Per-agency DB columns (agencies.pp_*)
 *   2. config('services.private_property.*') from .env (legacy global default)
 *
 * All PP code paths read through this resolver — never via config() directly.
 */
class PrivatePropertyConfig
{
    /**
     * @return array{username:?string,password:?string,branch_guid:?string,wsdl:?string,sandbox:bool,image_base_url:?string,webhook_secret:?string,enabled:bool,source_agency_id:?int}
     */
    public static function for(?Agency $agency): array
    {
        $env = [
            'username'       => config('services.private_property.username'),
            'password'       => config('services.private_property.password'),
            'branch_guid'    => config('services.private_property.branch_guid'),
            'wsdl'           => config('services.private_property.wsdl'),
            'sandbox'        => (bool) config('services.private_property.sandbox', true),
            'image_base_url' => config('services.private_property.image_base_url') ?: null,
            'webhook_secret' => config('services.private_property.webhook_secret'),
            'enabled'        => true,
            'source_agency_id' => null,
        ];

        if (! $agency) {
            // CLI / queue context: no auth user and no explicit agency.
            // Auto-pick the first enabled PP agency so scheduled jobs
            // (event feed, activations sync) and artisan commands have a
            // valid BranchId. Falls through to env if no agency configured.
            $agency = Agency::query()
                ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->where('pp_enabled', true)
                ->whereNotNull('pp_branch_guid')
                ->orderBy('id')
                ->first();
            if (! $agency) {
                return $env;
            }
        }

        $pick = function (string $col, $fallback) use ($agency) {
            $val = $agency->{$col} ?? null;
            if ($val === null || $val === '') {
                return $fallback;
            }
            return $val;
        };

        return [
            'username'         => $pick('pp_username', $env['username']),
            'password'         => $pick('pp_password', $env['password']),
            'branch_guid'      => $pick('pp_branch_guid', $env['branch_guid']),
            'wsdl'             => $pick('pp_wsdl', $env['wsdl']),
            'sandbox'          => $agency->pp_username !== null
                ? (bool) $agency->pp_sandbox
                : $env['sandbox'],
            'image_base_url'   => $pick('pp_image_base_url', $env['image_base_url']),
            'webhook_secret'   => $pick('pp_webhook_secret', $env['webhook_secret']),
            'enabled'          => (bool) ($agency->pp_enabled ?? false) || empty($agency->pp_username),
            'source_agency_id' => $agency->id,
        ];
    }

    public static function forProperty(?Property $property): array
    {
        return self::for($property?->agency);
    }

    public static function forCurrentAgency(): array
    {
        $user = Auth::user();
        return self::for($user?->agency);
    }

    /**
     * Resolve the agency that owns a given PP branch GUID. Used by webhook
     * verification where the only signal is the BranchId in the payload.
     */
    public static function agencyForBranchGuid(?string $guid): ?Agency
    {
        if (! $guid) {
            return null;
        }
        return Agency::query()->where('pp_branch_guid', $guid)->first();
    }
}
