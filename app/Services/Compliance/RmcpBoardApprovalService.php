<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Mail\Compliance\RmcpBoardApprovalMail;
use App\Models\Agency;
use App\Models\Compliance\AgencyBoardMember;
use App\Models\Compliance\RmcpBoardAuditLog;
use App\Models\Compliance\RmcpBoardLetter;
use App\Models\Compliance\RmcpBoardSignature;
use App\Models\Compliance\RmcpVersion;
use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Services\Otp\OtpService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * RMCP board approval letter (.ai/specs/compliance.md "RMCP board approval letter").
 *
 * The board resolution for a DRAFT version is sent to each board member as a secure link (code to their own email, as the
 * DR2 document links do), signed with the same Type/Draw signature pad staff use, and filed as a signed PDF. The Approve
 * step then uses that PDF instead of an upload. Every send is a new numbered round; a signed line, a stored signature image
 * and a signed PDF are never overwritten - a change means a new round or a new timestamped file.
 *
 * Plain-language messages are thrown as \DomainException; the controllers show them as they are.
 */
class RmcpBoardApprovalService
{
    public const OTP_PURPOSE = 'rmcp_board_letter';

    public function __construct(
        private RmcpVariableResolver $resolver,
        private CompliancePdf $pdf,
    ) {
    }

    // ── The board list ──────────────────────────────────────────────────────

