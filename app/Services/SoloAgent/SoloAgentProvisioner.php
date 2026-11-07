<?php

declare(strict_types=1);

namespace App\Services\SoloAgent;

use App\Models\Agency;
use App\Models\User;
use App\Services\Agencies\AgencyProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates a Single Agent account: a one-person agency of kind 'solo'.
 * Spec: .ai/specs/solo-agent-accounts.md §4.2 (build step 2b).
 *
 * Reuses AgencyProvisioningService — the same agency + feature profile + first
 * branch + first Admin creation Admin\AgencyController@store uses — and only adds
 * what is different about a solo agent:
 *   - account_kind='solo' is set in the creating insert (never afterwards),
 *   - the agent's login starts INACTIVE and un-invited: they cannot sign in until the
 *     signed agreement activates the account (later step), and the password-setup
 *     email is sent at that point, not now,
 *   - one branch (named after the agency) — a solo agent never manages branches.
 *
 * Later steps extend this class with the sign-up record, the flat fee and the
 * 5-a-month AI allowance (spec build steps 3 and 4).
 */
class SoloAgentProvisioner
{
    public function __construct(private readonly AgencyProvisioningService $provisioning)
    {
    }

    /**
     * @param  array{agency_name:string,name:string,email:string,cell?:?string,agency_type?:?string,trading_name?:?string}  $input
     * @return array{agency: Agency, user: User}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function provision(array $input, ?int $createdByUserId = null): array
    {
        $data = Validator::make($input, [
            'agency_name' => ['required', 'string', 'max:100'],
            'name'        => ['required', 'string', 'max:191'],
            'email'       => ['required', 'email', 'max:191', 'unique:users,email'],
            'cell'        => ['nullable', 'string', 'max:50'],
            'agency_type' => ['nullable', 'in:sales,rentals,both'],
            'trading_name' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $created = DB::transaction(function () use ($data) {
            return $this->provisioning->createWithFirstAdmin(
                [
                    'name'          => $data['agency_name'],
                    'slug'          => $this->uniqueSlug($data['agency_name']),
                    'trading_name'  => $data['trading_name'] ?? null,
                    'agency_type'   => $data['agency_type'] ?? null,   // blank = both (decision 3)
                    'email'         => $data['email'],
                    'sidebar_color' => '#0ea5e9',
                    'icon_color'    => '#0ea5e9',
                    'default_color' => '#0b2a4a',
                    'button_color'  => '#0ea5e9',
                    'is_active'     => true,
                    'is_demo'       => false,
                ],
                ['name' => $data['agency_name'], 'code' => 'MAIN'],
                ['name' => $data['name'], 'email' => $data['email'], 'cell' => $data['cell'] ?? null],
                [
                    'account_kind'  => Agency::ACCOUNT_KIND_SOLO,
                    'admin_active'  => false,
                    'admin_invited' => false,
                ],
            );
        });

        $agency = $created['agency'];
        $user   = $created['admin'];

        // Same post-commit steps a normal agency gets (domain event NN #9, per-agency defaults).
        $this->provisioning->announceCreated($agency, $user, $createdByUserId);
        $this->provisioning->seedDefaults($agency, $user);

        return ['agency' => $agency, 'user' => $user];
    }

    /** A slug no agency (archived or not) already uses. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'agent';
        $slug = $base;
        $i = 2;
        while (DB::table('agencies')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
