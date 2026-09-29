<?php

namespace App\Services\Compliance;

use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FicaSubmission;
use App\Models\Lease;
use App\Models\RentalInspection;
use App\Models\RentalInventory;
use Illuminate\Support\Str;

/**
 * PPRA Inspection Pack — Phase H, item l (§6.8c, amended by Johan's ruling
 * 2026-09-28). Deep per-lease aggregation: given one sampled `Lease`
 * (Phase H changed the picker's "rental" mode from the disconnected
 * `Rental` commission table to `Lease` — see PpraSamplePickerService's own
 * docblock), gathers everything CoreX holds for it and returns a manifest,
 * same shape as PpraSalesFileAggregationService (item k) — one row per
 * file, destination path inside the future pack ZIP
 * (`l-rental-sample/{lease-reference}/`, written by Phase J's job), plus
 * an explicit `missing` list for anything expected but not found. Never
 * fuzzy-matches on address — every source here is a real FK relation.
 */
class PpraRentalFileAggregationService
{
    /** Same stand-in as item k — the real catalogue has no separate "MDF" slug. */
    private const MANDATE_MDF_SLUGS = ['mandate', 'disclosure'];

    public function aggregate(Lease $lease): object
    {
        $lease->loadMissing(['property', 'tenants.contact', 'rentalApplication']);

        $reference = $lease->property->address ?? ('lease-' . $lease->id);
        $folder = 'l-rental-sample/' . Str::slug($reference) . '-' . $lease->id;
        $missing = [];

        $sections = [];

        $sections[] = [
            'category' => 'Lease & Rental Application Summary',
            'files'    => [$this->leaseSummaryEntry($lease, $folder)],
        ];

        $rentalApplication = $lease->rentalApplication;
        $applicationDocs = collect();
        if (! $rentalApplication) {
            $missing[] = 'No RentalApplication is linked to this lease (Lease.rental_application_id is null) — application documents (including any lease agreement or FICA supporting docs filed there) are not available from that source.';
        } else {
            $applicationDocs = Document::where('source_type', 'rental_application')
                ->where('source_id', $rentalApplication->id)
                ->with('documentType')
                ->get();

            if ($applicationDocs->isEmpty()) {
                $missing[] = 'The linked RentalApplication has no documents filed against it.';
            }
        }
        $sections[] = [
            'category' => 'Rental Application Documents (incl. lease agreement, if filed there)',
            'files'    => $applicationDocs->map(fn (Document $d) => $this->fileEntry($d, $folder))->all(),
        ];

        [$propertyDocs, $propertyMandateMdfMissing] = $this->propertyMandateMdf($lease, $applicationDocs);
        if ($propertyMandateMdfMissing) {
            $missing[] = $propertyMandateMdfMissing;
        }
        $sections[] = [
            'category' => 'Mandate / MDF — from the Property drive (deduplicated against application documents)',
            'files'    => $propertyDocs->map(fn (Document $d) => $this->fileEntry($d, $folder, 'property-mandate-mdf'))->all(),
        ];

        [$ficaFiles, $ficaMissing] = $this->ficaForTenantAndLandlord($lease, $folder);
        $missing = array_merge($missing, $ficaMissing);
        $sections[] = [
            'category' => 'FICA — Tenant(s) and Landlord',
            'files'    => $ficaFiles,
        ];

        [$inspectionFiles, $inspectionMissing] = $this->moveInMoveOutInspections($lease, $folder);
        $missing = array_merge($missing, $inspectionMissing);
        $sections[] = [
            'category' => 'Move-In / Move-Out Inspections',
            'files'    => $inspectionFiles,
        ];

        [$inventoryFiles, $inventoryMissing] = $this->inventory($lease, $folder);
        if ($inventoryMissing) {
            $missing[] = $inventoryMissing;
        }
        $sections[] = [
            'category' => 'Inventory',
            'files'    => $inventoryFiles,
        ];

        [$commsFiles, $commsMissing] = $this->communicationsLog($lease, $folder);
        if ($commsMissing) {
            $missing[] = $commsMissing;
        }
        $sections[] = [
            'category' => 'Communications Log',
            'files'    => $commsFiles,
        ];

        return (object) [
            'lease'     => $lease,
            'reference' => $reference,
            'folder'    => $folder,
            'sections'  => $sections,
            'missing'   => $missing,
        ];
    }