    /**
     * First time an agency looks at its board list: start it from the people who hold the agency's top role - users with an
     * owner role, or, when the agency has none (owner roles are the platform owner's and sit outside agencies), its active
     * `admin` users. Only ever runs while the agency has never had a board row, archived ones included, so an archived
     * member does not come back.
     */
    public function seedDefaultsIfNeverConfigured(int $agencyId): void
    {
        $everHad = AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->withTrashed()->where('agency_id', $agencyId)->exists();
        if ($everHad) {
            return;
        }

        $ownerRoles = Role::query()->where('is_owner', true)->pluck('name')->all();
        $users = $this->activeAgencyUsers($agencyId)->whereIn('role', $ownerRoles ?: ['__none__'])->orderBy('name')->get();
        if ($users->isEmpty()) {
            $users = $this->activeAgencyUsers($agencyId)->where('role', 'admin')->orderBy('name')->get();
        }

        foreach ($users->values() as $i => $user) {
            AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
                'agency_id'  => $agencyId,
                'name'       => $user->name,
                'title'      => trim((string) $user->designation) !== '' ? trim((string) $user->designation) : 'Director',
                'email'      => (string) $user->email,
                'user_id'    => $user->id,
                'sort_order' => $i + 1,
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int,AgencyBoardMember> */
    public function members(int $agencyId)
    {
        return AgencyBoardMember::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $agencyId)->orderBy('sort_order')->orderBy('id')->get();
    }

    // ── Sending ─────────────────────────────────────────────────────────────

    /**
     * Start a new round: freeze the wording and the RMCP fingerprint, give each current board member a signature line and an
     * emailed link. An earlier open round is superseded (kept, never deleted). Returns the letter and the names of members whose
     * email could not be handed to the mail server (their line stays "not sent yet" and can be resent).
     *
     * @return array{letter:RmcpBoardLetter, failed:list<string>}
     */
    public function send(RmcpVersion $version, User $actor, CarbonInterface $effectiveFrom, ?CarbonInterface $nextReviewDue): array
    {
        if (! $version->canBeEdited()) {
            throw new \DomainException('Only a draft RMCP can be sent to the board.');
        }
        if ($effectiveFrom->copy()->startOfDay()->lt(now()->startOfDay())) {
            throw new \DomainException('The effective date cannot be in the past.');
        }
        if ($nextReviewDue && $nextReviewDue->copy()->startOfDay()->lte($effectiveFrom->copy()->startOfDay())) {
            throw new \DomainException('The review date must be after the effective date.');
        }

        $this->seedDefaultsIfNeverConfigured((int) $version->agency_id);
        $members = $this->members((int) $version->agency_id);
        if ($members->isEmpty()) {
            throw new \DomainException('Add your board members first (Company Settings, Compliance tab).');
        }
        foreach ($members as $m) {
            if (! filter_var($m->email, FILTER_VALIDATE_EMAIL)) {
                throw new \DomainException("{$m->name} has no valid email address. Fix it in Company Settings, Compliance tab.");
            }
        }

        $version->loadMissing('sections');
        $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($version->agency_id);
        $variables = $this->resolver->resolve($agency, $version);
        $hash = $version->contentHash();

        $letterHtml = view('compliance.rmcp-board._resolution', [
            'legalName'     => (string) ($variables['agency.legal_name'] ?? $agency->name),
            'regNo'         => (string) ($variables['agency.reg_no'] ?? ''),
            'versionNumber' => $version->version_number,
            'rmcpTitle'     => $version->title ?: 'Risk Management and Compliance Programme',
            'effectiveFrom' => $effectiveFrom,
            'nextReviewDue' => $nextReviewDue,
            'fingerprint'   => strtoupper(substr($hash, 0, 16)),
        ])->render();

        $letter = DB::transaction(function () use ($version, $actor, $effectiveFrom, $nextReviewDue, $hash, $letterHtml, $members, $agency) {
            $open = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('rmcp_version_id', $version->id)
                ->where('status', RmcpBoardLetter::STATUS_SENT)->lockForUpdate()->get();
            foreach ($open as $old) {
                $old->update(['status' => RmcpBoardLetter::STATUS_SUPERSEDED]);
                $this->audit($version, $old, null, 'superseded', $actor, null, null, ['reason' => 'a new round was sent']);
            }

            $round = (int) RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->withTrashed()->where('rmcp_version_id', $version->id)->max('round') + 1;

            $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
                'agency_id'       => $version->agency_id,
                'rmcp_version_id' => $version->id,
                'round'           => $round,
                'status'          => RmcpBoardLetter::STATUS_SENT,
                'effective_from'  => $effectiveFrom->toDateString(),
                'next_review_due' => $nextReviewDue?->toDateString(),
                'content_hash'    => $hash,
                'letter_html'     => $letterHtml,
                'sent_by'         => $actor->id,
                'sent_at'         => now(),
            ]);

            foreach ($members as $m) {
                RmcpBoardSignature::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
                    'agency_id'       => $version->agency_id,
                    'letter_id'       => $letter->id,
                    'board_member_id' => $m->id,
                    'user_id'         => $m->user_id ?: $this->userIdForEmail((int) $version->agency_id, $m->email),
                    'member_name'     => $m->name,
                    'member_title'    => $m->title,
                    'member_email'    => $m->email,
                    'token'           => $this->newToken(),
                    'status'          => RmcpBoardSignature::STATUS_PENDING,
                ]);
            }

            $this->audit($version, $letter, null, 'sent', $actor, null, null, [
                'round'   => $round,
                'members' => $members->pluck('name')->all(),
                'effective_from' => $effectiveFrom->toDateString(),
            ]);

