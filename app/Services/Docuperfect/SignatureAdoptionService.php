<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\EsignSettings;
use App\Models\Docuperfect\SignatureAdoption;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * esign-signing-modes.md §5 / §6 (Build step 2). Records a signer adopting their signature and initial once:
 * the adoption row (with the exact wording and its version), and the audit trail entry the certificate prints.
 *
 *  - Only an envelope in mode adopt_once (or one of the two retired modes it replaced) can be adopted on (the mode is the envelope's,
 *    fixed when it was created — never the agency's live setting).
 *  - Adopting the same two images again is a no-op (returns the current adoption); different images are a new
 *    generation and the earlier adoption is stamped superseded, never edited. Marks already placed keep pointing
 *    at the adoption they were stamped from.
 */
class SignatureAdoptionService
{
    public const MODES = [EsignSettings::MODE_ADOPT_ONCE];

    /** The agreement text and version a signer is shown for a mode. @return array{version:int,text:string} */
    public function wordingFor(string $mode): array
    {
        $text = (string) config("esign-adoption.wording." . EsignSettings::normaliseMode($mode), '');
        if ($text === '') {
            throw new InvalidArgumentException("No adoption wording for mode '{$mode}'.");
        }

        return ['version' => (int) config('esign-adoption.version', 1), 'text' => $text];
    }

    public function current(SignatureRequest $signingRequest): ?SignatureAdoption
    {
        return SignatureAdoption::where('signature_request_id', $signingRequest->id)->current()->first();
    }

    /** Parties who keep the agent's own way of signing (saved signature + apply-to-all) — never asked to adopt. Ruling 3. */
    public const AGENT_SIDE_ROLES = ['agent', 'supervisor', 'supervisor_final'];

    /**
     * True when this signer must adopt before any place can be placed: the envelope is in an adoption mode AND the
     * signer signs by link (an agent / supervisor / authoriser keeps saved signature + apply-to-all — Johan's ruling 3).
     * Whether the DOCUMENT is a web/CDS one is the caller's to check — only that path has a place-by-place ceremony.
     */
    public function required(SignatureRequest $signingRequest): bool
    {
        return in_array($this->modeOf($signingRequest), self::MODES, true)
            && ! in_array((string) $signingRequest->party_role, self::AGENT_SIDE_ROLES, true)
            && $signingRequest->isSigningParticipant(); // a party a proxy signs for (or a deceased party) never adopts
    }

    /**
     * Build step 6 — a deferred or waiting signer is not on their turn: they adopt when their own turn comes, in the
     * mode their envelope was created with (never the agency's live setting), not before.
     */
    public function notTheirTurn(SignatureRequest $signingRequest): bool
    {
        return in_array($signingRequest->status, [SignatureRequest::STATUS_WAITING, SignatureRequest::STATUS_DEFERRED], true);
    }

    /**
     * Build step 6 (proxy) — when this signer signs AS A PROXY the adoption and every mark it leads to are recorded as the
     * proxy's own, and the audit names both the person who signed and the party they signed for. Empty for everyone else.
     *
     * @return array<string,mixed> keys to merge into an audit row's metadata
     */
    public function signingContext(SignatureRequest $signingRequest): array
    {
        if (! $signingRequest->is_proxy) {
            return [];
        }

        $represented = $signingRequest->represented_contact_id
            ? \App\Models\Contact::withoutGlobalScopes()->find($signingRequest->represented_contact_id)
            : null;

        $covered = SignatureRequest::query()
            ->where('signature_template_id', $signingRequest->signature_template_id)
            ->where('party_role', $signingRequest->party_role)
            ->where('id', '!=', $signingRequest->id)
            ->get()
            ->filter(fn (SignatureRequest $r) => $r->nonSigningReason() === SignatureRequest::NON_SIGNING_REASON_PROXY_COLLAPSED)
            ->map(fn (SignatureRequest $r) => ['request_id' => $r->id, 'name' => $r->signer_name])
            ->values()
            ->all();

        return [
            'signing_as' => 'proxy',
            'proxy_name' => $signingRequest->signer_name,
            'proxy_email' => $signingRequest->signer_email,
            'represented_contact_id' => $signingRequest->represented_contact_id,
            'represented_name' => $represented?->full_name,
            'signing_for' => $covered,
        ];
    }

    public function modeOf(SignatureRequest $signingRequest): string
    {
        $signingRequest->loadMissing('template');

        // An envelope sent before 26 Oct 2026 may still carry one of the two retired adoption modes: both mean adopt_once.
        return EsignSettings::normaliseMode($signingRequest->template?->signing_mode);
    }

    /**
     * @throws DomainException           the envelope is not in an adoption mode
     * @throws InvalidArgumentException  an image is missing, not a PNG/JPEG data URI, or too large
     */
    public function adopt(SignatureRequest $signingRequest, string $signatureImage, string $initialImage, Request $http): SignatureAdoption
    {
        $mode = $this->modeOf($signingRequest);
        if (! in_array($mode, self::MODES, true)) {
            throw new DomainException('This document is signed by signing each spot yourself.');
        }

        $this->assertImage($signatureImage, 'signature');
        $this->assertImage($initialImage, 'initial');
        $wording = $this->wordingFor($mode);
        $sigHash = hash('sha256', $signatureImage);
        $initHash = hash('sha256', $initialImage);

        return DB::transaction(function () use ($signingRequest, $mode, $signatureImage, $initialImage, $sigHash, $initHash, $wording, $http) {
            $previous = SignatureAdoption::where('signature_request_id', $signingRequest->id)
                ->orderByDesc('generation')->lockForUpdate()->first();

            if ($previous && $previous->signature_image_hash === $sigHash && $previous->initial_image_hash === $initHash
                && $previous->disclaimer_version === $wording['version'] && EsignSettings::normaliseMode($previous->mode) === $mode) {
                return $previous; // the same adoption confirmed again — nothing new happened
            }

            $row = SignatureAdoption::create([
                'signature_request_id' => $signingRequest->id,
                'signature_template_id' => $signingRequest->signature_template_id,
                'generation' => $previous ? $previous->generation + 1 : 1,
                'mode' => $mode,
                'signer_name' => $signingRequest->signer_name,
                'signer_email' => $signingRequest->signer_email,
                'signature_image' => $signatureImage,
                'initial_image' => $initialImage,
                'signature_image_hash' => $sigHash,
                'initial_image_hash' => $initHash,
                'disclaimer_version' => $wording['version'],
                'disclaimer_text' => $wording['text'],
                'ip_address' => $http->ip(),
                'user_agent' => $http->userAgent(),
                'adopted_at' => now(),
            ]);

            if ($previous) {
                $previous->update(['superseded_at' => now(), 'superseded_by_id' => $row->id]);
            }

            SignatureAuditLog::create([
                'signature_template_id' => $signingRequest->signature_template_id,
                'signature_request_id' => $signingRequest->id,
                'action' => $previous ? 'signature_readopted' : 'signature_adopted',
                'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
                'actor_name' => $signingRequest->signer_name,
                'actor_email' => $signingRequest->signer_email,
                'actor_ip_address' => $http->ip(),
                'actor_user_agent' => $http->userAgent(),
                'metadata_json' => [
                    'adoption_id' => $row->id,
                    'generation' => $row->generation,
                    'mode' => $mode,
                    'disclaimer_version' => $wording['version'],
                    'signature_image_hash' => $sigHash,
                    'initial_image_hash' => $initHash,
                    'previous_adoption_id' => $previous?->id,
                ] + $this->signingContext($signingRequest),
            ]);

            return $row;
        });
    }

    private function assertImage(string $image, string $label): void
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/]+={0,2})$#', $image, $m)) {
            throw new InvalidArgumentException("The {$label} must be a PNG or JPEG image.");
        }
        $bytes = (int) (strlen($m[2]) * 3 / 4);
        if ($bytes <= 0 || $bytes > (int) config('esign-adoption.max_image_bytes', 1_500_000)) {
            throw new InvalidArgumentException("The {$label} image is empty or too large.");
        }
    }
}
