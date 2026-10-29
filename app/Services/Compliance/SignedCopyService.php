<?php

namespace App\Services\Compliance;

use App\Models\Compliance\ComplianceDocumentAudit;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\PolicyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The signed copy of an RMCP / policy version and the compliance officer's sign-off (compliance.md).
 *
 * The signed file sits beside the version, not inside the approval, so it can be added or replaced on an ACTIVE version
 * without approving it again - nothing here touches staff acknowledgements. Everyone who may view the document sees
 * "Signed copy on file - signed by ... on ..., submitted to the FIC on ..." and a download button; a version with no
 * signed copy shows nothing extra.
 *
 * Reading back: before this existed, approving by upload stored the signed PDF in board_approval_document_path and
 * approving with the in-app board letter stored the letter's PDF there, but nothing ever served it. describe() falls back to
 * that file, so those versions show their signed copy too.
 */
class SignedCopyService
{
    /** Role Manager key. Owner and admin hold it by default; the agency's appointed compliance officer is allowed too. */
    public const PERMISSION = 'manage_signed_copy';

    public function canManage(?User $user, int $agencyId): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->hasPermission(self::PERMISSION)) {
            return true;
        }
        $officer = FicaOfficerAppointment::currentPrimary($agencyId);

        return $officer !== null && (int) $officer->user_id === (int) $user->id;
    }

    /** A draft has not been approved yet, so it has no signed copy to keep; the approval step takes the board's file. */
    public function canAttachTo(Model $version): bool
    {
        return in_array($version->status, ['active', 'superseded'], true);
    }

    /**
     * @return array{source:string, signed_by:?string, signed_on:?Carbon, submitted_to_fic_on:?Carbon, uploaded_by:?string,
     *               uploaded_at:?Carbon, line:string}|null null when no signed copy is on the system
     */
    public function describe(Model $version): ?array
    {
        $disk = Storage::disk('local');

        if ($version->signed_copy_path && $disk->exists($version->signed_copy_path)) {
            $uploader = $version->signed_copy_uploaded_by ? User::withoutGlobalScopes()->find($version->signed_copy_uploaded_by) : null;
            $info = [
                'source'              => 'signed_copy',
                'signed_by'           => $version->signed_by_name ?: null,
                'signed_on'           => $version->signed_on,
                'submitted_to_fic_on' => $version->submitted_to_fic_on,
                'uploaded_by'         => $uploader?->name,
                'uploaded_at'         => $version->signed_copy_uploaded_at,
            ];
        } elseif ($version->board_approval_document_path && $disk->exists($version->board_approval_document_path)) {
            $approver = $version->approved_by ? User::withoutGlobalScopes()->find($version->approved_by) : null;
            $who = trim(($approver?->name ?? '') . ($version->approver_title ? ' (' . $version->approver_title . ')' : ''));
            $info = [
                'source'              => 'approval',
                'signed_by'           => $who !== '' ? $who : null,
                'signed_on'           => $version->approved_at,
                'submitted_to_fic_on' => $version->submitted_to_fic_on,
                'uploaded_by'         => $approver?->name,
                'uploaded_at'         => $version->approved_at,
            ];
        } else {
            return null;
        }

        $info['line'] = $this->line($info);

        return $info;
    }

    /** "Signed copy on file - signed by Jane Smith on 12 March 2026, submitted to the FIC on 20 March 2026". */
    public function line(array $info): string
    {
        $line = 'Signed copy on file';
        $signed = [];
        if (! empty($info['signed_by'])) {
            $signed[] = 'signed by ' . $info['signed_by'];
        }
        if (! empty($info['signed_on'])) {
            $signed[] = 'on ' . $info['signed_on']->format('j F Y');
        }
        if ($signed) {
            $line .= ' — ' . implode(' ', $signed);
        }
        if (! empty($info['submitted_to_fic_on'])) {
            $line .= ($signed ? ', ' : ' — ') . 'submitted to the FIC on ' . $info['submitted_to_fic_on']->format('j F Y');
        }

        return $line;
    }

    /**
     * Keep or replace the signed copy. The earlier file is never deleted (the audit entry names it).
     */
    public function store(Model $version, UploadedFile $file, User $by, ?string $signedByName, ?string $signedOn, ?string $submittedOn): void
    {
        $kind = StaffTrainingService::kindOf($version);
        $dir  = ($kind === ComplianceDocumentAudit::KIND_POLICY ? 'policy' : 'rmcp') . "/{$version->agency_id}/signed";
        $path = $file->storeAs($dir, 'v' . $version->id . '-' . now()->format('Ymd-His') . '-' . Str::random(6) . '.pdf', 'local');

        $replaced = $version->signed_copy_path;
        $sha      = hash_file('sha256', Storage::disk('local')->path($path));

        $version->forceFill([
            'signed_copy_path'        => $path,
            'signed_copy_name'        => Str::limit($file->getClientOriginalName(), 250, ''),
            'signed_copy_sha256'      => $sha,
            'signed_copy_uploaded_by' => $by->id,
            'signed_copy_uploaded_at' => now(),
            'signed_by_name'          => $signedByName ?: null,
            'signed_on'               => $signedOn ?: null,
            'submitted_to_fic_on'     => $submittedOn ?: null,
        ])->save();

        ComplianceDocumentAudit::record($kind, (int) $version->agency_id, (int) $version->id,
            $replaced ? 'signed_copy_replaced' : 'signed_copy_uploaded', $by, [
                'file' => $path, 'sha256' => $sha, 'replaced' => $replaced,
                'signed_by' => $signedByName, 'signed_on' => $signedOn, 'submitted_to_fic_on' => $submittedOn,
            ]);
    }

    /** The path + a download name of the file describe() reports, or null. */
    public function file(Model $version): ?array
    {
        $info = $this->describe($version);
        if (! $info) {
            return null;
        }
        $path = $info['source'] === 'signed_copy' ? $version->signed_copy_path : $version->board_approval_document_path;
        $kind = $version instanceof PolicyVersion ? Str::slug((string) ($version->policy?->name ?: 'policy')) : 'rmcp';

        return ['path' => $path, 'name' => $kind . '-v' . $version->version_number . '-signed-copy.pdf'];
    }
}
