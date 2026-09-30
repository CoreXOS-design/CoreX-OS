<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use App\Models\Compliance\AgencyTransformationNote;
use App\Models\Compliance\PpraInspectionGapNote;
use App\Models\Compliance\PpraInspectionPack;
use App\Models\Deal;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * PPRA Inspection Pack — Phase A + B + C + D + E + G + H + I. .ai/specs/ppra-inspection-pack.md §5.
 *
 * Computes the live a-m checklist for an agency. Wired so far:
 * a, b, d, e, h (agency-vault-backed, Phase A), c, f, g (practitioner
 * FFC roster + letterhead, Phase B/C — v3 sources c/f from
 * PractitionerFfcRosterService, role-filtered + UserDocument-backed, per
 * Johan's 2026-09-28 ruling), i (transformation initiatives, Phase D — v3
 * structured-or-document, either satisfies the item), j (sales/rentals FY
 * list, Phase E — v3 "active and advertised" derivation), k (sales file
 * samples, Phase G — §6.8b), l (rental file samples, Phase H — §6.8c,
 * reads sample_rental_ids as Lease ids, not the old Rental model — see
 * PpraSamplePickerService's own docblock), m (mandate/MDF samples + the
 * ongoing register, Phase I — §6.8d/§6.8e, the only k/l/m item with a real
 * red/amber/green status, driven by PpraMandateRegisterService::
 * checklistStats() against ALL active advertised listings, not just the
 * sample). Every item a-m is now real.
 */
