<?php

declare(strict_types=1);

namespace App\Services\SoloAgent;

use App\Events\SoloAgent\SoloAgentActivated;
use App\Events\SoloAgent\SoloAgentArchived;
use App\Events\SoloAgent\SoloAgentRestored;
use App\Events\SoloAgent\SoloAgentSuspended;
use App\Events\SoloAgent\SoloAgentUnsuspended;
use App\Models\Agency;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Models\SoloAgentSignup;
use App\Models\SoloAgentStatusEvent;
use App\Models\User;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Everything the CoreX team does to a single-agent account after sign-up:
 * send/resend the agreement, activate on signing, edit, suspend/unsuspend, archive/restore.
 * Spec: .ai/specs/solo-agent-accounts.md sections 4.2 and 6. Owner-only callers; no hard deletes anywhere.
 *
 * Every state change writes a line to the account's timeline (solo_agent_status_events) and emits a domain event (NN #9).
 */
class SoloAgentService
{
    public const TEMPLATE_KIND = 'solo_agent_agreement';

    public function __construct(private readonly EsignService $esign)
    {
    }

    /** Active single-agent agreement templates, newest first. */
    public function availableTemplates(): Collection
    {
        return Template::query()
            ->where('kind', self::TEMPLATE_KIND)
            ->where('is_active', true)
            ->where('source', '!=', 'webdoc')
            ->orderByDesc('id')
            ->get();
    }

    // ── Contract ───────────────────────────────────────────────────────────

    /**
     * Send the single-agent agreement to the agent, or - while it is still open - re-send it with fresh links.
     * The first signer role (lowest order) is the agent; any further role is signed by the CoreX user sending it.
     *
     * @throws \DomainException
     */
    public function sendContract(SoloAgentSignup $signup, int $actorUserId, ?int $templateId = null): Document
    {
        $this->assertOpen($signup);
        if ($signup->status === SoloAgentSignup::STATUS_ACTIVE) {
            throw new \DomainException('This agreement is already signed and the account is open.');
        }

        // Still-open agreement: new links, not a second contract.
        $existing = $signup->agreement_document_id ? Document::withoutGlobalScopes()->find($signup->agreement_document_id) : null;
        if ($existing && in_array($existing->status, ['sent', 'in_progress', 'expired'], true) && ! $existing->trashed()) {
            $this->esign->resend($existing, $actorUserId);
            $this->record($signup, 'contract_sent', $signup->status, $signup->status, $actorUserId, 'Agreement re-sent with fresh links');
            $signup->update(['contract_sent_at' => now()]);

            return $existing->fresh();
        }

        $templates = $this->availableTemplates();
        $tpl = $templateId ? $templates->firstWhere('id', $templateId) : $templates->first();
        if (! $tpl) {
            throw new \DomainException($templateId
                ? 'That agreement template is not available. Choose another.'
                : 'There is no single-agent agreement to send yet. Create one in Platform E-Sign first (Templates, type "Single-agent agreement").');
        }

        $agent  = $signup->user;
        $sender = User::withoutGlobalScopes()->find($actorUserId);
        if (! $agent || ! $sender) {
            throw new \DomainException('The agent or the sending user could not be found.');
        }

        $signers = [];
        foreach (array_values($tpl->roles()) as $i => $role) {
            $who = $i === 0 ? $agent : $sender;
            $signers[] = ['role_key' => $role['key'], 'name' => (string) $who->name, 'email' => (string) $who->email];
        }

        $doc = $this->esign->send($tpl, [
            'agency_id'  => $signup->agency_id,
            'title'      => 'Single-agent agreement — ' . $signup->agency->name,
            'sequential' => true,
            'signers'    => $signers,
        ], [], $actorUserId);
        $this->esign->inviteDue($doc, $actorUserId);

        $signup->update(['agreement_document_id' => $doc->id, 'contract_sent_at' => now()]);
        $this->record($signup, 'contract_sent', $signup->status, $signup->status, $actorUserId, 'Agreement "' . $tpl->name . '" sent to ' . $agent->email);

        return $doc;
    }

