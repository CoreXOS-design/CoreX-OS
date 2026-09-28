<?php

namespace App\Services\Compliance;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\Communications\CommunicationLink;
use App\Models\FicaSubmission;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Support\Str;

/**
 * PPRA Inspection Pack — Phase G, item k (§6.8b). Deep per-deal aggregation:
 * given one sampled DR1 `Deal` (the picker's "deal" mode persists DR1 ids,
 * see PpraSamplePickerService::deals()), gathers everything CoreX holds for
 * it and returns a manifest — one row per file, with its destination path
 * inside the future pack ZIP (§6.9's `k-sales-sample/{deal-reference}/`
 * folder, written by Phase J's job) — plus an explicit `missing` list for
 * anything expected but not found, per BUILD_STANDARD "no silent gaps."
 *
 * Deliberately returns a MANIFEST (source path + label + destination path),
 * not a written ZIP — Phase J owns the actual file-bundling job; this phase
 * only needs the manifest, both to drive the Report's per-file index page
 * (§6.2 item 5) and later to be walked by Phase J without re-deriving it.
 */
class PpraSalesFileAggregationService
{
    /** Property-drive document types that stand in for "mandate/MDF" (§6.8b) — the real catalogue has no separate "MDF" slug; `disclosure` is CoreX's Mandate Disclosure Form. */
    private const MANDATE_MDF_SLUGS = ['mandate', 'disclosure'];

