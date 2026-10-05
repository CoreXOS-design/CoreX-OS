<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Mail\Compliance\PpraEmploymentLetterPrincipalNotificationMail;
use App\Mail\Compliance\PpraEmploymentLetterSignedMail;
use App\Models\Agency;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use App\Services\AgentSignatureService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * The fixed-order, two-signer PIN ceremony: agent signs first, then the
 * agency's resolved principal. Built on AgentSignatureService (the PIN
 * mechanism) exactly like EvaluationCertificateController — deliberately
 * NOT the canon web/CDS e-sign pipeline, which has no PIN concept and no
 * fixed-specific-signer routing (see the 2026-10-05 investigation report).
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
     * @return list<array{label:string,fix_url:string}>
     */
    public function missingFieldsFor(User $agent, Agency $agency): array
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
                $missing[] = ['label' => 'The agency\'s PPRA firm number is not set', 'fix_url' => route('corex.settings')];
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
     * Create a new letter directly in awaiting_agent_signature — every
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
            'status'             => PpraEmploymentLetter::STATUS_AWAITING_AGENT_SIGNATURE,
        ]);
    }

    /**
     * Bake the agent's signature (PIN-unlocked image already resolved by the
     * caller), move to awaiting_principal_signature, and — unless the agent
     * IS the resolved principal (self-sign-both case, no round-trip needed)
     * — email the principal that a letter awaits their signature.
     */
    public function signAsAgent(PpraEmploymentLetter $letter, User $agent, string $signatureImage, string $ip): void
    {
        $letter->agent_signature_image = $signatureImage;
        $letter->agent_signed_at       = now();
        $letter->agent_signed_ip       = $ip;
        $letter->status                = PpraEmploymentLetter::STATUS_AWAITING_PRINCIPAL_SIGNATURE;
        $letter->save();

        if ((int) $letter->principal_user_id === (int) $letter->user_id) {
            return; // self-sign-both — the agent is about to sign the principal block themselves.
        }

        $this->notifyPrincipal($letter);
    }

    /**
     * Bake the principal's signature, finalise the immutable signed PDF,
     * move to signed, and notify the agent (in-app + email).
     */
    public function signAsPrincipal(PpraEmploymentLetter $letter, User $principal, string $signatureImage, string $ip): void
    {
        $agent  = User::withoutGlobalScopes()->find($letter->user_id);
        $agency = Agency::withoutGlobalScopes()->find($letter->agency_id);

        $letter->principal_signature_image = $signatureImage;
        $letter->principal_signed_at       = now();
        $letter->principal_signed_ip       = $ip;
        $letter->status                    = PpraEmploymentLetter::STATUS_SIGNED;

        $pdfPath = $this->pdf->generate(
            $letter,
            $agent,
            $principal,
            $agency,
            $letter->agent_signature_image,
            $signatureImage,
        );

        $storedPath = 'ppra-employment-letters/' . $agency->id . '/' . $letter->id . '-signed.pdf';
        Storage::put($storedPath, file_get_contents($pdfPath));
        @unlink($pdfPath);

        $letter->signed_pdf_path = $storedPath;
        $letter->save();

        $this->notifyAgentSigned($letter, $agent, $principal);
    }

    /** Email + in-app notify the agent that their letter is fully signed. Non-fatal. */
    private function notifyAgentSigned(PpraEmploymentLetter $letter, User $agent, User $principal): void
    {
        try {
            DatabaseNotification::create([
                'id'              => (string) Str::uuid(),
                'type'            => 'ppra_employment_letter.signed',
                'notifiable_type' => User::class,
                'notifiable_id'   => $agent->id,
                'data'            => [
                    'title'      => 'Your PPRA employment letter is signed',
                    'message'    => $principal->name . ' signed your Confirmation of Employment letter — it is ready to download.',
                    'action_url' => route('agent.portal') . '#documents',
                    'letter_id'  => $letter->id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ppra-employment-letter: agent in-app notification failed', ['letter' => $letter->id, 'error' => $e->getMessage()]);
        }

        if (! $agent->email) {
            return;
        }

        try {
            Mail::to($agent->email)->send(new PpraEmploymentLetterSignedMail($letter, $agent, $principal));
        } catch (\Throwable $e) {
            Log::warning('ppra-employment-letter: agent signed email failed', ['letter' => $letter->id, 'error' => $e->getMessage()]);
        }
    }

    /** Email the resolved principal that a letter awaits their signature. Non-fatal. Updates reminder_last_sent_at. */
    public function notifyPrincipal(PpraEmploymentLetter $letter): void
    {
        $principal = User::withoutGlobalScopes()->find($letter->principal_user_id);
        $agent     = User::withoutGlobalScopes()->find($letter->user_id);

        if (! $principal || ! $principal->email || ! $agent) {
            return;
        }

        try {
            Mail::to($principal->email)->send(new PpraEmploymentLetterPrincipalNotificationMail($letter, $agent, $principal));
            $letter->forceFill(['reminder_last_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('ppra-employment-letter: principal notification failed', ['letter' => $letter->id, 'error' => $e->getMessage()]);
        }
    }

    /** Cancel/archive an unsigned letter — soft delete only, never hard. */
    public function cancel(PpraEmploymentLetter $letter): void
    {
        abort_if($letter->isSigned(), 409, 'A signed letter cannot be archived from here — use the Admin register.');

        $letter->delete();
    }
}
