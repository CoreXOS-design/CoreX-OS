<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use App\Models\Compliance\PpraInspectionGapNote;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack — Phase A + B + C. .ai/specs/ppra-inspection-pack.md §5.
 *
 * Computes the live a-m checklist for an agency. Wired so far:
 * a, b, d, e, h (agency-vault-backed, Phase A), c, f, g (practitioner
 * FFC roster + letterhead, Phase B/C — v3 sources c/f from
 * PractitionerFfcRosterService, role-filtered + UserDocument-backed, per
 * Johan's 2026-09-28 ruling). Items i/j/k/l/m are returned with status
 * 'pending' ("not yet available" — later phases) so the checklist page and
 * Inspection Report keep a stable 13-row shape from day one without
 * guessing at data later phases will add.
 */
class PpraInspectionPackChecklistService
{
    public function __construct(
        private PractitionerFfcRosterService $practitionerRoster = new PractitionerFfcRosterService(),
    ) {
    }

    /**
     * Vault-backed items: item key => [slug(s), label, why-line prefix].
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
        'i' => 'Transformation Initiatives',
        'j' => 'Sales/Rentals List — Current Financial Year',
        'k' => 'Sales Files Sampled',
        'l' => 'Rental Files Sampled',
        'm' => 'Mandates + MDFs, Active Listings',
    ];

    /**
     * Which checklist item a given agency_document_type_configs slug feeds
     * (2026-09-28, Johan — the agency-documents page links back to its
     * matching Inspection Pack row). Single source of truth: this reads
     * the same VAULT_ITEMS map the checklist itself is built from, so the
     * two can never drift apart.
     */
    public static function itemForSlug(string $slug): ?string
    {
        foreach (self::VAULT_ITEMS as $item => $def) {
            if (in_array($slug, $def['slugs'], true)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, object> one row per item a-m, in order.
     */
    public function checklistFor(Agency $agency): Collection
    {
        $rows = collect();

        foreach (self::VAULT_ITEMS as $key => $def) {
            $rows->push($this->vaultRow($agency, $key, $def));
        }

        $fullRoster = $this->practitionerRoster->rosterFor($agency->id);
        $principals = $this->practitionerRoster->principalsFor($agency->id);
        $rows->push($this->practitionerFfcRow($agency, 'c', 'Principal\'s FFCs', $principals, false, true));
        $rows->push($this->practitionerFfcRow($agency, 'f', 'Practitioner List & FFC Numbers', $fullRoster, true));
        $rows->push($this->letterheadRow($agency));

        foreach (self::PENDING_ITEMS as $key => $label) {
            $rows->push((object) [
                'item'        => $key,
                'label'       => $label,
                'requirement' => null,
                'status'      => 'pending',
                'why'         => 'Not yet available — a later build phase.',
                'evidence'    => null,
                'gap_note'    => null,
                'document'    => null,
                'upload_configs' => collect(),
            ]);
        }

        return $rows->sortBy('item')->values();
    }

    /**
     * Items c/f — the practitioner FFC roster (v3: role-filtered
     * agent/branch_manager/admin, UserDocument-backed — see
     * PractitionerFfcRosterService). Aggregate status = worst individual
     * practitioner status; the "why" line names the gaps (capped).
     */
    private function practitionerFfcRow(Agency $agency, string $item, string $label, Collection $roster, bool $exportNote = false, bool $principalOnly = false): object
    {
        $requirement = $exportNote
            ? 'A list of every property practitioner, their status, and FFC number.'
            : "Every principal practitioner's own Fidelity Fund Certificate.";

        $gapNote = null;
        $status = 'green';
        $why = 'No active practitioners on the roster.';

        if ($roster->isEmpty()) {
            $status = 'red';
            $why = $principalOnly
                ? 'No principal practitioner identified — check user designations.'
                : 'No active practitioners found in the agency roster.';
        } else {
            $gaps = $roster->filter(fn ($a) => in_array($a['ffc']['status'], ['red', 'amber'], true));

            if ($gaps->isEmpty()) {
                $status = 'green';
                $why = "{$roster->count()}/{$roster->count()} practitioners have a valid FFC on file.";
            } else {
                $hasRed = $gaps->contains(fn ($a) => $a['ffc']['status'] === 'red');
                $status = $hasRed ? 'red' : 'amber';
                $names = $gaps->pluck('name')->take(3)->implode(', ');
                $extra = $gaps->count() > 3 ? ' +' . ($gaps->count() - 3) . ' more' : '';
                $ok = $roster->count() - $gaps->count();
                $why = "{$ok}/{$roster->count()} practitioners have a valid FFC on file — {$names}{$extra} need attention.";
            }
        }

        if ($exportNote) {
            $why .= ' Export to PDF/CSV from the practitioner register.';
        }

        if (in_array($status, ['amber', 'red'], true)) {
            $gapNote = PpraInspectionGapNote::currentFor($agency->id, $item);
        } else {
            PpraInspectionGapNote::where('agency_id', $agency->id)->forItem($item)->open()->update(['resolved_at' => now()]);
        }

        return (object) [
            'item'        => $item,
            'label'       => $label,
            'requirement' => $requirement,
            'status'      => $status,
            'why'         => $why,
            'evidence'    => $roster->isNotEmpty() ? ($roster->count() . ' practitioners on the roster') : null,
            'gap_note'    => $gapNote,
            'document'    => null,
            'upload_configs' => collect(),
        ];
    }

    /**
     * Item g — letterhead. Green once the agency has the same data every
     * agent-branded email already reads (BaseSignatureMail::getAgentFooter()):
     * a logo, and a PPRA number resolvable at agency or branch level.
     */
    private function letterheadRow(Agency $agency): object
    {
        $item = 'g';
        $hasLogo = ! empty($agency->logo_path);
        $hasPpra = ! empty($agency->ppra_number);

        if (! $hasLogo) {
            $status = 'red';
            $why = 'Agency logo not set — letterhead cannot be generated.';
        } elseif (! $hasPpra) {
            $status = 'amber';
            $why = 'Agency logo is set, but no PPRA registration number is on file.';
        } else {
            $status = 'green';
            $why = 'Agency logo and PPRA number are on file — a letterhead can be generated.';
        }

        $gapNote = null;
        if (in_array($status, ['amber', 'red'], true)) {
            $gapNote = PpraInspectionGapNote::currentFor($agency->id, $item);
        } else {
            PpraInspectionGapNote::where('agency_id', $agency->id)->forItem($item)->open()->update(['resolved_at' => now()]);
        }

        return (object) [
            'item'        => $item,
            'label'       => 'Letterhead',
            'requirement' => 'A copy of the agency letterhead, carrying the PPA-prescribed information.',
            'status'      => $status,
            'why'         => $why,
            'evidence'    => $status === 'green' ? 'Letterhead available on demand' : null,
            'gap_note'    => $gapNote,
            'document'    => null,
            'upload_configs' => collect(),
        ];
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
            'item'            => $item,
            'label'           => $def['label'],
            'requirement'     => $def['requirement'],
            'status'          => $status,
            'why'             => $why,
            'evidence'        => $resolved['evidence'],
            'gap_note'        => $gapNote,
            // The actual document on file at /corex/my-portal/agency-documents
            // (Johan, 2026-09-28) — one source of truth, this row only LINKS
            // to it (provision id, name, dates) via the same anti-tamper
            // download route that page uses. Null when nothing is uploaded.
            'document'        => $resolved['document'],
            // Which agency_document_type_configs row(s) an inline "Upload"/
            // "Replace" action on a red row should target — one entry for
            // a/b/d/e, two (Certificate/Affidavit) for h's satisfies_group
            // pair. Only meaningful (and only rendered) when status='red'.
            'upload_configs'  => $resolved['configs'],
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
                'document' => null,
                'configs'  => collect(),
            ];
        }

        foreach ($configs as $config) {
            $provision = AgencyComplianceProvision::resolveForUser($config->id, null);
            $candidate = $this->statusForProvision($config, $provision);

            if ($best === null || $rank[$candidate['status']] > $rank[$best['status']]) {
                $best = $candidate;
            }
        }

        $best['configs'] = $configs->map(fn ($c) => (object) [
            'id'          => $c->id,
            'name'        => $c->name,
            'has_expiry'  => $c->has_expiry,
        ]);

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
                'document' => null,
            ];
        }

        // 2026-09-28 (Johan) — the pack must LINK to the one document store
        // at /corex/my-portal/agency-documents, never a second copy of it.
        // This carries exactly what the row needs to show the real document
        // inline and to build a View/Download link through the SAME
        // anti-tamper route that page already uses.
        $document = (object) [
            'provision_id'  => $provision->id,
            'name'          => $provision->document_original_name ?: 'document on file',
            'uploaded_at'   => $provision->created_at,
            'expires_at'    => $provision->effective_until,
        ];

        $evidence = $document->name;

        if (! $config->has_expiry || ! $provision->effective_until) {
            return [
                'status'   => 'green',
                'why'      => "{$config->name} on file, uploaded {$provision->created_at->format('d M Y')}.",
                'evidence' => $evidence,
                'document' => $document,
            ];
        }

        $daysLeft = (int) now()->diffInDays($provision->effective_until, false);
        $renewalWindow = $config->renewal_days ?: 30;

        if ($daysLeft < 0) {
            return [
                'status'   => 'red',
                'why'      => "{$config->name} expired " . $provision->effective_until->format('d M Y') . ' — renew now.',
                'evidence' => $evidence,
                'document' => $document,
            ];
        }

        if ($daysLeft <= $renewalWindow) {
            return [
                'status'   => 'amber',
                'why'      => "{$config->name} expires " . $provision->effective_until->format('d M Y') . " ({$daysLeft} days).",
                'evidence' => $evidence,
                'document' => $document,
            ];
        }

        return [
            'status'   => 'green',
            'why'      => "{$config->name} on file, valid until " . $provision->effective_until->format('d M Y') . '.',
            'evidence' => $evidence,
            'document' => $document,
        ];
    }
}