class PpraInspectionPackChecklistService
{
    public function __construct(
        private PractitionerFfcRosterService $practitionerRoster = new PractitionerFfcRosterService(),
        private PpraFinancialYearListService $fyList = new PpraFinancialYearListService(),
        private PpraMandateRegisterService $mandateRegister = new PpraMandateRegisterService(),
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
        $rows->push($this->transformationRow($agency));
        $rows->push($this->salesRentalsRow($agency));
        $rows->push($this->salesFileSampleRow($agency));
        $rows->push($this->rentalFileSampleRow($agency));
        $rows->push($this->mandateFileSampleRow($agency));

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
                ? 'No user is flagged Principal Property Practitioner — set it on their user profile.'
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

    /**
     * Item i — transformation initiatives (Phase D, v3). Green once a
     * current version exists, whichever entry_type it is — either path
     * independently satisfies the item (§6.5).
     */
    private function transformationRow(Agency $agency): object
    {
        $item = 'i';
        $current = AgencyTransformationNote::currentFor($agency->id);

        if (! $current) {
            $status = 'red';
            $why = 'No transformation initiatives statement on file — write one or upload a document.';
        } else {
            $status = 'green';
            $why = $current->entry_type === 'document'
                ? "Statement uploaded: {$current->summary}."
                : "Statement on file: {$current->summary}.";
        }

        $gapNote = null;
        if (in_array($status, ['amber', 'red'], true)) {
            $gapNote = PpraInspectionGapNote::currentFor($agency->id, $item);
        } else {
            PpraInspectionGapNote::where('agency_id', $agency->id)->forItem($item)->open()->update(['resolved_at' => now()]);
        }

        return (object) [
            'item'        => $item,
            'label'       => 'Transformation Initiatives',
            'requirement' => "The agency's B-BBEE / transformation initiatives — written in CoreX or an uploaded statement.",
            'status'      => $status,
            'why'         => $why,
            'evidence'    => $current?->summary,
            'gap_note'    => $gapNote,
            'document'    => null,
            'upload_configs' => collect(),
        ];
    }

    /**
     * Item j — current FY sales/rentals list, "active and advertised" (Phase
     * E, v3). Always green once the FY window can be computed (it always
     * can — financial_year_start_month defaults to March), since the item
     * IS the list itself, not a document that can be missing; a zero-listing
     * agency still satisfies it, with "0 sales, 0 rentals" stated plainly.
     */
    private function salesRentalsRow(Agency $agency): object
    {
        $item = 'j';
        [$from, $to] = $this->fyList->resolveRange($agency);
        $counts = $this->fyList->counts($agency, $from, $to);
        $rangeLabel = $this->fyList->rangeLabel($from, $to);

        $why = "{$counts['sales']} sales, {$counts['rentals']} rentals advertised in {$rangeLabel}.";

        PpraInspectionGapNote::where('agency_id', $agency->id)->forItem($item)->open()->update(['resolved_at' => now()]);

        return (object) [
            'item'        => $item,
            'label'       => 'Sales/Rentals List — Current Financial Year',
            'requirement' => 'Every property that was active and advertised at any point during the current financial year, split into sales and rentals.',
            'status'      => 'green',
            'why'         => $why,
            'evidence'    => $why,
            'gap_note'    => null,
            'document'    => null,
            'upload_configs' => collect(),
        ];
    }

    /**
     * Item k — sales file samples (Phase G, §6.8b/§5). `info` row, never
     * pass/fail — reads the agency's current DRAFT pack (§4.5) read-only
     * (PpraInspectionPack::currentDraftFor(), no create side-effect merely
     * from viewing the checklist) for whichever deal ids the shared picker
     * (§6.8a) has already saved.
     */
    private function salesFileSampleRow(Agency $agency): object
    {
        $item = 'k';
        $draft = PpraInspectionPack::currentDraftFor($agency);
        $sampleSize = $agency->ppra_pack_sales_sample_size ?: 5;
        $ids = $draft?->sample_deal_ids ?? [];

        if (empty($ids)) {
            $why = "No sales files sampled yet — pick up to {$sampleSize} deals and pull every document, communication and pipeline record CoreX holds for each.";
            $evidence = null;
        } else {
            $count = count($ids);
            $labels = Deal::where('agency_id', $agency->id)->whereIn('id', $ids)->get(['id', 'property_address', 'deal_no'])
                ->map(fn (Deal $d) => $d->property_address ?: ('Deal #' . ($d->deal_no ?: $d->id)))
                ->implode(', ');
            $why = "{$count} deal(s) sampled: {$labels}.";
            $evidence = $why;
        }

        return (object) [
            'item'        => $item,
            'label'       => 'Sales Files Sampled',
            'requirement' => 'A sample of ' . $sampleSize . ' sale deals (agency setting) with every document, communication and pipeline record CoreX holds for each.',
            'status'      => 'info',
            'why'         => $why,
            'evidence'    => $evidence,
            'gap_note'    => null,
            'document'    => null,
            'upload_configs' => collect(),
        ];
    }

    /**
     * Item l — rental file samples (Phase H, §6.8c/§5). `info` row, never
     * pass/fail — reads the agency's current DRAFT pack read-only, same
     * pattern as item k. sample_rental_ids holds Lease ids (Phase H
     * changed this from the old Rental model — see
     * PpraSamplePickerService's own docblock for why).
     */
    private function rentalFileSampleRow(Agency $agency): object
    {
        $item = 'l';
        $draft = PpraInspectionPack::currentDraftFor($agency);
        $sampleSize = $agency->ppra_pack_rental_sample_size ?: 5;
        $ids = $draft?->sample_rental_ids ?? [];

        if (empty($ids)) {
            $why = "No rental files sampled yet — pick up to {$sampleSize} leases and pull the application, lease, inspections, MDF, FICA and communications for each.";
            $evidence = null;
        } else {
            $count = count($ids);
            $labels = Lease::where('agency_id', $agency->id)->whereIn('id', $ids)->with('property')->get()
                ->map(fn (Lease $l) => $l->property->address ?? ('Lease #' . $l->id))
                ->implode(', ');
            $why = "{$count} lease(s) sampled: {$labels}.";
            $evidence = $why;
        }

        return (object) [
            'item'        => $item,
            'label'       => 'Rental Files Sampled',
            'requirement' => 'A sample of ' . $sampleSize . ' leases (agency setting) with the application, lease agreement, move-in/move-out inspections, inventory, MDF, FICA and communications for each.',
            'status'      => 'info',
            'why'         => $why,
            'evidence'    => $evidence,
            'gap_note'    => null,
            'document'    => null,
            'upload_configs' => collect(),
        ];
    }

    /**
     * Item m — mandate/MDF samples + the ongoing register (Phase I, §6.8d/
     * §6.8e/§5). Unlike k/l, this row has a REAL red/amber/green status,
     * driven by the register's aggregate stats against ALL active
     * advertised listings — the sample (evidence for the inspector) is a
     * separate concern from the gate (whether the agency is actually
     * compliant right now), matching §6.8d's own "relationship to the
     * ongoing register" note.
     */
    private function mandateFileSampleRow(Agency $agency): object
    {
        $item = 'm';
        $stats = $this->mandateRegister->checklistStats($agency);
        $why = "{$stats['total']}/{$stats['total']} active listings have every required document."; // overwritten below when there are gaps
        if ($stats['gaps'] > 0) {
            $ok = $stats['total'] - $stats['gaps'];
            $why = "{$ok}/{$stats['total']} active listings have every required document — {$stats['gaps']} listing(s) have a mandate/MDF/FICA gap.";
        } elseif ($stats['total'] === 0) {
            $why = 'No active advertised listings for this agency.';
        }

        $draft = PpraInspectionPack::currentDraftFor($agency);
        $sampleIds = $draft?->sample_listing_ids ?? [];
        if (! empty($sampleIds)) {
            $labels = Property::where('agency_id', $agency->id)->whereIn('id', $sampleIds)->get(['id', 'address'])
                ->map(fn (Property $p) => $p->address ?: ('Property #' . $p->id))
                ->implode(', ');
            $why .= ' Sample: ' . count($sampleIds) . ' listing(s) — ' . $labels . '.';
        }

        $gapNote = null;
        if (in_array($stats['status'], ['amber', 'red'], true)) {
            $gapNote = PpraInspectionGapNote::currentFor($agency->id, $item);
        } else {
            PpraInspectionGapNote::where('agency_id', $agency->id)->forItem($item)->open()->update(['resolved_at' => now()]);
        }

        return (object) [
            'item'        => $item,
            'label'       => 'Mandates + MDFs, Active Listings',
            'requirement' => 'Every active advertised listing has a valid mandate, MDF, and seller/owner FICA on file.',
            'status'      => $stats['status'],
            'why'         => $why,
            'evidence'    => $why,
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
