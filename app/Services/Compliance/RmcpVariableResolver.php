<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\RmcpVariable;
use App\Models\Compliance\RmcpVersion;

class RmcpVariableResolver
{
    /**
     * Resolve all variables for an agency, merging:
     * 1. Agency table columns
     * 2. Current compliance officer
     * 3. Manual rmcp_variables overrides
     * 4. Computed values (dates)
     */
    public function resolve(Agency $agency, ?RmcpVersion $version = null): array
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

        // Compliance officer (primary CO from unified appointments table)
        $co = FicaOfficerAppointment::currentPrimary($agency->id);
        $variables['compliance_officer.full_name']    = $co->full_name ?? '';
        $variables['compliance_officer.id_number']    = $co->id_number ?? '';
        $variables['compliance_officer.cell']         = $co->cell ?? '';
        $variables['compliance_officer.email']        = $co->email ?? '';
        $variables['compliance_officer.title']        = $co->title ?? 'FICA Compliance Officer';
        $variables['compliance_officer.appointed_on'] = $co ? $co->appointed_on->format('d F Y') : '';

        // RMCP version info
        if ($version) {
            $variables['rmcp.version_number'] = (string) $version->version_number;
            $variables['rmcp.approved_on']    = $version->approved_at ? $version->approved_at->format('d F Y') : '';
            $variables['rmcp.effective_from'] = $version->effective_from ? $version->effective_from->format('d F Y') : '';
            $variables['rmcp.next_review_due'] = $version->next_review_due ? $version->next_review_due->format('d F Y') : '';
        }

        // Computed
        $variables['today.date'] = now()->format('d F Y');
        $variables['today.year'] = now()->format('Y');

        // Manual overrides from rmcp_variables table (highest priority)
        $manuals = RmcpVariable::forAgency($agency->id);
        foreach ($manuals as $key => $value) {
            if ($value !== null && $value !== '') {
                $variables[$key] = $value;
            }
        }

        return $variables;
    }

    /**
     * Replace {{key}} tokens in HTML with variable values.
     * Missing keys are left as-is so authors can see what's broken.
     */
    public function applyToHtml(string $html, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', e($value), $html);
        }

        return $html;
    }
}
