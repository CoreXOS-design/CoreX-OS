<?php

namespace App\Services\Compliance;

use App\Models\Document;
use App\Models\FicaSubmission;
use App\Models\Property;
use Illuminate\Support\Str;

/**
 * PPRA Inspection Pack — Phase I, item m (§6.8d). Deep per-listing
 * aggregation: given one sampled Property, gathers everything CoreX holds
 * for it. Same manifest shape as items k/l's aggregation services — one
 * row per file, destination path inside the future
 * `m-mandate-sample/{property-reference}/` pack ZIP folder (Phase J's job),
 * plus an explicit `missing` list. Broader than k/l by spec design: item m
 * pulls "any other document filed on the property's drive" too, not just
 * the named categories — an s25 inspection reasonably expects everything.
 */
class PpraMandateFileAggregationService
{
    private const NAMED_SLUGS = ['mandate', 'disclosure', 'body_corporate', 'levy_statement', 'rates_taxes'];

    public function aggregate(Property $property): object
    {
        $reference = $property->address ?: ('property-' . $property->id);
        $folder = 'm-mandate-sample/' . Str::slug($reference) . '-' . $property->id;
        $missing = [];

        $allDocs = $property->documents()->with('documentType')->get();
        $namedTypeIds = \App\Models\DocumentType::whereIn('slug', self::NAMED_SLUGS)->pluck('id', 'slug');

        $sections = [];

        $sections[] = [
            'category' => 'Mandate',
            'files'    => $this->docsForSlug($allDocs, $namedTypeIds->get('mandate'), $folder, 'mandate'),
        ];
        if (empty($sections[0]['files'])) {
            $missing[] = 'No mandate document found on the property drive.';
        }

        $sections[] = [
            'category' => 'MDF (Disclosure)',
            'files'    => $this->docsForSlug($allDocs, $namedTypeIds->get('disclosure'), $folder, 'mdf'),
        ];
        if (empty($sections[1]['files'])) {
            $missing[] = 'No MDF/disclosure document found on the property drive.';
        }

        [$ficaFiles, $ficaMissing] = $this->ficaForOwners($property, $folder);
        $missing = array_merge($missing, $ficaMissing);
        $sections[] = ['category' => 'FICA — Owner(s)', 'files' => $ficaFiles];

        $bodyCorpFiles = $this->docsForSlug($allDocs, $namedTypeIds->get('body_corporate'), $folder, 'body-corporate');
        $sections[] = ['category' => 'Body Corporate Rules (where sectional title and filed)', 'files' => $bodyCorpFiles];
        // Deliberately NO missing-reason here — spec: "where sectional title AND filed" is
        // conditional, not a universal expectation like mandate/MDF/FICA.

        $levyRatesFiles = array_merge(
            $this->docsForSlug($allDocs, $namedTypeIds->get('levy_statement'), $folder, 'levy-rates'),
            $this->docsForSlug($allDocs, $namedTypeIds->get('rates_taxes'), $folder, 'levy-rates')
        );
        $sections[] = ['category' => 'Levy / Rates Statements', 'files' => $levyRatesFiles];
        if (empty($levyRatesFiles)) {
            $missing[] = 'No levy or rates statement found on the property drive.';
        }

        $namedIds = collect($namedTypeIds)->values();
        $otherDocs = $allDocs->reject(fn (Document $d) => $d->document_type_id && $namedIds->contains($d->document_type_id));
        $sections[] = [
            'category' => 'Other Property Drive Documents',
            'files'    => $otherDocs->map(fn (Document $d) => $this->fileEntry($d, $folder, 'other'))->all(),
        ];

        return (object) [
            'property'  => $property,
            'reference' => $reference,
            'folder'    => $folder,
            'sections'  => $sections,
            'missing'   => $missing,
        ];
    }

    private function docsForSlug($allDocs, ?int $typeId, string $folder, string $subdir): array
    {
        if (! $typeId) {
            return [];
        }

        return $allDocs->where('document_type_id', $typeId)
            ->map(fn (Document $d) => $this->fileEntry($d, $folder, $subdir))
            ->values()
            ->all();
    }

    private function fileEntry(Document $document, string $folder, string $subdir): object
    {
        $name = $document->original_name ?: basename((string) $document->storage_path);

        return (object) [
            'label'     => $document->documentType?->label ?? 'Document',
            'source'    => $document,
            'dest_path' => $folder . '/' . $subdir . '/' . $name,
            'note'      => null,
            'exists'    => true,
        ];
    }

    /**
     * @return array{0: array<int, object>, 1: array<int, string>}
     */
    private function ficaForOwners(Property $property, string $folder): array
    {
        $owners = $property->contacts()->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])->get();
        if ($owners->isEmpty()) {
            $sole = $property->contacts()->get();
            if ($sole->count() === 1) {
                $owners = $sole;
            }
        }

        if ($owners->isEmpty()) {
            return [[], ['No owner contact is linked to this property — FICA cannot be resolved.']];
        }

        $files = [];
        $missing = [];

        foreach ($owners as $owner) {
            $name = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')) ?: ('Contact #' . $owner->id);

            $submission = FicaSubmission::where('contact_id', $owner->id)->approved()->latest('id')->first()
                ?? FicaSubmission::where('contact_id', $owner->id)->latest('id')->first();

            if (! $submission) {
                $missing[] = "No FICA submission found for {$name} (owner on this property).";
                continue;
            }

            $submission->loadMissing(['documents', 'linkedDocuments.documentType']);
            $uploaded = $submission->documents;
            $linked = $submission->linkedDocuments;

            if ($uploaded->isEmpty() && $linked->isEmpty()) {
                $missing[] = "FICA submission for {$name} has no supporting documents on file.";
                continue;
            }

            foreach ($uploaded as $doc) {
                $files[] = (object) [
                    'label'     => $name . ' — ' . $doc->document_type_label,
                    'source'    => $doc,
                    'dest_path' => $folder . '/fica/' . Str::slug($name) . '/' . ($doc->file_name ?: basename((string) $doc->file_path)),
                    'note'      => null,
                    'exists'    => true,
                ];
            }
            foreach ($linked as $doc) {
                $files[] = (object) [
                    'label'     => $name . ' — ' . ($doc->documentType?->label ?? 'linked FICA document'),
                    'source'    => $doc,
                    'dest_path' => $folder . '/fica/' . Str::slug($name) . '/' . ($doc->original_name ?: basename((string) $doc->storage_path)),
                    'note'      => null,
                    'exists'    => true,
                ];
            }
        }

        return [$files, $missing];
    }
}
