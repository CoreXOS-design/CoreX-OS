<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\RmcpVariable;
use App\Models\Compliance\RmcpVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RmcpVariableResolver
{
    /** A blank line to write on: shown where a fact has not been recorded yet (AT-506). */
    public const BLANK_LINE = '______________';

    /**
     * Keys this resolver COMPUTES from CoreX data (AT-506). A manual rmcp_variables row with the same key
     * still wins over the computed value, exactly like every other key — that is how an agency states a
     * figure CoreX cannot know (e.g. leases it manages in another system). The variables screen lists
     * these as "Computed" and lets an admin add an override row for any of them.
     */
    public const COMPUTED_KEYS = [
        'agency.legal_name',
        'agency.area_of_operation',
        'agency.subsidiaries_statement',
        'agency.branches_statement',
        'agency.branches_and_responsible',
        'profile.avg_sales_per_year',
        'profile.active_leases',
        'profile.avg_monthly_rent',
        'fic.directive12_submitted_on',
        'today.date',
        'today.year',
        'rmcp.version_number',
        'rmcp.approved_on',
        'rmcp.approved_by_name',
        'rmcp.effective_from',
        'rmcp.next_review_due',
    ];

    /**
     * Resolve all variables for an agency, merging:
     * 1. Agency table columns
     * 2. Current compliance officer
     * 3. Computed values (dates, offices, business profile)
     * 4. Manual rmcp_variables overrides (highest priority)
     */
    public function resolve(Agency $agency, ?RmcpVersion $version = null): array
    {
        $variables = [];

        // Agency fields
        $variables['agency.name']         = $agency->name ?? '';
        $variables['agency.legal_name']   = $agency->name ?? '';   // a manual row (the legal entity's full name) wins below
        $variables['agency.trading_name'] = $agency->trading_name ?? $agency->name ?? '';
        $variables['agency.reg_no']       = $agency->reg_no ?? '';
        $variables['agency.vat_no']       = $agency->vat_no ?? '';
        $variables['agency.ffc_no']       = $agency->ffc_no ?? '';
        $variables['agency.ppra_number']  = $agency->ppra_number ?? '';
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
            $variables['rmcp.version_number']   = (string) $version->version_number;
            $variables['rmcp.approved_on']      = $version->approved_at ? $version->approved_at->format('d F Y') : '';
            $variables['rmcp.approved_by_name'] = $version->approved_at ? (string) ($version->approver?->name ?? '') : '';
            $variables['rmcp.effective_from']   = $version->effective_from ? $version->effective_from->format('d F Y') : '';
            $variables['rmcp.next_review_due']  = $version->next_review_due ? $version->next_review_due->format('d F Y') : '';
        }

        // The FIC submission date (Directive 12). Nothing records it yet (the FIC tracker, AT-505, will),
        // so until a value exists the approval block shows a blank line to write on.
        $variables['fic.directive12_submitted_on'] = self::BLANK_LINE;

        // Facts only the agency knows — held as a manual value per agency, blank until stated.
        $variables['agency.area_of_operation']      = '';
        $variables['agency.subsidiaries_statement'] = '';

        // Offices, from the agency's own branches that have a physical address.
        $offices = $this->offices($agency);
        $variables['agency.branches_and_responsible'] = $this->branchesAndResponsible($offices);

        // Business profile — computed from CoreX at render time.
        $variables['profile.avg_sales_per_year'] = $this->salesProfile($agency);
        $leases = $this->leaseProfile($agency);
        $variables['profile.active_leases']      = $leases['count'];
        $variables['profile.avg_monthly_rent']   = $leases['average'];

        // Computed
        $variables['today.date'] = now()->format('d F Y');
        $variables['today.year'] = now()->format('Y');

        // Manual overrides from rmcp_variables table (highest priority). Read first so the offices
        // sentence can carry the agency's own subsidiaries statement.
        $manuals = RmcpVariable::forAgency($agency->id);
        $subsidiaries = trim((string) ($manuals['agency.subsidiaries_statement'] ?? ''));
        $variables['agency.branches_statement'] = $this->branchesStatement($agency, $offices, $subsidiaries);

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

    // ── Offices ──────────────────────────────────────────────────────────

    /**
     * The agency's offices: its live branches that carry a physical address, in website order.
     *
     * @return list<array{id:int,name:string,address:string}>
     */
    private function offices(Agency $agency): array
    {
        return Branch::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->whereNotNull('address')
            ->where('address', '<>', '')
            ->orderByRaw('website_order IS NULL, website_order')
            ->orderBy('id')
            ->get(['id', 'name', 'address'])
            ->map(fn (Branch $b) => ['id' => (int) $b->id, 'name' => trim((string) $b->name), 'address' => trim((string) $b->address)])
            ->all();
    }

    /**
     * "one office, Shelly Beach, to which all agents are attached; <subsidiaries sentence>".
     * Built from the branches with a physical address; an agency with none yet falls back to its own address.
     *
     * @param list<array{id:int,name:string,address:string}> $offices
     */
    private function branchesStatement(Agency $agency, array $offices, string $subsidiaries): string
    {
        $count = count($offices);

        if ($count === 0) {
            $address = trim((string) ($agency->address ?? ''));
            $statement = $address !== '' ? 'one office, ' . $address . ', to which all agents are attached' : '';
        } elseif ($count === 1) {
            $statement = 'one office, ' . $offices[0]['name'] . ', to which all agents are attached';
        } else {
            $names = array_map(fn (array $o) => $o['name'], $offices);
            $last = array_pop($names);
            $statement = $this->numberWord($count) . ' offices, ' . implode(', ', $names) . ' and ' . $last . ', each with its own agents attached';
        }

        if ($subsidiaries !== '') {
            $statement .= ($statement !== '' ? '; ' : '') . $subsidiaries;
        }

        return $statement;
    }

    /**
     * "Shelly Beach: Jane Smith; Ballito: ______________" — each office with its branch manager(s), a blank
     * line to write on where no branch manager is attached.
     *
     * @param list<array{id:int,name:string,address:string}> $offices
     */
    private function branchesAndResponsible(array $offices): string
    {
        if ($offices === []) {
            return '';
        }

        $managers = Branch::attachedUsersGrouped(array_column($offices, 'id'));

        $parts = [];
        foreach ($offices as $office) {
            $names = ($managers[$office['id']] ?? collect())
                ->filter(fn (User $u) => $u->is_active && ! $u->is_assistant && $u->role === 'branch_manager')
                ->pluck('name')->map(fn ($n) => trim((string) $n))->filter()->values()->all();
            $parts[] = $office['name'] . ': ' . ($names !== [] ? implode(' and ', $names) : self::BLANK_LINE);
        }

        return implode('; ', $parts);
    }

    private function numberWord(int $n): string
    {
        $words = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'];

        return $words[$n] ?? (string) $n;
    }

    // ── Business profile ─────────────────────────────────────────────────

    /**
     * Registered sales in the last 12 months: DR2 deals (deals_v2) with an actual registration date in the
     * window, ignoring cancelled and declined deals. Shown as a sentence, e.g. "35 registrations in the last 12 months".
     */
    private function salesProfile(Agency $agency): string
    {
        $count = DB::table('deals_v2')
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->whereNotNull('actual_registration')
            ->whereBetween('actual_registration', [now()->subYear()->toDateString(), now()->toDateString()])
            ->count();

        return $count . ($count === 1 ? ' registration' : ' registrations') . ' in the last 12 months';
    }

    /**
     * Active leases and the average monthly rent across them.
     *
     * @return array{count:string,average:string}
     */
    private function leaseProfile(Agency $agency): array
    {
        $row = DB::table('leases')
            ->where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->selectRaw('COUNT(*) AS n, AVG(rental_amount) AS avg_rent')
            ->first();

        $n = (int) ($row->n ?? 0);

        return [
            'count'   => (string) $n,
            'average' => $n > 0 ? 'R ' . number_format((float) $row->avg_rent, 0, '.', ',') : 'n/a',
        ];
    }
}
