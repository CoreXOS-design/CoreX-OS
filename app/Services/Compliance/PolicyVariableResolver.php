<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\PolicyVersion;
use App\Models\User;

/**
 * Resolves {{variable}} values for policy section mail-merge (AT-29).
 *
 * Table-free by design: the policy_variables table is deferred (spec §3),
 * so values come from the Agency, the current compliance officer, the
 * policy version, computed dates, and — optionally — the signing user.
 * Mirrors RmcpVariableResolver's interface so views/editors are identical.
 */
class PolicyVariableResolver
{
    public function resolve(Agency $agency, ?PolicyVersion $version = null, ?User $user = null): array
    {
        $variables = [];

        // Agency fields
        $variables['agency.name']         = $agency->name ?? '';
        $variables['agency.trading_name'] = $agency->trading_name ?? $agency->name ?? '';
        $variables['agency.reg_no']       = $agency->reg_no ?? '';
        $variables['agency.vat_no']       = $agency->vat_no ?? '';
        $variables['agency.ffc_no']       = $agency->ffc_no ?? '';
        $variables['agency.fic_no']       = $agency->fic_no ?? '';
        $variables['agency.address']      = $agency->address ?? '';
        $variables['agency.phone']        = $agency->phone ?? '';
        $variables['agency.email']        = $agency->email ?? '';

        // Compliance / Information officer (primary CO from unified appointments table)
        $co = FicaOfficerAppointment::currentPrimary($agency->id);
        $variables['compliance_officer.full_name']    = $co->full_name ?? '';
        $variables['compliance_officer.id_number']    = $co->id_number ?? '';
        $variables['compliance_officer.cell']         = $co->cell ?? '';
        $variables['compliance_officer.email']        = $co->email ?? '';
        $variables['compliance_officer.title']        = $co->title ?? 'Compliance Officer';
        $variables['compliance_officer.appointed_on'] = $co ? $co->appointed_on->format('d F Y') : '';

        // Policy version info
        if ($version) {
            $variables['policy.version_number'] = (string) $version->version_number;
            $variables['policy.title']          = $version->title ?? '';
            $variables['policy.approved_on']    = $version->approved_at ? $version->approved_at->format('d F Y') : '';
            $variables['policy.effective_from'] = $version->effective_from ? $version->effective_from->format('d F Y') : '';
            $variables['policy.next_review_due'] = $version->next_review_due ? $version->next_review_due->format('d F Y') : '';
        }

        // Signer (only when rendering for a specific user, e.g. the declaration)
        if ($user) {
            $variables['signer.name']      = $user->name ?? '';
            $variables['signer.id_number'] = $user->id_number ?? '';
            $variables['signer.email']     = $user->email ?? '';
        }

        // Computed
        $variables['today.date'] = now()->format('d F Y');
        $variables['today.year'] = now()->format('Y');

        return $variables;
    }

    /**
     * Replace {{key}} tokens in HTML with variable values. Missing keys are
     * left as-is so authors can see what's unresolved.
     */
    public function applyToHtml(string $html, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', e($value), $html);
        }

        return $html;
    }
}
