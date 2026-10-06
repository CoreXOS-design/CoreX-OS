<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Compliance\PpraEmploymentLetterFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * WET-INK FLOW (spec §20, 2026-10-06): the letter is created, printed, signed in wet ink outside CoreX and the signed
 * copy is uploaded from either screen. attachSignedCopy() is the ONE write path and streamSignedCopy() the ONE read
 * path for those scans. The earlier PIN-signing ceremony (two-step, AgentSignatureService) is retired.
 */
class PpraEmploymentLetterService
{
    public function __construct(
        private readonly PractitionerFfcRosterService $roster = new PractitionerFfcRosterService(),
        private readonly PpraEmploymentLetterPdfService $pdf = new PpraEmploymentLetterPdfService(),
    ) {
    }

    /**
     * Every merge field this letter needs, checked present. Returns an empty
     * array when nothing is missing. Each entry names exactly what's absent
     * and where to go fix it — never a silently blank merge field.
     *
     * `fix_url` is null when the viewer cannot act on the fix themselves (the
     * firm number lives in Company Settings, which needs
     * manage_performance_settings) — the message is shown without a link.
     *
     * @return list<array{label:string,fix_url:?string}>
     */
    public function missingFieldsFor(User $agent, Agency $agency, ?User $viewer = null): array
    {
        $missing = [];

        if (trim((string) $agent->id_number) === '') {
            $missing[] = ['label' => 'Your ID number is not on file', 'fix_url' => route('agent.portal') . '#profile'];
        }
        if (trim((string) $agent->ffc_number) === '') {
            $missing[] = ['label' => 'Your PPRA/FFC reference number is not on file', 'fix_url' => route('agent.portal') . '#profile'];
        }
        if (trim((string) $agent->designation) === '') {
            $missing[] = ['label' => 'Your designation is not set', 'fix_url' => route('agent.portal') . '#profile'];
        }
        if (trim((string) $agency->name) === '') {
            $missing[] = ['label' => 'The agency\'s legal name is not set', 'fix_url' => route('corex.settings')];
        }
        if (trim((string) ($agency->ppra_number ?? '')) === '') {
            $branch = $agent->effectiveBranchId() ? \App\Models\Branch::find($agent->effectiveBranchId()) : null;
            if (trim((string) ($branch?->ppra_number ?? '')) === '') {
                // Company Settings → Company tab → "PPRA Registration Number" (agencies.ppra_number).
                $missing[] = [
                    'label'   => 'The agency\'s PPRA firm number is not set',
                    'fix_url' => $viewer?->hasPermission('manage_performance_settings')
                        ? route('admin.company-settings') . '#company'
                        : null,
                ];
            }
        }

        $principal = $this->resolvePrincipal($agency);
        if ($principal['status'] === 'none') {
            $missing[] = [
                'label'   => 'This agency has no principal set',
                'fix_url' => route('admin.users'),
            ];
        }

        return $missing;
    }

    /**
     * Resolve this agency's principal(s) via the existing
     * PractitionerFfcRosterService::principalsFor() (the real
     * users.is_principal_practitioner flag).
     *
     * @return array{status:'none'|'single'|'multiple', principals:array}
     */
    public function resolvePrincipal(Agency $agency): array
    {
        $principals = $this->roster->principalsFor($agency->id);

        return [
            'status'     => match ($principals->count()) {
                0       => 'none',
                1       => 'single',
                default => 'multiple',
            },
            'principals' => $principals->values()->all(),
        ];
    }

    /**
     * Create a new letter directly in awaiting_signed_copy — every
     * merge field was already validated present by the caller
     * (missingFieldsFor()) before this is ever called.
     */
    public function create(User $agent, User $actor, ?int $principalUserId): PpraEmploymentLetter
    {
        $agency = Agency::withoutGlobalScopes()->find($agent->effectiveAgencyId());

        $resolved = $this->resolvePrincipal($agency);
        if ($resolved['status'] === 'none') {
            abort(422, 'This agency has no principal set.');
        }

        $principalIds = array_column($resolved['principals'], 'id');
        if ($resolved['status'] === 'single') {
            $principalUserId = $principalIds[0];
        } else {
            abort_unless($principalUserId && in_array($principalUserId, $principalIds, true), 422, 'Choose a principal for this letter.');
        }

        return PpraEmploymentLetter::create([
            'agency_id'          => $agency->id,
            'user_id'            => $agent->id,
            'principal_user_id'  => $principalUserId,
            'branch_id'          => $agent->effectiveBranchId(),
            'created_by_user_id' => $actor->id,
            'status'             => PpraEmploymentLetter::STATUS_AWAITING_SIGNED_COPY,
        ]);
    }

