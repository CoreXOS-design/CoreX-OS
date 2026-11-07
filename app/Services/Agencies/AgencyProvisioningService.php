<?php

declare(strict_types=1);

namespace App\Services\Agencies;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The one place an agency, its first branch and its first Admin are created.
 *
 * Extracted from Admin\AgencyController@store so the Solo Agent provisioner
 * (.ai/specs/solo-agent-accounts.md §4.2) reuses it instead of copying it
 * (agency-onboarding-setup.md §3.1 applies equally). The controller keeps
 * everything that is about ITS request (validation, invite mail, redirect);
 * this class keeps everything that is about the DATA.
 *
 * Behaviour for a normal agency is byte-for-byte what the controller did before:
 * same order, same transaction boundary, same defaults.
 */
class AgencyProvisioningService
{
    /**
     * Atomic: agency + feature profile + first branch (+ first Admin).
     *
     * @param  array       $agencyData  attributes for Agency::fill (mass-assignable only)
     * @param  array       $branch      ['name' => ..., 'code' => ...]
     * @param  array|null  $admin       ['name','email','cell'] — null for a demo agency (no Admin)
     * @param  array       $options     account_kind  Agency::ACCOUNT_KIND_* (default 'agency')
     *                                  admin_active  bool, default true
     *                                  admin_invited bool, default true (stamps invited_at)
     * @return array{agency: Agency, branch: Branch, admin: ?User}
     */
    public function createWithFirstAdmin(array $agencyData, array $branch, ?array $admin, array $options = []): array
    {
        $kind         = $options['account_kind'] ?? Agency::ACCOUNT_KIND_AGENCY;
        $adminActive  = (bool) ($options['admin_active'] ?? true);
        $adminInvited = (bool) ($options['admin_invited'] ?? true);

        return DB::transaction(function () use ($agencyData, $branch, $admin, $kind, $adminActive, $adminInvited) {
            $agencyData['agency_type'] = \App\Services\Features\AgencyTypeFeatureSeeder::normalise($agencyData['agency_type'] ?? null);

            // account_kind is deliberately NOT mass-assignable (a form can never make an
            // agency solo). It is set HERE, in the creating insert, so the Agency::created
            // hooks (role provisioning) and the feature seeding below already see the
            // right kind — setting it afterwards leaves the request-level feature cache
            // holding the wrong answers.
            $agency = new Agency();
            $agency->fill($agencyData);
            $agency->forceFill(['account_kind' => $kind]);
            $agency->save();

            // The feature switches start from what the agency does — not from the platform-wide defaults,
            // which carry Home Finders Coastal's own choices (syndication, marketing).
            app(\App\Services\Features\AgencyTypeFeatureSeeder::class)->seedNewAgency($agency);

            // AT-378 — every agency's first branch, created in the same transaction so an agency
            // can never exist without one. withoutAgencyStamping: a creating owner with an active
            // Agency Switcher session would otherwise have Branch's BelongsToAgency creating() hook
            // force this row onto THEIR switched-into agency.
            $branchRow = Branch::withoutAgencyStamping(fn () => Branch::create([
                'name'      => $branch['name'],
                'code'      => $branch['code'],
                'agency_id' => $agency->id,
            ]));

            // Every branch-context fallback that reads agencies.default_branch_id needs this.
            $agency->update(['default_branch_id' => $branchRow->id]);

            $adminUser = null;
            if ($admin) {
                // Email-only invite (agency-admin-rule.md §R1a): unusable placeholder password;
                // email_verified_at stays null until the person sets a real one via account.setup.
                $adminUser = User::withoutAgencyStamping(fn () => User::create([
                    'name'       => $admin['name'],
                    'email'      => $admin['email'],
                    'password'   => Hash::make(User::pendingInvitePassword()),
                    'cell'       => $admin['cell'] ?? null,
                    'role'       => 'admin',
                    'agency_id'  => $agency->id,
                    'branch_id'  => $branchRow->id,
                    'is_active'  => $adminActive,
                    'invited_at' => $adminInvited ? now() : null,
                ]));
            }

            return ['agency' => $agency, 'branch' => $branchRow, 'admin' => $adminUser];
        });
    }

    /**
     * Fire the AgencyCreated domain event (NN #9) — live agencies with an Admin only.
     * Call AFTER the create transaction has committed.
     */
    public function announceCreated(Agency $agency, ?User $admin, ?int $createdByUserId): void
    {
        if (! $admin) {
            return;
        }

        event(new \App\Events\AgencyCreated(
            agency: $agency,
            adminUser: $admin,
            adminEmail: $admin->email,
            createdByUserId: $createdByUserId,
        ));
    }

    /**
     * The per-agency defaults every new agency starts with, isolated from every other agency.
     */
    public function seedDefaults(Agency $agency, ?User $admin): void
    {
        // Marketing-compliance required document types (mandate, FICA, disclosure).
        app(\App\Services\Compliance\AgencyComplianceDocTypeService::class)->ensureDefaults($agency->id);

        // This agency's own daily-activity points definitions, cloned from the shared template.
        app(\App\Services\ActivityDefinitions\ActivityDefinitionDefaultsService::class)->ensureDefaults($agency->id);

        // A starter WhatsApp outreach template so Seller Outreach is never empty.
        app(\App\Services\SellerOutreach\SellerOutreachTemplateDefaultsService::class)
            ->ensureDefaults($agency->id, $admin?->id);
    }
}