    public function aggregate(Deal $deal): object
    {
        $deal->loadMissing(['property', 'contacts', 'pipelineSteps']);

        $reference = $deal->deal_no ?: ('deal-' . $deal->id);
        $folder = 'k-sales-sample/' . Str::slug($reference);
        $missing = [];

        $dealDocs = Document::where('source_type', 'deal')
            ->where('source_id', $deal->id)
            ->with('documentType')
            ->get();

        if ($dealDocs->isEmpty()) {
            $missing[] = 'No documents are filed directly on this deal.';
        }

        $sections = [];

        $sections[] = [
            'category' => 'Deal Record & Pipeline History',
            'files'    => [$this->pipelineSummaryEntry($deal, $folder)],
        ];

        $sections[] = [
            'category' => 'Deal Documents (mandate, OTP/sale agreement, MDF/disclosure, commission/proforma, etc.)',
            'files'    => $dealDocs->map(fn (Document $d) => $this->fileEntry($d, $folder))->all(),
        ];

        [$propertyDocs, $propertyMandateMdfMissing] = $this->propertyMandateMdf($deal, $dealDocs);
        if ($propertyMandateMdfMissing) {
            $missing[] = $propertyMandateMdfMissing;
        }
        $sections[] = [
            'category' => 'Mandate / MDF — from the Property drive (deduplicated against deal documents)',
            'files'    => $propertyDocs->map(fn (Document $d) => $this->fileEntry($d, $folder, 'property-mandate-mdf'))->all(),
        ];

        [$ficaFiles, $ficaMissing] = $this->ficaForAllParties($deal, $folder);
        $missing = array_merge($missing, $ficaMissing);
        $sections[] = [
            'category' => 'FICA — All Parties',
            'files'    => $ficaFiles,
        ];

        [$commsFiles, $commsMissing] = $this->communicationsLog($deal, $folder);
        if ($commsMissing) {
            $missing[] = $commsMissing;
        }
        $sections[] = [
            'category' => 'Communications Log',
            'files'    => $commsFiles,
        ];

        return (object) [
            'deal'      => $deal,
            'reference' => $reference,
            'folder'    => $folder,
            'sections'  => $sections,
            'missing'   => $missing,
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

    private function pipelineSummaryEntry(Deal $deal, string $folder): object
    {
        $steps = $deal->pipelineSteps;

        $label = $steps->isEmpty()
            ? 'No pipeline steps recorded for this deal.'
            : $steps->count() . ' pipeline step(s): ' . $steps->map(fn ($s) => ($s->pipelineStep->name ?? 'step') . ' (' . ($s->status ?? 'unknown') . ')')->implode(', ');

        return (object) [
            'label'         => 'Deal & Pipeline Summary',
            'source'        => null,
            'dest_path'     => $folder . '/00-deal-and-pipeline-summary.txt',
            'note'          => $label,
            'exists'        => true,
        ];
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, Document>, 1: ?string}
     */
    private function propertyMandateMdf(Deal $deal, \Illuminate\Support\Collection $dealDocs): array
    {
        if (! $deal->property) {
            return [collect(), 'Deal has no linked property — mandate/MDF cannot be sourced from a property drive.'];
        }

        $typeIds = DocumentType::whereIn('slug', self::MANDATE_MDF_SLUGS)->pluck('id');
        $dealDocIds = $dealDocs->pluck('id');

        $propertyDocs = $deal->property->documents()
            ->whereIn('document_type_id', $typeIds)
            ->with('documentType')
            ->get()
            ->reject(fn (Document $d) => $dealDocIds->contains($d->id));

        $dealHasMandate = $dealDocs->contains(fn (Document $d) => $d->documentType && in_array($d->documentType->slug, self::MANDATE_MDF_SLUGS, true));

        if ($propertyDocs->isEmpty() && ! $dealHasMandate) {
            return [$propertyDocs, 'No mandate or MDF/disclosure document found on the deal or its property.'];
        }

        return [$propertyDocs, null];
    }

    /**
     * @return array{0: array<int, object>, 1: array<int, string>}
     */
    private function ficaForAllParties(Deal $deal, string $folder): array
    {
        $files = [];
        $missing = [];

        if ($deal->contacts->isEmpty()) {
            return [$files, ['No contacts (buyer/seller/other party) are linked to this deal — FICA cannot be resolved.']];
        }

        foreach ($deal->contacts as $contact) {
            $submission = FicaSubmission::where('contact_id', $contact->id)->approved()->latest('id')->first()
                ?? FicaSubmission::where('contact_id', $contact->id)->latest('id')->first();

            if (! $submission) {
                $missing[] = "No FICA submission found for {$contact->name} (party on this deal).";
                continue;
            }

            $submission->loadMissing(['documents', 'linkedDocuments.documentType']);
            $uploaded = $submission->documents;
            $linked = $submission->linkedDocuments;

            if ($uploaded->isEmpty() && $linked->isEmpty()) {
                $missing[] = "FICA submission for {$contact->name} has no supporting documents on file.";
                continue;
            }

            foreach ($uploaded as $doc) {
                $files[] = (object) [
                    'label'     => $contact->name . ' — ' . $doc->document_type_label,
                    'source'    => $doc,
                    'dest_path' => $folder . '/fica/' . Str::slug($contact->name) . '/' . ($doc->file_name ?: basename((string) $doc->file_path)),
                    'note'      => null,
                    'exists'    => true,
                ];
            }

            foreach ($linked as $doc) {
                $files[] = (object) [
                    'label'     => $contact->name . ' — ' . ($doc->documentType?->label ?? 'linked FICA document'),
                    'source'    => $doc,
                    'dest_path' => $folder . '/fica/' . Str::slug($contact->name) . '/' . ($doc->original_name ?: basename((string) $doc->storage_path)),
                    'note'      => null,
                    'exists'    => true,
                ];
            }
        }

        return [$files, $missing];
    }

    /**
     * Communications log — CommunicationLink.linkable_type is stamped as
     * DealV2 (DR2), never DR1 Deal (confirmed: every linkable_type value on
     * this box is a DealV2/Contact/Property/Document/AgencyServiceProvider
     * class, never App\Models\Deal). DR1↔DR2 bridge is DealV2.legacy_deal_id
     * — NOT guaranteed 1:1 (confirmed: 96/169 DR1 deals on this agency have
     * no DR2 twin at all), so a sampled deal predating DR2 correctly gets an
     * explicit "not available" note here rather than a silently empty section.
     *
     * @return array{0: array<int, object>, 1: ?string}
     */
    private function communicationsLog(Deal $deal, string $folder): array
    {
        $dealV2 = DealV2::where('legacy_deal_id', $deal->id)->first();

        if (! $dealV2) {
            return [[], 'No DR2 record is linked to this deal (legacy_deal_id) — the communications log is not available for deals that predate CoreX\'s DR2 pipeline.'];
        }

        $links = CommunicationLink::where('linkable_type', DealV2::class)
            ->where('linkable_id', $dealV2->id)
            ->with('communication')
            ->get()
            ->filter(fn (CommunicationLink $l) => $l->communication !== null)
            ->sortBy(fn (CommunicationLink $l) => $l->communication->created_at ?? $l->created_at);

        if ($links->isEmpty()) {
            return [[], 'No communications are logged against this deal\'s DR2 record.'];
        }

        return [[(object) [
            'label'     => 'Communications Log (' . $links->count() . ' entries)',
            'source'    => $links,
            'dest_path' => $folder . '/communications-log.txt',
            'note'      => $links->count() . ' communication(s) logged, chronological.',
            'exists'    => true,
        ]], null];
    }
}