            return $letter;
        });

        $failed = [];
        foreach ($letter->signatures()->get() as $sig) {
            if (! $this->deliver($letter, $sig, $actor, $agency, (int) $version->version_number, false)) {
                $failed[] = $sig->member_name;
            }
        }

        return ['letter' => $letter->fresh('signatures'), 'failed' => $failed];
    }

    public function resend(RmcpBoardSignature $sig, User $actor): bool
    {
        $letter = $sig->letter()->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->first();
        if (! $letter || ! $letter->isOpen()) {
            throw new \DomainException('This letter is no longer open for signing.');
        }
        if ($sig->isSigned()) {
            throw new \DomainException("{$sig->member_name} has already signed.");
        }

        $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->agency_id);
        $version = RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->rmcp_version_id);
        $ok = $this->deliver($letter, $sig, $actor, $agency, (int) $version->version_number, true);
        $this->audit($version, $letter, $sig, 'resent', $actor, null, null, ['delivered' => $ok]);

        return $ok;
    }

    public function cancel(RmcpBoardLetter $letter, User $actor): void
    {
        if (! $letter->isOpen()) {
            throw new \DomainException('Only a letter that is still waiting for signatures can be cancelled.');
        }
        $letter->update(['status' => RmcpBoardLetter::STATUS_CANCELLED]);
        $this->audit(RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->rmcp_version_id), $letter, null, 'cancelled', $actor, null, null, []);
    }

    private function deliver(RmcpBoardLetter $letter, RmcpBoardSignature $sig, ?User $actor, Agency $agency, int $versionNumber, bool $reminder): bool
    {
        $agencyName = (string) ($agency->trading_name ?: $agency->name);
        try {
            app(ComplianceMailDispatcher::class)->send($sig->member_email, new RmcpBoardApprovalMail(
                $agency, $letter, $sig, route('rmcp-board.show', $sig->token), $agencyName, $versionNumber, $actor, $reminder
            ));
        } catch (\Throwable $e) {
            Log::warning('RMCP board letter email failed', ['signature_id' => $sig->id, 'error' => $e->getMessage()]);
            $sig->forceFill(['last_send_error' => Str::limit($e->getMessage(), 280, '')])->save();

            return false;
        }

        $sig->forceFill([
            'status'          => in_array($sig->status, [RmcpBoardSignature::STATUS_VIEWED, RmcpBoardSignature::STATUS_SIGNED], true)
                ? $sig->status : RmcpBoardSignature::STATUS_SENT,
            'sent_at'         => $sig->sent_at ?? now(),
            'last_sent_at'    => now(),
            'send_count'      => (int) $sig->send_count + 1,
            'last_send_error' => null,
        ])->save();

        return true;
    }

    // ── The member opens, proves who they are, signs ────────────────────────

    /** Record that the member opened the letter. Status moves to "viewed" once; every open is audited. */
    public function recordView(RmcpBoardSignature $sig, ?string $ip, ?string $userAgent): void
    {
        $firstTime = $sig->viewed_at === null;
        if ($firstTime && ! $sig->isSigned()) {
            $sig->forceFill(['viewed_at' => now(), 'status' => RmcpBoardSignature::STATUS_VIEWED])->save();
        }
        $this->auditFor($sig, 'viewed', null, $sig->member_name, $ip, $userAgent, ['first_time' => $firstTime]);
    }

    /** Email a six-digit code to the member's own address (the address on the signature line, never one typed on the page). */
    public function issueCode(RmcpBoardSignature $sig, ?string $ip, ?string $userAgent): ?string
    {
        $otp = app(OtpService::class);
        $blocked = $otp->throttle('rmcp_board:' . $sig->id, $sig->member_email);
        if ($blocked) {
            return $blocked === 'cooldown'
                ? 'A code was just sent. Please wait a moment before asking for another.'
                : 'Too many code requests. Please try again later.';
        }

        $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($sig->agency_id);
        $minutes = (int) config('otp.expires_minutes', 10);
        $otp->issue(self::OTP_PURPOSE, $sig->member_email, [
            'subject'    => $sig,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'audit'      => $this->otpSink($sig, $ip, $userAgent),
            // The agency's own code email, saying what the code opens - not the generic CoreX one.
            'mail'       => fn (string $code) => new \App\Mail\OtpMail($code, $minutes, 'Your code to open the board approval letter', null, $agency),
        ]);

        return null;
    }

    public function verifyCode(RmcpBoardSignature $sig, string $code, ?string $ip, ?string $userAgent): bool
    {
        return app(OtpService::class)->verify(self::OTP_PURPOSE, $sig->member_email, trim($code), [
            'audit' => $this->otpSink($sig, $ip, $userAgent),
        ]) !== null;
    }

    private function otpSink(RmcpBoardSignature $sig, ?string $ip, ?string $userAgent): callable
    {
        return function (string $event, ?Otp $otp, array $ctx) use ($sig, $ip, $userAgent) {
            $map = ['otp_issued' => 'code_sent', 'otp_verified' => 'code_verified', 'otp_failed' => 'code_failed', 'otp_attempts_exceeded' => 'code_failed'];
            if (isset($map[$event])) {
                $this->auditFor($sig, $map[$event], null, $sig->member_name, $ip, $userAgent, ['otp_event' => $event]);
            }
        };
    }

    /**
     * Sign the line. A drawn signature arrives as a PNG data URI, a typed one as the member's typed name. A line that is already
     * signed is never touched: a second attempt is refused.
     */
    public function sign(RmcpBoardSignature $sig, string $type, ?string $dataUri, ?string $typedName, string $via, ?string $ip, ?string $userAgent, ?User $actor = null): RmcpBoardSignature
    {
        if (! in_array($type, ['drawn', 'typed'], true)) {
            throw new \DomainException('Please draw or type your signature.');
        }

        $png = null;
        $typed = null;
        if ($type === 'drawn') {
            $png = base64_decode((string) preg_replace('/^data:image\/\w+;base64,/', '', (string) $dataUri), true);
            if ($png === false || strlen($png) < 100 || strlen($png) > 2_000_000 || ! str_starts_with($png, "\x89PNG")) {
                throw new \DomainException('The drawn signature could not be read. Please draw it again.');
            }
        } else {
            $typed = trim((string) $typedName);
            if ($typed === '') {
                throw new \DomainException('Please type your full name to sign.');
            }
        }

        $signed = DB::transaction(function () use ($sig, $type, $png, $typed, $via, $ip, $userAgent, $actor) {
            $row = RmcpBoardSignature::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->lockForUpdate()->findOrFail($sig->id);
            $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($row->letter_id);
            if ($row->isSigned()) {
                throw new \DomainException('This line has already been signed.');
            }
            if (! $letter->isOpen()) {
                throw new \DomainException('This letter is no longer open for signing.');
            }

            $path = null;
            if ($type === 'drawn') {
                $path = "rmcp/{$row->agency_id}/board-approval/{$letter->id}/{$row->id}-" . now()->format('Ymd-His') . '.png';
                Storage::disk('local')->put($path, $png);
            }

            $row->forceFill([
                'status'         => RmcpBoardSignature::STATUS_SIGNED,
                'signed_at'      => now(),
                'signature_type' => $type,
                'signature_path' => $path,
                'typed_name'     => $typed,
                'signed_via'     => $via,
                'ip_address'     => $ip,
                'user_agent'     => $userAgent ? Str::limit($userAgent, 480, '') : null,
                'viewed_at'      => $row->viewed_at ?? now(),
            ])->save();

            $version = RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->rmcp_version_id);
            $this->audit($version, $letter, $row, 'signed', $actor, $actor ? null : $row->member_name, $ip, ['via' => $via, 'type' => $type], $userAgent);

            return $row;
        });

        $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($signed->letter_id);
        if ($letter->signatures()->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('status', '!=', RmcpBoardSignature::STATUS_SIGNED)->doesntExist()) {
            $this->complete($letter);
        }

        return $signed->fresh();
    }

    // ── Completing: the signed PDF ──────────────────────────────────────────

    /** Everyone has signed: close the round and file the signed PDF. A printer failure leaves the round complete without a PDF (see regeneratePdf). */
    public function complete(RmcpBoardLetter $letter): void
    {
        $letter->update(['status' => RmcpBoardLetter::STATUS_COMPLETE, 'completed_at' => now()]);
        $version = RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->rmcp_version_id);
        $this->audit($version, $letter, null, 'letter_completed', null, 'system', null, []);
        $this->regeneratePdf($letter);
    }

    /** Print the signed letter to a new timestamped file (an earlier file is kept). Returns false when the printer failed. */
    public function regeneratePdf(RmcpBoardLetter $letter, ?User $actor = null): bool
    {
        $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->with('signatures', 'version')->findOrFail($letter->id);
        $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->agency_id);

        $images = [];
        foreach ($letter->signatures as $sig) {
            if ($sig->signature_type === 'drawn' && $sig->signature_path && Storage::disk('local')->exists($sig->signature_path)) {
                $images[$sig->id] = 'data:image/png;base64,' . base64_encode(Storage::disk('local')->get($sig->signature_path));
            }
        }

        $html = $this->pdf->boardLetterHtml($letter, $agency, $images);
        $bytes = $this->pdf->render(
            $html,
            ($agency->trading_name ?: $agency->name) . ' · RMCP v' . $letter->version?->version_number . ' board approval',
            'rmcp-board-' . $letter->id
        );
        if ($bytes === null) {
            return false;
        }

        $path = "rmcp/{$letter->agency_id}/board-approval/{$letter->id}/signed-letter-" . now()->format('Ymd-His') . '.pdf';
        Storage::disk('local')->put($path, $bytes);
        $letter->update(['signed_pdf_path' => $path, 'signed_pdf_sha256' => hash('sha256', $bytes)]);
        $this->audit(RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->findOrFail($letter->rmcp_version_id), $letter, null, 'pdf_filed', $actor, $actor ? null : 'system', null, ['sha256' => hash('sha256', $bytes)]);

        return true;
    }

    // ── What Approve needs to know ──────────────────────────────────────────

    /** The signed letter Approve can use: complete, filed as a PDF, and written about the text as it stands now. */
    public function usableLetter(RmcpVersion $version): ?RmcpBoardLetter
    {
        return $this->problemFor($version)['letter'];
    }

    /**
     * Why Approve cannot use a signed letter yet, in plain words (null when it can).
     *
     * @return array{letter:?RmcpBoardLetter, problem:?string}
     */
    public function problemFor(RmcpVersion $version): array
    {
        $latest = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('rmcp_version_id', $version->id)
            ->whereIn('status', [RmcpBoardLetter::STATUS_SENT, RmcpBoardLetter::STATUS_COMPLETE])
            ->orderByDesc('round')->first();

        if (! $latest) {
            return ['letter' => null, 'problem' => 'The board has not been asked to sign yet.'];
        }
        if ($latest->isOpen()) {
            $waiting = $latest->signatures()->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('status', '!=', RmcpBoardSignature::STATUS_SIGNED)->pluck('member_name')->all();

            return ['letter' => null, 'problem' => 'Still waiting for ' . implode(', ', $waiting) . ' to sign.'];
        }
        if (! hash_equals($latest->content_hash, $version->contentHash())) {
            return ['letter' => null, 'problem' => 'The RMCP was changed after the board signed. Send the letter again so the board signs the text as it is now.'];
        }
        if (! $latest->signed_pdf_path || ! Storage::disk('local')->exists($latest->signed_pdf_path)) {
            return ['letter' => null, 'problem' => 'Everyone has signed, but the signed PDF has not been created yet. Press "Create the PDF again".'];
        }

        return ['letter' => $latest, 'problem' => null];
    }

    /** Approve used this letter: link it and write the audit row. */
    public function recordApproval(RmcpVersion $version, ?RmcpBoardLetter $letter, User $actor, string $how): void
    {
        $this->audit($version, $letter, null, 'approved', $actor, null, request()->ip(), ['how' => $how], request()->userAgent());
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /**
     * Everything the Board approval panel on the RMCP page shows.
     *
     * @return array{members:\Illuminate\Support\Collection,letter:?RmcpBoardLetter,rounds:\Illuminate\Support\Collection,usable:?RmcpBoardLetter,problem:?string,stale:bool,linked:?RmcpBoardLetter,log:\Illuminate\Support\Collection}
     */
    public function panel(RmcpVersion $version): array
    {
        $this->seedDefaultsIfNeverConfigured((int) $version->agency_id);
        $rounds = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->with('signatures')->where('rmcp_version_id', $version->id)->orderByDesc('round')->get();
        $live = $rounds->first(fn ($l) => in_array($l->status, [RmcpBoardLetter::STATUS_SENT, RmcpBoardLetter::STATUS_COMPLETE], true));
        $check = $version->canBeEdited() ? $this->problemFor($version) : ['letter' => null, 'problem' => null];

        return [
            'members' => $this->members((int) $version->agency_id),
            'letter'  => $live,
            'rounds'  => $rounds,
            'usable'  => $check['letter'],
            'problem' => $check['problem'],
            'stale'   => $live !== null && $version->canBeEdited() && ! hash_equals($live->content_hash, $version->contentHash()),
            'linked'  => $version->board_letter_id ? $rounds->firstWhere('id', $version->board_letter_id) : null,
            'log'     => RmcpBoardAuditLog::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('rmcp_version_id', $version->id)->orderByDesc('id')->limit(12)->get(),
        ];
    }

    /** The open signature line for this logged-in user on this version, if they are on the board and have not signed. */
    public function openLineForUser(RmcpVersion $version, User $user): ?RmcpBoardSignature
    {
        $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('rmcp_version_id', $version->id)
            ->where('status', RmcpBoardLetter::STATUS_SENT)->orderByDesc('round')->first();
        if (! $letter) {
            return null;
        }

        return $letter->signatures()->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('status', '!=', RmcpBoardSignature::STATUS_SIGNED)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereRaw('LOWER(member_email) = ?', [Str::lower((string) $user->email)]))
            ->first();
    }

    /** Names, titles and the date the board signed - what the RMCP Approval block shows. */
    public function approvalFacts(RmcpBoardLetter $letter): array
    {
        $sigs = $letter->signatures()->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('status', RmcpBoardSignature::STATUS_SIGNED)->get();

        return [
            'names'  => $sigs->pluck('member_name')->implode(', '),
            'titles' => $sigs->pluck('member_title')->unique()->implode(', '),
            'date'   => ($letter->completed_at ?? $sigs->max('signed_at'))?->format('d F Y') ?? '',
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function activeAgencyUsers(int $agencyId)
    {
        return User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $agencyId)->where('is_active', true)
            ->where('is_assistant', false)->whereNull('deleted_at');
    }

    private function userIdForEmail(int $agencyId, string $email): ?int
    {
        return $this->activeAgencyUsers($agencyId)->whereRaw('LOWER(email) = ?', [Str::lower($email)])->value('id');
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(40);
        } while (RmcpBoardSignature::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->withTrashed()->where('token', $token)->exists());

        return $token;
    }

    private function auditFor(RmcpBoardSignature $sig, string $event, ?User $actor, ?string $label, ?string $ip, ?string $userAgent, array $meta): void
    {
        $letter = RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($sig->letter_id);
        if (! $letter) {
            return;
        }
        $version = RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($letter->rmcp_version_id);
        if ($version) {
            $this->audit($version, $letter, $sig, $event, $actor, $label, $ip, $meta, $userAgent);
        }
    }

    /** An audit write never breaks the real action. */
    private function audit(RmcpVersion $version, ?RmcpBoardLetter $letter, ?RmcpBoardSignature $sig, string $event, ?User $actor, ?string $label, ?string $ip, array $meta, ?string $userAgent = null): void
    {
        try {
            RmcpBoardAuditLog::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
                'agency_id'       => $version->agency_id,
                'rmcp_version_id' => $version->id,
                'letter_id'       => $letter?->id,
                'signature_id'    => $sig?->id,
                'event_type'      => $event,
                'actor_user_id'   => $actor?->id,
                'actor_label'     => $actor ? $actor->name : $label,
                'ip_address'      => $ip,
                'user_agent'      => $userAgent ? Str::limit($userAgent, 480, '') : null,
                'meta'            => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RMCP board audit write failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