    private function leaseSummaryEntry(Lease $lease, string $folder): object
    {
        $tenantNames = $lease->tenants->map(fn ($t) => $this->contactDisplayName($t->contact))->filter()->implode(', ');
        $note = "Status: {$lease->status}. " . optional($lease->start_date)->format('d M Y') . ' to ' . (optional($lease->end_date)->format('d M Y') ?: 'ongoing') . '. Tenant(s): ' . ($tenantNames ?: 'none recorded') . '.';

        return (object) [
            'label'     => 'Lease & Rental Application Summary',
            'source'    => null,
            'dest_path' => $folder . '/00-lease-summary.txt',
            'note'      => $note,
            'exists'    => true,
        ];
    }

    private function fileEntry(Document $document, string $folder, string $subdir = ''): object
    {
        $sub = $subdir ? $subdir . '/' : '';
        $name = $document->original_name ?: basename((string) $document->storage_path);

        return (object) [
            'label'     => $document->documentType?->label ?? 'Document',
            'source'    => $document,
            'dest_path' => $folder . '/' . $sub . $name,
            'note'      => null,
            'exists'    => true,
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Document>, 1: ?string}
     */
    private function propertyMandateMdf(Lease $lease, \Illuminate\Support\Collection $applicationDocs): array
    {
        if (! $lease->property) {
            return [collect(), 'Lease has no linked property — mandate/MDF cannot be sourced.'];
        }

        $typeIds = DocumentType::whereIn('slug', self::MANDATE_MDF_SLUGS)->pluck('id');
        $applicationDocIds = $applicationDocs->pluck('id');

        $propertyDocs = $lease->property->documents()
            ->whereIn('document_type_id', $typeIds)
            ->with('documentType')
            ->get()
            ->reject(fn (Document $d) => $applicationDocIds->contains($d->id));

        $applicationHasMandate = $applicationDocs->contains(fn (Document $d) => $d->documentType && in_array($d->documentType->slug, self::MANDATE_MDF_SLUGS, true));

        if ($propertyDocs->isEmpty() && ! $applicationHasMandate) {
            return [$propertyDocs, 'No mandate or MDF/disclosure document found on the property or the rental application.'];
        }

        return [$propertyDocs, null];
    }

    /**
     * @return array{0: array<int, object>, 1: array<int, string>}
     */
    private function ficaForTenantAndLandlord(Lease $lease, string $folder): array
    {
        $files = [];
        $missing = [];

        $parties = $lease->tenants->map(fn ($t) => ['role' => 'Tenant', 'contact' => $t->contact])->values();
        $landlord = $lease->property?->sellerOwnerContact();
        if ($landlord) {
            $parties->push(['role' => 'Landlord', 'contact' => $landlord]);
        } else {
            $missing[] = 'No landlord contact could be resolved from the property (no seller/owner/landlord-role contact on file).';
        }

        if ($parties->where('contact', '!=', null)->isEmpty()) {
            $missing[] = 'No tenant or landlord contacts are linked to this lease — FICA cannot be resolved.';
            return [$files, $missing];
        }

        foreach ($parties as $party) {
            $contact = $party['contact'];
            if (! $contact) {
                continue;
            }
            $name = $this->contactDisplayName($contact);
            $label = $party['role'] . ' — ' . $name;

            $submission = FicaSubmission::where('contact_id', $contact->id)->approved()->latest('id')->first()
                ?? FicaSubmission::where('contact_id', $contact->id)->latest('id')->first();

            if (! $submission) {
                $missing[] = "No FICA submission found for {$label} (party on this lease).";
                continue;
            }

            $submission->loadMissing(['documents', 'linkedDocuments.documentType']);
            $uploaded = $submission->documents;
            $linked = $submission->linkedDocuments;

            if ($uploaded->isEmpty() && $linked->isEmpty()) {
                $missing[] = "FICA submission for {$label} has no supporting documents on file.";
                continue;
            }

            foreach ($uploaded as $doc) {
                $files[] = (object) [
                    'label'     => $label . ' — ' . $doc->document_type_label,
                    'source'    => $doc,
                    'dest_path' => $folder . '/fica/' . Str::slug($party['role'] . '-' . $name) . '/' . ($doc->file_name ?: basename((string) $doc->file_path)),
                    'note'      => null,
                    'exists'    => true,
                ];
            }
            foreach ($linked as $doc) {
                $files[] = (object) [
                    'label'     => $label . ' — ' . ($doc->documentType?->label ?? 'linked FICA document'),
                    'source'    => $doc,
                    'dest_path' => $folder . '/fica/' . Str::slug($party['role'] . '-' . $name) . '/' . ($doc->original_name ?: basename((string) $doc->storage_path)),
                    'note'      => null,
                    'exists'    => true,
                ];
            }
        }

        return [$files, $missing];
    }

    /**
     * Move-in / move-out — RentalInspection::TYPE_IN / TYPE_OUT, linked
     * directly via lease_id (RentalInspection::lease() belongsTo). If only
     * one exists (e.g. an ongoing lease with no move-out yet), state which
     * is missing and why — never a silent gap (§6.8c).
     *
     * @return array{0: array<int, object>, 1: array<int, string>}
     */
    private function moveInMoveOutInspections(Lease $lease, string $folder): array
    {
        $inspections = RentalInspection::where('lease_id', $lease->id)
            ->whereIn('type', [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT])
            ->get();

        $files = [];
        $missing = [];

        $byType = $inspections->groupBy('type');
        $labels = [RentalInspection::TYPE_IN => 'Move-in inspection', RentalInspection::TYPE_OUT => 'Move-out inspection'];

        foreach ($labels as $type => $label) {
            $matches = $byType->get($type, collect());
            if ($matches->isEmpty()) {
                $missing[] = "{$label} not found for this lease" . ($type === RentalInspection::TYPE_OUT ? ' (expected once the tenancy has ended).' : '.');
                continue;
            }
            foreach ($matches as $inspection) {
                $files[] = (object) [
                    'label'     => $label . ' (' . ($inspection->status ?? 'status unknown') . ')',
                    'source'    => $inspection,
                    'dest_path' => $folder . '/inspections/' . $type . '-inspection-' . $inspection->id . '.txt',
                    'note'      => 'Recorded ' . optional($inspection->created_at)->format('d M Y') . '.',
                    'exists'    => true,
                ];
            }
        }

        return [$files, $missing];
    }

    /**
     * @return array{0: array<int, object>, 1: ?string}
     */
    private function inventory(Lease $lease, string $folder): array
    {
        $inventories = RentalInventory::where('lease_id', $lease->id)->get();

        if ($inventories->isEmpty()) {
            return [[], 'No inventory record found for this lease.'];
        }

        $files = $inventories->map(fn (RentalInventory $inv) => (object) [
            'label'     => 'Inventory (' . ($inv->status ?? 'status unknown') . ')',
            'source'    => $inv,
            'dest_path' => $folder . '/inventory/inventory-' . $inv->id . '.txt',
            'note'      => 'Recorded ' . optional($inv->created_at)->format('d M Y') . '.',
            'exists'    => true,
        ])->all();

        return [$files, null];
    }

    /**
     * Communications — CommunicationLink never links a RentalApplication or
     * a Lease directly (confirmed: this box's linkable_type values are only
     * Contact, Property, Document, and DealV2 (and its own sub-types) —
     * never RentalApplication or Lease), so
     * this pulls every link against the lease's OWN property and its
     * tenant/landlord contacts instead, deduplicated by communication id.
     *
     * @return array{0: array<int, object>, 1: ?string}
     */
    private function communicationsLog(Lease $lease, string $folder): array
    {
        $linkableTargets = [];
        if ($lease->property) {
            $linkableTargets[] = [\App\Models\Property::class, $lease->property->id];
        }
        foreach ($lease->tenants as $tenant) {
            if ($tenant->contact) {
                $linkableTargets[] = [Contact::class, $tenant->contact->id];
            }
        }
        $landlord = $lease->property?->sellerOwnerContact();
        if ($landlord) {
            $linkableTargets[] = [Contact::class, $landlord->id];
        }

        if (empty($linkableTargets)) {
            return [[], 'No property or contacts linked to this lease — communications log not available.'];
        }

        $query = CommunicationLink::query()->with('communication');
        $query->where(function ($q) use ($linkableTargets) {
            foreach ($linkableTargets as [$type, $id]) {
                $q->orWhere(fn ($qq) => $qq->where('linkable_type', $type)->where('linkable_id', $id));
            }
        });

        $links = $query->get()
            ->filter(fn (CommunicationLink $l) => $l->communication !== null)
            ->unique(fn (CommunicationLink $l) => $l->communication_id)
            ->sortBy(fn (CommunicationLink $l) => $l->communication->created_at ?? $l->created_at);

        if ($links->isEmpty()) {
            return [[], 'No communications are logged against this lease\'s property or contacts.'];
        }

        return [[(object) [
            'label'     => 'Communications Log (' . $links->count() . ' entries)',
            'source'    => $links,
            'dest_path' => $folder . '/communications-log.txt',
            'note'      => $links->count() . ' communication(s) logged, chronological.',
            'exists'    => true,
        ]], null];
    }

    private function contactDisplayName(?Contact $contact): string
    {
        if (! $contact) {
            return '';
        }

        return trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: ('Contact #' . $contact->id);
    }
}