    /** Largest signed copy accepted, in KB — the existing staff-document rule (AgentPortalController::uploadDocument). */
    public const MAX_UPLOAD_KB = 10240;

    /** File kinds accepted for a signed copy (validation `mimes:` list). */
    public const UPLOAD_MIMES = 'pdf,jpg,jpeg,png';

    /** Private (non-public) disk the scans are written to. */
    public const DISK = 'local';

    /**
     * THE one write path for a signed copy — used by both the My Portal and the Admin controller, so an upload on
     * either screen is the same record on the other. One DB transaction: store the file, add the row, mark the letter
     * "Signed copy filed". A re-upload adds a NEW row (it becomes Current); earlier rows are never touched, so they
     * stay as superseded history. Refuses an archived letter. The caller has already checked access.
     */
    public function attachSignedCopy(PpraEmploymentLetter $letter, UploadedFile $file, User $actor, string $via): PpraEmploymentLetterFile
    {
        abort_if($letter->trashed(), 409, 'This letter is archived — a signed copy cannot be uploaded to it.');
        abort_unless(in_array($via, [PpraEmploymentLetterFile::VIA_ADMIN, PpraEmploymentLetterFile::VIA_PORTAL], true), 500);

        $ext  = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'pdf');
        $path = 'ppra-employment-letters/' . $letter->agency_id . '/' . $letter->id . '/signed-copies/' . Str::random(40) . '.' . $ext;

        $record = null;
        try {
            $record = DB::transaction(function () use ($letter, $file, $actor, $via, $path) {
                Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

                $row = PpraEmploymentLetterFile::create([
                    'agency_id'           => $letter->agency_id,
                    'letter_id'           => $letter->id,
                    'path'                => $path,
                    'original_name'       => mb_substr($file->getClientOriginalName(), 0, 250),
                    'size'                => (int) $file->getSize(),
                    'mime'                => $file->getMimeType(),
                    'uploaded_by_user_id' => $actor->id,
                    'uploaded_via'        => $via,
                ]);

                $letter->status = PpraEmploymentLetter::STATUS_SIGNED_COPY_FILED;
                $letter->save();

                return $row;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path); // roll the file back with the rows
            throw $e;
        }

        $letter->unsetRelation('files')->unsetRelation('currentFile');

        Log::info('ppra-employment-letter: signed copy filed', [
            'letter' => $letter->id, 'file' => $record->id, 'by' => $actor->id, 'via' => $via,
        ]);

        return $record;
    }

    /**
     * THE one read path for a signed copy — both screens' download actions call this AFTER their own access check.
     * Streams from the private disk; never a public URL.
     */
    public function streamSignedCopy(PpraEmploymentLetterFile $file, bool $inline = false): Response
    {
        abort_unless(Storage::disk(self::DISK)->exists($file->path), 404, 'The signed copy file is missing.');

        $name = trim(str_replace(['"', "\r", "\n", '/', '\\'], '_', $file->original_name)) ?: ('signed-copy-' . $file->id);
        $headers = ['Content-Type' => $file->mime ?: 'application/octet-stream'];

        return $inline
            ? Storage::disk(self::DISK)->response($file->path, $name, $headers + ['Content-Disposition' => 'inline; filename="' . $name . '"'])
            : Storage::disk(self::DISK)->download($file->path, $name, $headers);
    }

    /** Cancel/archive a letter that has no signed copy yet — soft delete only, never hard. */
    public function cancel(PpraEmploymentLetter $letter): void
    {
        abort_unless($letter->isCancellableByAgent(), 409, 'A letter with a signed copy filed cannot be archived from here — use the Admin register.');

        $letter->delete();
    }
}
