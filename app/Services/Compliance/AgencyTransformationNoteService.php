<?php

namespace App\Services\Compliance;

use App\Models\Compliance\AgencyTransformationNote;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * PPRA Inspection Pack Phase D — item (i) versioning.
 * .ai/specs/ppra-inspection-pack.md §4.3/§6.5 (v3).
 */
class AgencyTransformationNoteService
{
    /**
     * Create a new current STRUCTURED version, superseding (soft-deleting)
     * the prior one. $initiatives is already-validated, one row per
     * initiative: {description, start_date, end_date, people_involved, spend_amount}.
     */
    public function createStructuredVersion(int $agencyId, array $initiatives, User $author): AgencyTransformationNote
    {
        $this->supersedeCurrent($agencyId);

        return AgencyTransformationNote::create([
            'agency_id'            => $agencyId,
            'entry_type'           => 'structured',
            'structured_data'      => array_values($initiatives),
            'summary'              => $this->summariseStructured($initiatives),
            'created_by_user_id'   => $author->id,
        ]);
    }

    /**
     * Create a new current DOCUMENT version — the principal's own statement,
     * uploaded instead of written in CoreX. Same private-disk pattern as the
     * agency-documents vault (AgencyComplianceSettingsController::store()).
     */
    public function createDocumentVersion(int $agencyId, UploadedFile $file, ?string $caption, User $author): AgencyTransformationNote
    {
        $this->supersedeCurrent($agencyId);

        $path = $file->store('ppra-transformation', 'local');

        return AgencyTransformationNote::create([
            'agency_id'                => $agencyId,
            'entry_type'               => 'document',
            'document_path'            => $path,
            'document_original_name'   => $file->getClientOriginalName(),
            'summary'                  => $caption ?: $file->getClientOriginalName(),
            'created_by_user_id'       => $author->id,
        ]);
    }

    /**
     * Archive the current version. "What happens to current now" — falls
     * back to the next non-deleted row by created_at automatically, since
     * currentFor() simply queries the latest non-deleted row; if none
     * remain, item (i) reads red again (checklist service's own logic).
     */
    public function archiveCurrent(int $agencyId): void
    {
        AgencyTransformationNote::currentFor($agencyId)?->delete();
    }

    /**
     * Restore a historical version — copies it FORWARD as a new current row
     * (never un-deletes a stale row into ambiguous multi-current state, per
     * §4.3). Preserves the historical row's entry_type and data.
     */
    public function restore(AgencyTransformationNote $historical, User $author): AgencyTransformationNote
    {
        $this->supersedeCurrent((int) $historical->agency_id);

        return AgencyTransformationNote::create([
            'agency_id'                => $historical->agency_id,
            'entry_type'               => $historical->entry_type,
            'structured_data'          => $historical->structured_data,
            'document_path'            => $historical->document_path,
            'document_original_name'   => $historical->document_original_name,
            'summary'                  => $historical->summary,
            'created_by_user_id'       => $author->id,
        ]);
    }

    private function supersedeCurrent(int $agencyId): void
    {
        AgencyTransformationNote::currentFor($agencyId)?->delete();
    }

    private function summariseStructured(array $initiatives): string
    {
        $count = count($initiatives);
        if ($count === 0) {
            return 'No initiatives listed.';
        }

        $first = trim((string) ($initiatives[0]['description'] ?? ''));
        $firstShort = mb_strlen($first) > 80 ? mb_substr($first, 0, 77) . '...' : $first;
        $extra = $count > 1 ? ' (+' . ($count - 1) . ' more)' : '';

        return "{$count} initiative" . ($count === 1 ? '' : 's') . ($firstShort !== '' ? ": {$firstShort}{$extra}" : '');
    }
}