    /**
     * The agreement is fully signed: open the account. Idempotent (a replay, or a re-seal recovery, changes nothing),
     * and never opens an archived account.
     */
    public function activate(SoloAgentSignup $signup, ?int $documentId = null, ?int $actorUserId = null): bool
    {
        if ($signup->trashed() || $signup->status === SoloAgentSignup::STATUS_ACTIVE) {
            return false;
        }

        $user = $signup->user;
        DB::transaction(function () use ($signup, $user, $documentId) {
            $signup->update([
                'status'                => SoloAgentSignup::STATUS_ACTIVE,
                'contract_signed_at'    => now(),
                'agreement_document_id' => $documentId ?: $signup->agreement_document_id,
            ]);
            if ($user) {
                $user->forceFill(['is_active' => true, 'invited_at' => now()])->save();
            }
        });
        $this->record($signup, 'contract_signed', SoloAgentSignup::STATUS_AWAITING_CONTRACT, SoloAgentSignup::STATUS_ACTIVE, $actorUserId, 'Agreement signed - account opened');

        $this->sendSetupEmail($signup, $actorUserId, true);
        event(new SoloAgentActivated((int) $signup->id, (int) $signup->agency_id, $actorUserId));

        return true;
    }

    /**
     * Email the agent their password-setup link. Allowed only for an open account whose password is not yet set.
     *
     * @throws \DomainException
     */
    public function resendSetupEmail(SoloAgentSignup $signup, int $actorUserId): void
    {
        $this->assertOpen($signup);
        if ($signup->status !== SoloAgentSignup::STATUS_ACTIVE) {
            throw new \DomainException('The password-setup email goes out once the agreement is signed.');
        }
        if (! $signup->user?->isPendingInvite()) {
            throw new \DomainException('This agent has already set their password.');
        }
        if (! $this->sendSetupEmail($signup, $actorUserId, false)) {
            throw new \DomainException('The email could not be sent. Please try again in a moment.');
        }
    }

