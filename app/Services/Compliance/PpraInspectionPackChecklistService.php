<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use App\Models\Compliance\PpraInspectionGapNote;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack — Phase A. .ai/specs/ppra-inspection-pack.md §5.
 *
 * Computes the live a-m checklist for an agency. Phase A wires items
 * a, b, d, e, h (all agency-vault-backed). Items c/f/g/i/j/k/l/m are
 * returned with status 'pending' ("not yet available" — later phases)
 * so the checklist page and Inspection Report can render a stable 13-row
 * shape from day one without guessing at data later phases will add.
 */
class PpraInspectionPackChecklistService
{
    /**
     * Vault-backed items wired in Phase A: item key => [slug(s), label, why-line prefix].
     * 'h' carries two slugs (the satisfies_group alternatives).
     */
    private const VAULT_ITEMS = [
        'a' => ['slugs' => ['cipc_registration'], 'label' => 'CIPC Documents', 'requirement' => 'Company registration documents from the CIPC.'],
        'b' => ['slugs' => ['ffc_certificate'],    'label' => 'Company FFC',    'requirement' => "The agency's own Fidelity Fund Certificate."],
        'd' => ['slugs' => ['bank_confirmation'],  'label' => 'Trust Account Bank Confirmation Letter', 'requirement' => "A bank confirmation letter for the agency's trust account (Property Practitioners Act s54(1))."],
        'e' => ['slugs' => ['trial_balance'],      'label' => 'Trial Balance',  'requirement' => "The agency's latest trial balance / control accounts, from its accountant."],
        'h' => ['slugs' => ['bee_certificate', 'bee_affidavit'], 'label' => 'BEE Certificate or Sworn Affidavit', 'requirement' => 'A valid BEE certificate, or a sworn affidavit where none exists.'],
    ];

    private const PENDING_ITEMS = [
        'c' => 'Principal\'s FFCs',
        'f' => 'Practitioner List & FFC Numbers',
        'g' => 'Letterhead',
        'i' => 'Transformation Initiatives',
        'j' => 'Sales/Rentals List — Current Financial Year',
        'k' => 'Sales Files Sampled',
        'l' => 'Rental Files Sampled',
        'm' => 'Mandates + MDFs, Active Listings',
    ];

    /**
     * @return Collection<int, object> one row per item a-m, in order.
     */
    public function checklistFor(Agency $agency): Collection
    {
        $rows = collect();

        foreach (self::VAULT_ITEMS as $key => $def) {
            $rows->push($this->vaultRow($agency, $key, $def));
        }

        foreach (self::PENDING_ITEMS as $key => $label) {
            $rows->push((object) [
                'item'        => $key,
                'label'       => $label,
                'requirement' => null,
                'status'      => 'pending',
                'why'         => 'Not yet available — a later build phase.',
                'evidence'    => null,
                'gap_note'    => null,
            ]);
        }

        return $rows->sortBy('item')->values();
    }

    private function vaultRow(Agency $agency, string $item, array $def): object
    {
        $resolved = $this->bestProvisionFor($agency, $def['slugs']);

        $status = $resolved['status'];
        $why = $resolved['why'];

        $gapNote = null;
        if (in_array($status, ['amber', 'red'], true)) {
            $gapNote = PpraInspectionGapNote::currentFor($agency->id, $item);
        } else {
            // Item is clean now — auto-resolve any stray open note (§9 edge case).
            PpraInspectionGapNote::where('agency_id', $agency->id)
                ->forItem($item)
                ->open()
                ->update(['resolved_at' => now()]);
        }

        return (object) [
            'item'        => $item,
            'label'       => $def['label'],
            'requirement' => $def['requirement'],
            'status'      => $status,
            'why'         => $why,
            'evidence'    => $resolved['evidence'],
            'gap_note'    => $gapNote,
        ];
    }

    /**
     * Resolve the best (highest-status) provision across one or more
     * alternative document-type slugs for an agency — used as-is for
     * single-slug items (a/b/d/e) and for item h's satisfies_group pair.
     */
    private function bestProvisionFor(Agency $agency, array $slugs): array
    {
        $best = null; // ['status' => ..., 'why' => ..., 'evidence' => ...]
        $rank = ['red' => 0, 'amber' => 1, 'green' => 2];

        $configs = AgencyDocumentTypeConfig::active()
            ->where('agency_id', $agency->id)
            ->whereIn('slug', $slugs)
            ->get();

        if ($configs->isEmpty()) {
            return [
                'status'   => 'red',
                'why'      => 'Compliance document type not configured yet — set it up in Settings.',
                'evidence' => null,
            ];
        }

        foreach ($configs as $config) {
            $provision = AgencyComplianceProvision::resolveForUser($config->id, null);
            $candidate = $this->statusForProvision($config, $provision);

            if ($best === null || $rank[$candidate['status']] > $rank[$best['status']]) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function statusForProvision(AgencyDocumentTypeConfig $config, ?AgencyComplianceProvision $provision): array
    {
        if (! $provision) {
            // Missing is red regardless of the vault's own `required` toggle —
            // that flag gates the unrelated marketing-readiness/property gate;
            // every item on this PPRA checklist is a real s25 requirement.
            return [
                'status'   => 'red',
                'why'      => "No {$config->name} on file.",
                'evidence' => null,
            ];
        }

        $evidence = $provision->document_original_name ?: 'document on file';

        if (! $config->has_expiry || ! $provision->effective_until) {
            return [
                'status'   => 'green',
                'why'      => "{$config->name} on file, uploaded {$provision->created_at->format('d M Y')}.",
                'evidence' => $evidence,
            ];
        }

        $daysLeft = (int) now()->diffInDays($provision->effective_until, false);
        $renewalWindow = $config->renewal_days ?: 30;

        if ($daysLeft < 0) {
            return [
                'status'   => 'red',
                'why'      => "{$config->name} expired " . $provision->effective_until->format('d M Y') . ' — renew now.',
                'evidence' => $evidence,
            ];
        }

        if ($daysLeft <= $renewalWindow) {
            return [
                'status'   => 'amber',
                'why'      => "{$config->name} expires " . $provision->effective_until->format('d M Y') . " ({$daysLeft} days).",
                'evidence' => $evidence,
            ];
        }

        return [
            'status'   => 'green',
            'why'      => "{$config->name} on file, valid until " . $provision->effective_until->format('d M Y') . '.',
            'evidence' => $evidence,
        ];
    }
}
