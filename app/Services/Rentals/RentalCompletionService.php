<?php

namespace App\Services\Rentals;

use App\Events\Rentals\RentalCompletionResponded;
use App\Events\Rentals\RentalCompletionSettledBySilence;
use App\Events\Rentals\RentalWorkReportedDone;
use App\Jobs\Rentals\SendLandlordDisputeMailJob;
use App\Jobs\Rentals\SendTenantCompletionCheckMailJob;
use App\Mail\Rentals\RentalDisputeSentBackMail;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §17.10 — "work reported done" rounds and the
 * tenant check that follows (internal AND external work).
 *
 * One ROUND = one "work reported done" event. When work is reported done (crew link, crew page, signed copy, the
 * office's "Worker — done", or "Contractor reports done") a round opens and the tenant is told; the tenant can
 * confirm or say it is NOT complete, with photos — which puts the work order and the job card in the DISPUTED
 * stage until the work is reported done again (a new round). Silence for the agency's response window counts as
 * accepted. Every round is kept; none is ever deleted.
 *
 * Every action is a service method, so the office screens, the public response link, the portal API and a
 * future mobile app all go through the same rules (§13, §17.19).
 */
class RentalCompletionService
{
    /** §17.10.4 — a tenant can attach up to this many photos to a "not complete" answer. */
    public const MAX_DISPUTE_PHOTOS = 10;
    /** §17.10.4 — the shortest note that counts as "saying what is wrong". */
    public const MIN_DISPUTE_NOTE_LENGTH = 5;

    /** Round states a caller can ask for ({@see responseState()}). */
    public const STATE_OPEN = 'open';
    public const STATE_ANSWERED = 'answered';
    public const STATE_ENDED = 'ended';
    public const STATE_CLOSED = 'closed';

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Opening a round
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A round starts when work is reported done (§17.10.1). $report: reported_by_label, reported_via (a
     * RentalWorkCompletionRound::VIA_* value), reported_note?, reported_by_user_id?, rental_job_card_id?.
     *
     * - A report made while the tenant check of the SAME work is still open does not open a second round (the tenant
     *   is not asked twice for one job): the open round is returned unchanged (`wasRecentlyCreated` is false).
     * - A report made after a dispute opens the next round, stamps the old round's `dispute_resolved_at`, and returns
     *   the work order and job card to `in_progress` (§17.10.7).
     * - The tenant check follows the agency setting and who lives there (§17.10.2): `disabled` / `no_tenant` rounds
     *   are recorded as `outcome = no_tenant` and nothing waits; a tenant with no email leaves the round
     *   `awaiting_tenant` for the office to record the answer by phone.
     *
     * @param array<string, mixed> $report
     *
     * @throws \LogicException a cancelled work order cannot have work reported done
     */
    public function openRound(RentalWorkOrder $workOrder, array $report): RentalWorkCompletionRound
    {
        $via = (string) ($report['reported_via'] ?? RentalWorkCompletionRound::VIA_OFFICE);
        $label = trim((string) ($report['reported_by_label'] ?? '')) ?: 'The crew';
        $note = isset($report['reported_note']) && trim((string) $report['reported_note']) !== '' ? trim((string) $report['reported_note']) : null;
        $byUserId = isset($report['reported_by_user_id']) ? ((int) $report['reported_by_user_id'] ?: null) : null;

        $round = DB::transaction(function () use ($workOrder, $report, $via, $label, $note, $byUserId) {
            /** @var RentalWorkOrder $wo */
            $wo = RentalWorkOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($workOrder->id);
            if ($wo->status === RentalWorkOrder::STATUS_CANCELLED) {
                throw new \LogicException('This work order was cancelled — work cannot be reported done on it.');
            }

            $latest = RentalWorkCompletionRound::withoutGlobalScopes()
                ->where('rental_work_order_id', $wo->id)->orderByDesc('round_no')->first();
            if ($latest && $latest->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT && ! $wo->hasOpenDispute()) {
                // Same job reported done twice (e.g. the crew's link, then the paper copy): one check, not two.
                $wo->updates()->create([
                    'agency_id' => $wo->agency_id, 'update_type' => 'work_reported_done',
                    'note' => "{$label} reported the work done again ({$this->viaLabel($via)}) — round {$latest->round_no} is still waiting for the tenant",
                    'created_by_user_id' => $byUserId,
                ]);

                return $latest;
            }

            $card = $this->cardFor($wo, $report['rental_job_card_id'] ?? null);

            // §17.10.7 — reported done again after a dispute: close the disputed round and reopen the work.
            if ($latest && $latest->outcome === RentalWorkCompletionRound::OUTCOME_DISPUTED && $latest->dispute_resolved_at === null) {
                $latest->forceFill(['dispute_resolved_at' => now()])->save();
            }
            if ($wo->hasOpenDispute()) {
                $wo->returnFromDispute();
            }
            if ($card && $card->isDisputed()) {
                $card->returnFromDispute();
            }

            $tenantCheck = $this->evaluateTenantCheck($wo);
            $roundNo = (int) ($latest?->round_no ?? 0) + 1;

            $round = RentalWorkCompletionRound::withoutGlobalScopes()->create([
                'agency_id' => $wo->agency_id,
                'rental_work_order_id' => $wo->id,
                'rental_job_card_id' => $card?->id,
                'round_no' => $roundNo,
                'opened_at' => now(),
                'reported_by_label' => mb_substr($label, 0, 191),
                'reported_via' => $via,
                'reported_note' => $note,
                'reported_by_user_id' => $byUserId,
                'tenant_notify_status' => $tenantCheck['notify_status'],
                'window_ends_at' => $tenantCheck['window_ends_at'],
                'outcome' => $tenantCheck['outcome'],
            ]);

            $wo->updates()->create([
                'agency_id' => $wo->agency_id, 'update_type' => 'work_reported_done',
                'note' => "Round {$roundNo}: {$label} reported the work done ({$this->viaLabel($via)})"
                    . ($note ? " — {$note}" : '') . $this->tenantCheckNote($tenantCheck),
                'created_by_user_id' => $byUserId,
            ]);

            return $round;
        });

        if ($round->wasRecentlyCreated) {
            if ($round->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT
                && $round->tenant_notify_status !== RentalWorkCompletionRound::NOTIFY_NO_EMAIL) {
                SendTenantCompletionCheckMailJob::dispatch((int) $round->id)->afterCommit();
            }
            RentalWorkReportedDone::dispatch($round, $via, $byUserId);
        }

        return $round;
    }

    /** The job card this report is about: the explicit one, else the work order's own card. */
    private function cardFor(RentalWorkOrder $wo, mixed $cardId): ?RentalJobCard
    {
        $query = RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $wo->agency_id)->whereNull('deleted_at')->where('rental_work_order_id', $wo->id);

        return $cardId ? $query->find($cardId) : $query->orderByDesc('id')->first();
    }

    /**
     * §17.10.2 — what the tenant check does for this work order right now.
     *
     * @return array{notify_status: ?string, outcome: string, window_ends_at: ?\Illuminate\Support\Carbon}
     */
    private function evaluateTenantCheck(RentalWorkOrder $wo): array
    {
        if (! RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($wo->agency_id)) {
            return ['notify_status' => RentalWorkCompletionRound::NOTIFY_DISABLED, 'outcome' => RentalWorkCompletionRound::OUTCOME_NO_TENANT, 'window_ends_at' => null];
        }

        $tenants = $this->tenantContacts($wo);
        if ($tenants->isEmpty()) {
            return ['notify_status' => RentalWorkCompletionRound::NOTIFY_NO_TENANT, 'outcome' => RentalWorkCompletionRound::OUTCOME_NO_TENANT, 'window_ends_at' => null];
        }

        $windowEnds = now()->addDays(RentalWorkOrderSetting::completionResponseWindowDaysFor($wo->agency_id));
        if ($tenants->filter(fn (Contact $c) => $this->usableEmail($c) !== null)->isEmpty()) {
            return ['notify_status' => RentalWorkCompletionRound::NOTIFY_NO_EMAIL, 'outcome' => RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, 'window_ends_at' => $windowEnds];
        }

        // `null` until the (queued) mail job records `sent` or `failed`.
        return ['notify_status' => null, 'outcome' => RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, 'window_ends_at' => $windowEnds];
    }

    private function tenantCheckNote(array $check): string
    {
        return match ($check['notify_status']) {
            RentalWorkCompletionRound::NOTIFY_DISABLED => ' — the tenant check is switched off for this agency',
            RentalWorkCompletionRound::NOTIFY_NO_TENANT => ' — nobody is living at the property, so no tenant check',
            RentalWorkCompletionRound::NOTIFY_NO_EMAIL => " — the tenant has no email on file: record their answer by phone (answer due {$check['window_ends_at']->format('j M Y')})",
            default => $check['window_ends_at'] ? " — tenant asked to check (answer due {$check['window_ends_at']->format('j M Y')})" : '',
        };
    }

    private function viaLabel(string $via): string
    {
        return match ($via) {
            RentalWorkCompletionRound::VIA_CREW_LINK => 'crew link',
            RentalWorkCompletionRound::VIA_CREW_PAGE => 'crew page',
            RentalWorkCompletionRound::VIA_SIGNED_COPY => 'signed copy',
            RentalWorkCompletionRound::VIA_CONTRACTOR_CAPTURED => 'captured by the office from the contractor',
            default => 'recorded by the office',
        };
    }

    /**
     * The tenant contacts of the tenancy this work order belongs to (primary first). Empty for a vacancy period,
     * an archived tenancy, or a tenancy with nobody on it.
     *
     * @return Collection<int, Contact>
     */
    public function tenantContacts(RentalWorkOrder $wo): Collection
    {
        if (! $wo->lease_id) {
            return collect();
        }
        $lease = Lease::withoutGlobalScopes()->where('agency_id', $wo->agency_id)->whereNull('deleted_at')->find($wo->lease_id);
        if (! $lease) {
            return collect();
        }

        return \App\Models\LeaseTenant::query()->withoutGlobalScopes()
            ->where('lease_id', $lease->id)
            ->with(['contact' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('is_primary')->orderBy('id')
            ->get()
            ->map(fn ($t) => $t->contact)
            ->filter()
            ->values();
    }

    /** A real, sendable address — or null (blank / malformed addresses never get a mail). */
    public function usableEmail(Contact $contact): ?string
    {
        $email = trim((string) $contact->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // The tenant answers
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Is this round still waiting for an answer? `open` (answer it), `answered`, `ended` (the window passed — silence
     * counts as accepted), `closed` (no tenant check on it, or the work order was cancelled / archived).
     */
    public function responseState(RentalWorkCompletionRound $round): string
    {
        $wo = RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($round->rental_work_order_id);
        if (! $wo || $wo->trashed() || $wo->status === RentalWorkOrder::STATUS_CANCELLED) {
            return self::STATE_CLOSED;
        }

        return match ($round->outcome) {
            RentalWorkCompletionRound::OUTCOME_CONFIRMED, RentalWorkCompletionRound::OUTCOME_DISPUTED => self::STATE_ANSWERED,
            RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE => self::STATE_ENDED,
            RentalWorkCompletionRound::OUTCOME_NO_TENANT => self::STATE_CLOSED,
            default => ($round->window_ends_at && $round->window_ends_at->isPast()) ? self::STATE_ENDED : self::STATE_OPEN,
        };
    }

    /** The plain-language reason a round cannot be answered, or null when it can. */
    public function cannotRespondReason(RentalWorkCompletionRound $round, bool $byOffice = false): ?string
    {
        $state = $this->responseState($round);
        // The office may record a phone answer after the window ends but before the nightly settle has run.
        if ($byOffice && $state === self::STATE_ENDED && $round->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT) {
            return null;
        }

        return match ($state) {
            self::STATE_OPEN => null,
            self::STATE_ANSWERED => 'This check has already been answered.',
            self::STATE_ENDED => 'The response period has ended — please report a new fault.',
            default => 'This check is no longer open.',
        };
    }

    /**
     * The tenant (link / portal) or the office on their behalf answers a round (§17.10.4–§17.10.6).
     *
     * $actor: `['via' => 'link'|'portal'|'office_on_behalf', 'contact' => Contact?, 'user' => User?, 'ip' => ?]`.
     * `fixed = false` needs a note of at least {@see MIN_DISPUTE_NOTE_LENGTH} characters and accepts up to
     * {@see MAX_DISPUTE_PHOTOS} photos; it opens the dispute.
     *
     * @param array<int, UploadedFile> $photos
     * @param array<string, mixed> $actor
     *
     * @throws \InvalidArgumentException a missing or too-short note, too many photos
     * @throws \LogicException the round is not open for an answer
     */
    public function respond(RentalWorkCompletionRound $round, bool $fixed, ?string $note, array $photos, array $actor): void
    {
        $user = ($actor['user'] ?? null) instanceof User ? $actor['user'] : null;
        $contact = ($actor['contact'] ?? null) instanceof Contact ? $actor['contact'] : null;
        $via = (string) ($actor['via'] ?? ($user ? RentalWorkCompletionRound::RESPONDED_OFFICE_ON_BEHALF : RentalWorkCompletionRound::RESPONDED_PORTAL));
        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;
        $photos = array_values(array_filter($photos, fn ($p) => $p instanceof UploadedFile));

        if (! $fixed) {
            if ($note === null || mb_strlen($note) < self::MIN_DISPUTE_NOTE_LENGTH) {
                throw new \InvalidArgumentException('Please tell us what is still wrong (at least ' . self::MIN_DISPUTE_NOTE_LENGTH . ' characters).');
            }
            if (count($photos) > self::MAX_DISPUTE_PHOTOS) {
                throw new \InvalidArgumentException('You can attach up to ' . self::MAX_DISPUTE_PHOTOS . ' photos.');
            }
        } else {
            $photos = []; // a confirmation carries no photos
        }

        $disputedWorkOrder = null;
        $disputedCard = null;
        $dispute = DB::transaction(function () use ($round, $fixed, $note, $photos, $via, $user, $contact, $actor, &$disputedWorkOrder, &$disputedCard) {
            /** @var RentalWorkCompletionRound $locked */
            $locked = RentalWorkCompletionRound::withoutGlobalScopes()->lockForUpdate()->findOrFail($round->id);
            if ($reason = $this->cannotRespondReason($locked, $user !== null)) {
                throw new \LogicException($reason);
            }
            /** @var RentalWorkOrder $wo */
            $wo = RentalWorkOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->rental_work_order_id);
            $card = $this->cardFor($wo, $locked->rental_job_card_id);

            $who = $this->answerActorLabel($via, $contact, $user, $actor);
            $base = [
                'outcome' => $fixed ? RentalWorkCompletionRound::OUTCOME_CONFIRMED : RentalWorkCompletionRound::OUTCOME_DISPUTED,
                'responded_at' => now(),
                'responded_via' => $via,
                'responded_by_contact_id' => $contact?->id,
                'responded_by_user_id' => $user?->id,
                'response_note' => $note,
            ];

            if ($fixed) {
                $locked->forceFill($base)->save();
                $wo->mirrorCompletionAnswer(true, $note, $contact?->id);
                $wo->updates()->create([
                    'agency_id' => $wo->agency_id, 'update_type' => 'completion_response',
                    'note' => "Round {$locked->round_no}: {$who} confirmed the work is done" . ($note ? " — {$note}" : ''),
                    'created_by_user_id' => $user?->id,
                ]);

                return false;
            }

            $snapshot = [];
            $wo->markDisputed($note);
            if ($card && $card->status !== RentalJobCard::STATUS_CANCELLED) {
                $snapshot = $card->reopenForDispute($note);
                // §17.10.9 — closing a card killed its crew link; reopening must not quietly bring the old one back to life.
                // A fresh link is issued only by "Send back to crew" (or the agency's immediate-send setting).
                if (($snapshot['status_before'] ?? null) === RentalJobCard::STATUS_COMPLETED) {
                    app(RentalSecureAccessTokenService::class)->revokeAllFor($card);
                }
            }
            $locked->forceFill($base + ['sign_off_snapshot' => $snapshot ?: null])->save();
            $wo->mirrorCompletionAnswer(false, $note, $contact?->id);

            $wo->updates()->create([
                'agency_id' => $wo->agency_id, 'update_type' => 'completion_response',
                'note' => "Round {$locked->round_no}: {$who} said the work is NOT complete — {$note}"
                    . ($photos ? ' (' . count($photos) . ' photo' . (count($photos) === 1 ? '' : 's') . ')' : ''),
                'created_by_user_id' => $user?->id,
            ]);

            $this->storeDisputePhotos($wo, $card, $locked, $photos, $user);

            $disputedWorkOrder = $wo;
            $disputedCard = $card;

            return true;
        });

        $round->refresh();
        RentalCompletionResponded::dispatch($round, $round->outcome, $via, $user?->id);

        $wo = $disputedWorkOrder ?? RentalWorkOrder::withoutGlobalScopes()->find($round->rental_work_order_id);
        if (! $wo) {
            return;
        }

        if (! $dispute) {
            $this->notifyStaff($wo, 'rental_work_order.completion_confirmed', 'Tenant confirmed the work is done — ' . $this->addressFor($wo), $wo->title);

            return;
        }

        // §17.10.6 — the office is told first (the agent and the branch manager); the owner per the agency setting.
        $this->notifyStaff(
            $wo, 'rental_work_order.disputed',
            'Tenant says the work is NOT complete — ' . $this->addressFor($wo),
            mb_substr((string) $round->response_note, 0, 240),
            alsoBranchManager: true,
        );
        if (RentalWorkOrderSetting::notifyLandlordOnDisputeFor($wo->agency_id)) {
            SendLandlordDisputeMailJob::dispatch((int) $round->id);
        }
        // §17.22 Decision 3 — by default the office looks first and presses "Send back"; an agency may switch
        // on an immediate send to the crew (an external contractor is always sent back by the office).
        if ($disputedCard && RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($wo->agency_id)) {
            try {
                $this->performSendBack($wo, null);
            } catch (\Throwable $e) {
                Log::warning('Immediate dispute send-back to the crew failed', ['work_order_id' => $wo->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function answerActorLabel(string $via, ?Contact $contact, ?User $user, array $actor): string
    {
        $ip = trim((string) ($actor['ip'] ?? '')) !== '' ? ' (IP ' . $actor['ip'] . ')' : '';

        return match ($via) {
            RentalWorkCompletionRound::RESPONDED_OFFICE_ON_BEHALF => ($user?->name ?: 'The office') . ' recorded the tenant\'s answer',
            RentalWorkCompletionRound::RESPONDED_PORTAL => ($contact?->full_name ?: 'The tenant') . ' (portal)',
            default => 'The tenant (response link)' . $ip,
        };
    }

    /** @param array<int, UploadedFile> $photos */
    private function storeDisputePhotos(RentalWorkOrder $wo, ?RentalJobCard $card, RentalWorkCompletionRound $round, array $photos, ?User $user): void
    {
        $storer = app(PropertyImageStorer::class);
        foreach ($photos as $file) {
            RentalWorkOrderPhoto::withoutGlobalScopes()->create([
                'agency_id' => $wo->agency_id,
                'rental_work_order_id' => $wo->id,
                'rental_job_card_id' => $card?->id,
                'rental_completion_round_id' => $round->id,
                'photo_type' => RentalWorkOrder::PHOTO_DISPUTE,
                'uploaded_via' => $user ? RentalWorkOrderPhoto::VIA_OFFICE : RentalWorkOrderPhoto::VIA_TENANT,
                'storage_path' => $storer->store($file, $wo->property_id),
                'uploaded_by_user_id' => $user?->id,
                'file_size_bytes' => $file->getSize(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Send it back
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The office sends a disputed job back to the crew / contractor (fresh link + the tenant's note and photos). */
    public function sendBack(RentalWorkOrder $workOrder, User $by): void
    {
        $this->performSendBack($workOrder, $by);
    }

    /**
     * Same as {@see sendBack()}, but tells the screen what happened.
     *
     * @return array{kind: string, to: ?string, link_url: ?string, emailed: bool, message: string}
     */
    public function sendBackWithResult(RentalWorkOrder $workOrder, User $by): array
    {
        return $this->performSendBack($workOrder, $by);
    }

    /**
     * Internal job: issue a fresh per-job crew link (the old one is replaced) and email it with the tenant's note and
     * photos to the crew's address. External job: email the contractor the note and photos (no link — contractor links
     * are not minted by any screen, §17.9.7). $by null = the automatic send of `dispute_notify_crew_immediately`.
     *
     * @return array{kind: string, to: ?string, link_url: ?string, emailed: bool, message: string}
     */
    private function performSendBack(RentalWorkOrder $workOrder, ?User $by): array
    {
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($workOrder->id);
        if (! $wo->hasOpenDispute()) {
            throw new \LogicException('Only a work order the tenant has reported as not complete can be sent back.');
        }

        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)
            ->where('outcome', RentalWorkCompletionRound::OUTCOME_DISPUTED)->orderByDesc('round_no')->first();
        $photos = $round
            ? RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->orderBy('id')->get()
            : collect();
        $agent = $by ?? $this->agentFor($wo);
        $card = $wo->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL ? $this->cardFor($wo, null) : null;

        if ($card) {
            return $this->sendBackToCrew($wo, $card, $round, $photos, $agent, $by);
        }

        return $this->sendBackToContractor($wo, $round, $photos, $agent, $by);
    }

    private function sendBackToCrew(RentalWorkOrder $wo, RentalJobCard $card, ?RentalWorkCompletionRound $round, Collection $photos, ?User $agent, ?User $by): array
    {
        $linkUrl = null;
        if (RentalPortalSetting::crewLinksEnabledFor($card->agency_id) && ! $card->isClosed()) {
            $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $by);
            $linkUrl = route('rentals.crew-job.show', $issued['raw_token']);
        }

        $to = $card->crew?->email ? trim((string) $card->crew->email) : null;
        $emailed = false;
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            try {
                app(RentalMailDispatcher::class)->send($to, new RentalDisputeSentBackMail(
                    $wo, $card->crew?->name, (string) $round?->response_note, $photos->pluck('storage_path')->all(), $linkUrl, $agent,
                ));
                $emailed = true;
            } catch (\Throwable $e) {
                Log::warning('Dispute send-back email to the crew failed', ['work_order_id' => $wo->id, 'error' => $e->getMessage()]);
            }
        }

        $message = $emailed
            ? "Sent back to the crew ({$to}) with the tenant's note" . ($photos->isNotEmpty() ? ' and photos' : '') . ($linkUrl ? ' and a fresh link.' : '.')
            : (($to ? 'The email to the crew could not be sent.' : 'The crew has no email address on file.')
                . ($linkUrl ? ' A fresh crew link was created — copy it below and send it another way.' : ' Crew links are switched off for this agency, so no link was created.'));

        $note = $emailed ? "Sent back to the crew ({$to}) with the tenant's note" . ($linkUrl ? ' and a fresh link' : '') : 'Send-back prepared — ' . $message;
        $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'dispute_sent_back', 'note' => $note, 'created_by_user_id' => $by?->id]);
        $card->logUpdate('dispute_sent_back', $by, $note);

        return ['kind' => 'crew', 'to' => $to, 'link_url' => $linkUrl, 'emailed' => $emailed, 'message' => $message];
    }

    private function sendBackToContractor(RentalWorkOrder $wo, ?RentalWorkCompletionRound $round, Collection $photos, ?User $agent, ?User $by): array
    {
        $provider = $wo->supplier()->with('serviceContacts')->first();
        $to = $provider ? ($provider->serviceContacts->first()?->email ?: $provider->email) : null;
        $to = $to ? trim((string) $to) : null;

        $emailed = false;
        if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            try {
                app(RentalMailDispatcher::class)->send($to, new RentalDisputeSentBackMail(
                    $wo, $provider?->name, (string) $round?->response_note, $photos->pluck('storage_path')->all(), null, $agent,
                ));
                $emailed = true;
            } catch (\Throwable $e) {
                Log::warning('Dispute send-back email to the contractor failed', ['work_order_id' => $wo->id, 'error' => $e->getMessage()]);
            }
        }

        $message = $emailed
            ? "Sent back to the contractor ({$to}) with the tenant's note" . ($photos->isNotEmpty() ? ' and photos.' : '.')
            : ($to ? 'The email to the contractor could not be sent.' : 'There is no contractor email on file — contact them directly.');
        $note = $emailed ? "Sent back to the contractor ({$to}) with the tenant's note" : 'Send-back not emailed — ' . $message;
        $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'dispute_sent_back', 'note' => $note, 'created_by_user_id' => $by?->id]);

        return ['kind' => 'contractor', 'to' => $to, 'link_url' => null, 'emailed' => $emailed, 'message' => $message];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Contractor reports done (external work, §17.9.6)
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * "Contractor reports done": the agent captures that an outside contractor has finished — date, how they said so,
     * a note, and any photos the contractor sent. Opens a round (§17.10.1) and keeps the work order `in_progress`.
     * It does not close the work order — the agent's Complete form still does that.
     *
     * @param array{date_done?: ?string, reported_via?: ?string, note?: ?string} $data
     * @param array<int, UploadedFile> $photos
     *
     * @throws \LogicException work cannot be reported done on this work order
     */
    public function recordContractorDone(RentalWorkOrder $workOrder, array $data, User $by, array $photos = []): RentalWorkCompletionRound
    {
        if ($workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            throw new \LogicException('This work is done by the maintenance crew — it is reported through the job card.');
        }
        if (! in_array($workOrder->status, [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_DISPUTED], true)) {
            throw new \LogicException(match ($workOrder->status) {
                RentalWorkOrder::STATUS_REPORTED => 'The work order has not been given to a contractor yet — assign the supplier first.',
                RentalWorkOrder::STATUS_COMPLETED => 'This work order is already completed.',
                default => 'This work order is cancelled.',
            });
        }

        $how = (string) ($data['reported_via'] ?? 'other');
        $howLabel = ['phone' => 'phone', 'whatsapp' => 'WhatsApp', 'email' => 'email', 'in_person' => 'in person', 'other' => 'another way'][$how] ?? 'another way';
        $dateDone = ! empty($data['date_done']) ? \Illuminate\Support\Carbon::parse($data['date_done']) : now();
        $contractor = $workOrder->supplier?->name ?: 'The contractor';
        $note = trim((string) ($data['note'] ?? ''));
        $reportNote = 'Done on ' . $dateDone->format('j M Y') . ", told to the office by {$howLabel}" . ($note !== '' ? " — {$note}" : '');

        return DB::transaction(function () use ($workOrder, $by, $photos, $contractor, $reportNote) {
            $round = $this->openRound($workOrder, [
                'reported_by_label' => $contractor,
                'reported_via' => RentalWorkCompletionRound::VIA_CONTRACTOR_CAPTURED,
                'reported_note' => $reportNote,
                'reported_by_user_id' => $by->id,
            ]);

            $wo = RentalWorkOrder::findOrFail($workOrder->id);
            if ($wo->status === RentalWorkOrder::STATUS_ORDERED) {
                $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
                $wo->updates()->create([
                    'agency_id' => $wo->agency_id, 'update_type' => 'status_change',
                    'from_status' => RentalWorkOrder::STATUS_ORDERED, 'to_status' => RentalWorkOrder::STATUS_IN_PROGRESS,
                    'note' => 'The contractor reported the work done', 'created_by_user_id' => $by->id,
                ]);
            }

            $service = app(RentalWorkOrderService::class);
            foreach (array_values(array_filter($photos, fn ($p) => $p instanceof UploadedFile)) as $file) {
                $photo = $service->storePhoto($wo, $file, RentalWorkOrder::PHOTO_COMPLETED, $by);
                $photo->forceFill(['uploaded_via' => RentalWorkOrderPhoto::VIA_OFFICE])->save();
            }

            return $round;
        });
    }

    /**
     * W6 (8 Oct 2026) - the owner tells us from the portal that the work is done. Exactly the office's "contractor reports
     * done": the same round, the same tenant check, the same hand-over to the office to close the work order.
     *
     * @throws \LogicException the work order is not at a stage where work can be reported done
     */
    public function recordOwnerReportedDone(RentalWorkOrder $workOrder, Contact $owner, ?string $note = null): RentalWorkCompletionRound
    {
        if ($workOrder->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL) {
            throw new \LogicException("This work is done by the agency's own team - it is reported through the job card.");
        }
        if (! in_array($workOrder->status, [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_DISPUTED], true)) {
            throw new \LogicException(match ($workOrder->status) {
                RentalWorkOrder::STATUS_REPORTED => 'The work has not been given to a contractor yet.',
                RentalWorkOrder::STATUS_COMPLETED => 'This work order is already completed.',
                default => 'This work order is cancelled.',
            });
        }

        $name = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
        $contractor = $workOrder->isOwnerContractor() ? ($workOrder->contractor_name ?: "The owner's contractor") : ($workOrder->supplier?->name ?: 'The contractor');
        $note = trim((string) $note);

        return DB::transaction(function () use ($workOrder, $contractor, $name, $note) {
            $round = $this->openRound($workOrder, [
                'reported_by_label' => $contractor,
                'reported_via' => RentalWorkCompletionRound::VIA_OWNER_PORTAL,
                'reported_note' => 'Told to us by the owner' . ($name !== '' ? " ({$name})" : '') . ' on the portal' . ($note !== '' ? " - {$note}" : ''),
            ]);

            $wo = RentalWorkOrder::findOrFail($workOrder->id);
            if ($wo->status === RentalWorkOrder::STATUS_ORDERED) {
                $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
                $wo->updates()->create([
                    'agency_id' => $wo->agency_id, 'update_type' => 'status_change',
                    'from_status' => RentalWorkOrder::STATUS_ORDERED, 'to_status' => RentalWorkOrder::STATUS_IN_PROGRESS,
                    'note' => 'The owner reported the work done',
                ]);
            }

            return $round;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Silence = accepted
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Daily (`rentals:settle-completion-rounds`): every `awaiting_tenant` round past its window becomes
     * `accepted_by_silence` (§17.10.8). Never touches a disputed round. Returns how many were settled.
     */
    public function settleSilent(): int
    {
        $settled = 0;

        RentalWorkCompletionRound::withoutGlobalScopes()
            ->where('outcome', RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT)
            ->whereNotNull('window_ends_at')
            ->where('window_ends_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($rounds) use (&$settled) {
                foreach ($rounds as $round) {
                    // Claim it with ONE conditional update, so an answer arriving at the same moment wins.
                    $claimed = RentalWorkCompletionRound::withoutGlobalScopes()->whereKey($round->id)
                        ->where('outcome', RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT)
                        ->update(['outcome' => RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE, 'updated_at' => now()]);
                    if ($claimed === 0) {
                        continue;
                    }
                    $settled++;

                    $round->refresh();
                    $wo = RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($round->rental_work_order_id);
                    if (! $wo) {
                        continue;
                    }
                    $days = max(1, (int) round($round->opened_at->diffInDays($round->window_ends_at, true)));
                    $wo->updates()->create([
                        'agency_id' => $wo->agency_id, 'update_type' => 'completion_accepted',
                        'note' => "Round {$round->round_no}: no response in {$days} day" . ($days === 1 ? '' : 's') . ' — accepted',
                    ]);
                    RentalCompletionSettledBySilence::dispatch($round);
                    if (! $wo->trashed() && $wo->status !== RentalWorkOrder::STATUS_CANCELLED) {
                        $this->notifyStaff($wo, 'rental_work_order.completion_accepted', 'No tenant response — work accepted — ' . $this->addressFor($wo), $wo->title);
                    }
                }
            });

        return $settled;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** The user who should hear about / send mail for this work order: the property's agent, else the creator. */
    public function agentFor(RentalWorkOrder $wo): ?User
    {
        $property = Property::withoutGlobalScopes()->withTrashed()->find($wo->property_id);
        $id = $property?->agent_id ?: $wo->created_by_user_id;

        return $id ? User::withoutGlobalScopes()->find($id) : null;
    }

    private function addressFor(RentalWorkOrder $wo): string
    {
        $property = Property::withoutGlobalScopes()->withTrashed()->find($wo->property_id);

        return $property?->buildDisplayAddress() ?: ($property?->title ?: ('Property #' . $wo->property_id));
    }

    /** In-app staff notification (§17.16) to the property's agent — and the branch manager when asked. */
    private function notifyStaff(RentalWorkOrder $wo, string $eventKey, string $title, string $body, bool $alsoBranchManager = false): void
    {
        $recipients = collect();
        if ($agent = $this->agentFor($wo)) {
            $recipients->push($agent);
        }
        if ($alsoBranchManager && $wo->branch_id) {
            $manager = User::query()->withoutGlobalScopes()
                ->where('agency_id', $wo->agency_id)->where('branch_id', $wo->branch_id)
                ->where('role', 'branch_manager')->where('is_active', true)->whereNull('deleted_at')
                ->orderBy('id')->first();
            if ($manager) {
                $recipients->push($manager);
            }
        }

        foreach ($recipients->unique('id') as $user) {
            try {
                app(NotificationDispatcher::class)->fire($user, $eventKey, $wo, [
                    'title' => $title,
                    'body' => $body,
                    'action_url' => route('corex.rental-work-orders.show', $wo->id),
                    'severity' => $eventKey === 'rental_work_order.disputed' ? 'warning' : 'info',
                    'threshold_hit_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Maintenance-flow staff notification failed', ['event' => $eventKey, 'work_order_id' => $wo->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