    private function sendSetupEmail(SoloAgentSignup $signup, ?int $actorUserId, bool $first): bool
    {
        $user = $signup->user;
        if (! $user) {
            return false;
        }
        try {
            Mail::to($user->email)->send(new \App\Mail\UserInviteMail($user));
            $this->record($signup, 'setup_email_sent', $signup->status, $signup->status, $actorUserId, ($first ? 'Password-setup email sent to ' : 'Password-setup email re-sent to ') . $user->email);

            return true;
        } catch (\Throwable $e) {
            // A mail hiccup must not undo an opened account; the owner can resend from the detail page.
            Log::error('Solo agent: password-setup email failed', ['signup_id' => $signup->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ── Details ────────────────────────────────────────────────────────────

    /**
     * @param array{agency_name:string,name:string,cell?:?string,trading_name?:?string,ffc_number:string,ffc_expires_on:string} $d
     */
    public function updateDetails(SoloAgentSignup $signup, array $d, int $actorUserId): void
    {
        $this->assertOpen($signup);

        DB::transaction(function () use ($signup, $d) {
            $agency = Agency::withoutGlobalScopes()->findOrFail($signup->agency_id);
            $agency->update(['name' => $d['agency_name'], 'trading_name' => ($d['trading_name'] ?? null) ?: null]);
            if ($signup->user) {
                $signup->user->update(['name' => $d['name'], 'cell' => $d['cell'] ?? null]);
            }
            $signup->update(['ffc_number' => $d['ffc_number'], 'ffc_expires_on' => $d['ffc_expires_on']]);
        });
        $this->record($signup, 'edited', $signup->displayStatus(), $signup->displayStatus(), $actorUserId, 'Details edited');
    }

    // ── Suspend / archive ──────────────────────────────────────────────────

    public function suspend(SoloAgentSignup $signup, int $actorUserId, ?string $reason = null): void
    {
        $this->assertOpen($signup);
        $from = $signup->displayStatus();
        if ($from === SoloAgentSignup::STATUS_SUSPENDED) {
            throw new \DomainException('This account is already suspended.');
        }
        $this->agency($signup)->suspend();
        $this->record($signup, 'suspended', $from, SoloAgentSignup::STATUS_SUSPENDED, $actorUserId, $reason);
        event(new SoloAgentSuspended((int) $signup->id, (int) $signup->agency_id, $actorUserId));
    }

    public function unsuspend(SoloAgentSignup $signup, int $actorUserId, ?string $reason = null): void
    {
        $this->assertOpen($signup);
        if ($signup->displayStatus() !== SoloAgentSignup::STATUS_SUSPENDED) {
            throw new \DomainException('This account is not suspended.');
        }
        $this->agency($signup)->unsuspend();
        $signup->unsetRelation('agency');
        $this->record($signup, 'unsuspended', SoloAgentSignup::STATUS_SUSPENDED, $signup->displayStatus(), $actorUserId, $reason);
        event(new SoloAgentUnsuspended((int) $signup->id, (int) $signup->agency_id, $actorUserId));
    }

    /**
     * Archive = soft delete: the sign-up is closed, the login is shut and billing stops. Nothing is erased.
     * An unsigned agreement still out is voided so it can no longer be signed.
     */
    public function archive(SoloAgentSignup $signup, int $actorUserId, ?string $reason = null): void
    {
        $this->assertOpen($signup);
        $from = $signup->displayStatus();
        $agency = $this->agency($signup);
        $wasSuspended = $agency->isSuspended();

        DB::transaction(function () use ($signup, $agency, $wasSuspended) {
            $signup->update(['suspended_before_archive' => $wasSuspended]);
            if (! $wasSuspended) {
                $agency->suspend();
            }
            // Raw update on purpose: the Last-Admin rule protects live agencies from being left admin-less, and an archived
            // single-agent account is meant to have no one signing in (same technique as the first-login claim).
            DB::table('users')->where('id', $signup->user_id)->update(['is_active' => false]);
            $signup->delete();
        });

        if ($signup->agreement_document_id) {
            try {
                $doc = Document::withoutGlobalScopes()->find($signup->agreement_document_id);
                if ($doc && in_array($doc->status, ['sent', 'in_progress', 'expired'], true)) {
                    $this->esign->void($doc, 'The single-agent account was archived', $actorUserId);
                }
            } catch (\Throwable $e) {
                Log::warning('Solo agent: could not void the open agreement on archive', ['signup_id' => $signup->id, 'error' => $e->getMessage()]);
            }
        }

        $this->record($signup, 'archived', $from, SoloAgentSignup::STATUS_ARCHIVED, $actorUserId, $reason);
        event(new SoloAgentArchived((int) $signup->id, (int) $signup->agency_id, $actorUserId));
    }

    public function restore(SoloAgentSignup $signup, int $actorUserId, ?string $reason = null): void
    {
        if (! $signup->trashed()) {
            throw new \DomainException('This account is not archived.');
        }
        $agency = $this->agency($signup);

        DB::transaction(function () use ($signup, $agency) {
            $signup->restore();
            if (! $signup->suspended_before_archive) {
                $agency->unsuspend();
            }
            // An account that never got to sign its agreement stays switched off until it does.
            if ($signup->status === SoloAgentSignup::STATUS_ACTIVE) {
                DB::table('users')->where('id', $signup->user_id)->update(['is_active' => true]);
            }
        });
        $signup->unsetRelation('agency');

        $this->record($signup, 'restored', SoloAgentSignup::STATUS_ARCHIVED, $signup->displayStatus(), $actorUserId, $reason);
        event(new SoloAgentRestored((int) $signup->id, (int) $signup->agency_id, $actorUserId));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function assertOpen(SoloAgentSignup $signup): void
    {
        if ($signup->trashed()) {
            throw new \DomainException('This account is archived. Restore it first.');
        }
    }

    private function agency(SoloAgentSignup $signup): Agency
    {
        return Agency::withoutGlobalScopes()->findOrFail($signup->agency_id);
    }

    private function record(SoloAgentSignup $signup, string $event, ?string $from, string $to, ?int $actorUserId, ?string $reason = null): void
    {
        SoloAgentStatusEvent::create([
            'signup_id'     => $signup->id,
            'from_status'   => $from,
            'to_status'     => $to,
            'event'         => $event,
            'actor_user_id' => $actorUserId,
            'reason'        => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
        ]);
    }
}
