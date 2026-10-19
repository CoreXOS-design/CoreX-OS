<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalJobOnHoldMail;
use App\Mail\Rentals\RentalOwnerNoticeToAgentMail;
use App\Mail\Rentals\RentalOwnerQueryAnswerMail;
use App\Models\Contact;
use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalJobCard;
use App\Models\RentalJobDispatch;
use App\Models\RentalOwnerHold;
use App\Models\RentalOwnerQuery;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.7 / 22.12a - "Stop this" and "Query this".
 *
 * ONE place that decides whether a job is PAUSED BY OWNER, whether the owner can still stop it (the work has not started), and that writes the hold, the
 * resume, the owner's questions and the agents' answers. Every guard site asks {@see holdReason()} - stage 2 (cost approval / emergency) must too:
 * `if ($reason = app(RentalOwnerHoldService::class)->holdReason($subject)) { refuse with $reason }` before any approve / send / start action.
 */
class RentalOwnerHoldService
{
    public const EV_HOLD = 'rental_fault_report.owner_hold';
    /** Audit C-B4: lifting the hold is its own alert type (it shared EV_HOLD and the resume alert was swallowed within the cooldown of the stop). */
    public const EV_RESUMED = 'rental_fault_report.owner_resumed';
    public const EV_QUERY = 'rental_fault_report.owner_query';

    public function __construct(private RentalAuthorisationService $authorisations) {}

    // ───────────────────────── reading ─────────────────────────

    /** The fault and the work order of a job, whichever one was given. @return array{0: ?RentalFaultReport, 1: ?RentalWorkOrder} */
    public function jobOf(RentalFaultReport|RentalWorkOrder|RentalJobCard $subject): array
    {
        if ($subject instanceof RentalJobCard) {
            $subject = $subject->rental_work_order_id ? RentalWorkOrder::withoutGlobalScopes()->find($subject->rental_work_order_id) : null;
            if (! $subject) {
                return [null, null];
            }
        }
        if ($subject instanceof RentalFaultReport) {
            $wo = $subject->rental_work_order_id ? RentalWorkOrder::withoutGlobalScopes()->whereNull('deleted_at')->find($subject->rental_work_order_id) : null;

            return [$subject, $wo];
        }
        $fault = $subject->reported_fault_report_id ? RentalFaultReport::withoutGlobalScopes()->find($subject->reported_fault_report_id) : null;

        return [$fault, $subject];
    }

    /** The active hold on this job (null = not paused). */
    public function activeFor(RentalFaultReport|RentalWorkOrder|RentalJobCard $subject): ?RentalOwnerHold
    {
        [$fault, $wo] = $this->jobOf($subject);
        if (! $fault && ! $wo) {
            return null;
        }

        return RentalOwnerHold::withoutGlobalScopes()->whereNull('resumed_at')
            ->where(function ($q) use ($fault, $wo) {
                if ($fault) {
                    $q->orWhere('rental_fault_report_id', $fault->id);
                }
                if ($wo) {
                    $q->orWhere('rental_work_order_id', $wo->id);
                }
            })->orderByDesc('id')->first();
    }

    /** Every hold ever made on this job, oldest first. */
    public function historyFor(RentalFaultReport|RentalWorkOrder $subject): Collection
    {
        [$fault, $wo] = $this->jobOf($subject);

        return RentalOwnerHold::withoutGlobalScopes()->with(['stoppedBy', 'resumedByUser', 'resumedByContact'])
            ->where(function ($q) use ($fault, $wo) {
                if ($fault) {
                    $q->orWhere('rental_fault_report_id', $fault->id);
                }
                if ($wo) {
                    $q->orWhere('rental_work_order_id', $wo->id);
                }
            })->orderBy('id')->get();
    }

    private function tz(?RentalFaultReport $fault, ?RentalWorkOrder $wo): string
    {
        $agency = ($fault ?? $wo)?->agency;

        return \App\Support\AppDate::agencyTimezone($agency);
    }

    public function ownerName(?Contact $c): string
    {
        $n = $c ? trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) : '';

        return $n !== '' ? $n : 'the owner';
    }

    /** "Stopped by owner <name> on <date time>: <reason>" - the line the authorisation records and the history carry. */
    public function stopLine(RentalOwnerHold $hold): string
    {
        $tz = $this->tz($hold->faultReport, $hold->workOrder);

        return 'Stopped by owner ' . $this->ownerName($hold->stoppedBy) . ' on ' . $hold->stopped_at->copy()->setTimezone($tz)->format('j M Y H:i') . ': ' . $hold->reason;
    }

    public function resumeLine(RentalOwnerHold $hold): string
    {
        $tz = $this->tz($hold->faultReport, $hold->workOrder);
        $when = $hold->resumed_at?->copy()->setTimezone($tz)->format('j M Y H:i');
        $who = $hold->resume_basis === RentalOwnerHold::RESUME_OWNER_LINK
            ? 'by the owner, ' . $this->ownerName($hold->resumedByContact) . ', who said go ahead after all'
            : 'by agent ' . ($hold->resumedByUser?->name ?? 'an agent') . ' on the owner\'s instruction';

        return "Resumed on {$when} {$who}.";
    }

    /** The plain sentence every blocked action shows, or null when the job is not paused. */
    public function holdReason(RentalFaultReport|RentalWorkOrder|RentalJobCard $subject): ?string
    {
        $hold = $this->activeFor($subject);
        if (! $hold) {
            return null;
        }
        $tz = $this->tz($hold->faultReport, $hold->workOrder);

        return 'PAUSED BY OWNER on ' . $hold->stopped_at->copy()->setTimezone($tz)->format('j M Y H:i') . ' - ' . $this->ownerName($hold->stoppedBy) . ' said: "' . $hold->reason
            . '". Nothing can be sent, approved or started until the owner says go ahead.';
    }

    public function assertNotOnHold(RentalFaultReport|RentalWorkOrder|RentalJobCard $subject): void
    {
        if ($reason = $this->holdReason($subject)) {
            throw new \LogicException($reason);
        }
    }

    /**
     * Has the work started? There is no separate "contractor on site" record: the markers are the work order being in progress (or disputed / completed) or its
     * job card being in progress / signed off / completed / disputed.
     */
    public function workStarted(RentalFaultReport|RentalWorkOrder $subject): bool
    {
        [, $wo] = $this->jobOf($subject);
        if (! $wo) {
            return false;
        }
        if (in_array($wo->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_DISPUTED, RentalWorkOrder::STATUS_COMPLETED], true)) {
            return true;
        }
        $card = RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->whereNull('deleted_at')->orderByDesc('id')->first();

        return (bool) ($card && ($card->worker_signed_off_at || in_array($card->status, [RentalJobCard::STATUS_IN_PROGRESS, RentalJobCard::STATUS_COMPLETED, RentalJobCard::STATUS_DISPUTED], true)));
    }

    /** Why the owner cannot stop this job right now, or null when they can. 'started' | 'closed' | 'held' (a code, for the screens), with the plain words. */
    public function stopBlock(RentalFaultReport|RentalWorkOrder $subject): ?array
    {
        [$fault, $wo] = $this->jobOf($subject);
        if (! $fault && ! $wo) {
            return ['code' => 'closed', 'text' => 'There is nothing to stop.'];
        }
        if ($this->activeFor($subject)) {
            return ['code' => 'held', 'text' => 'This is already on hold.'];
        }
        if ($this->workStarted($subject)) {
            return ['code' => 'started', 'text' => 'Work has started - contact your agent'];
        }
        $closed = ($fault && in_array($fault->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED, RentalFaultReport::STATUS_DECLINED], true))
            || ($wo && in_array($wo->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true));
        if ($closed) {
            return ['code' => 'closed', 'text' => 'This job is closed, so there is nothing to stop.'];
        }

        return null;
    }

    // ───────────────────────── Stop this ─────────────────────────

    /**
     * The owner pressed "Stop this" and confirmed it. @return array{result:'stopped'|'already'|'started'|'closed', hold: ?RentalOwnerHold}
     *
     * @throws \InvalidArgumentException a missing / too short / too long reason, or a person who is not an owner of this property
     */
    public function stop(RentalFaultReport|RentalWorkOrder $subject, Contact $owner, string $reason, string $via): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \InvalidArgumentException('Please say why - a short reason is required.');
        }
        if (mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Please keep the reason to 500 characters.');
        }
        [$fault, $wo] = $this->jobOf($subject);
        if (! $fault && ! $wo) {
            throw new \InvalidArgumentException('That job could not be found.');
        }
        $this->assertIsOwner($fault ?? $wo, $owner);

        $hold = null;
        $result = DB::transaction(function () use ($fault, $wo, $owner, $reason, $via, &$hold) {
            // the lock is taken on the fault (else the work order): a press racing the contractor's first start, or a second press, sees the real state
            if ($fault) {
                RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->find($fault->id);
            } else {
                RentalWorkOrder::withoutGlobalScopes()->lockForUpdate()->find($wo->id);
            }
            $subjectNow = $fault ?? $wo;
            if ($this->activeFor($subjectNow)) {
                return 'already';
            }
            $block = $this->stopBlock($subjectNow);
            if ($block) {
                return $block['code'] === 'started' ? 'started' : 'closed';
            }
            $stage = $fault?->status ?? $wo?->status;
            $standing = $fault ? ($this->authorisations->activeFor($fault, RentalAuthorisation::POINT_COST) ?? $this->authorisations->activeFor($fault, RentalAuthorisation::POINT_INSPECT)) : null;
            $hold = RentalOwnerHold::withoutGlobalScopes()->create([
                'agency_id' => ($fault ?? $wo)->agency_id, 'rental_fault_report_id' => $fault?->id, 'rental_work_order_id' => $wo?->id,
                'rental_authorisation_id' => $standing?->id, 'stopped_by_contact_id' => $owner->id, 'stopped_via' => $via === RentalOwnerHold::VIA_LINK ? RentalOwnerHold::VIA_LINK : RentalOwnerHold::VIA_PORTAL,
                'reason' => $reason, 'stage_when_stopped' => $stage, 'stopped_at' => now(),
            ]);
            $hold->load('stoppedBy');
            $line = $this->stopLine($hold);
            $this->log($fault, $wo, RentalFaultReportUpdate::TYPE_OWNER_STOPPED, null, $line, 'owner_stopped');

            return 'stopped';
        });

        if ($result !== 'stopped') {
            return ['result' => $result, 'hold' => $this->activeFor($fault ?? $wo)];
        }

        // outside the transaction: a mail problem never undoes the stop
        $this->tellContractorAndCrew($hold, $fault, $wo);
        $property = ($fault ?? $wo)->property;
        $addr = $property?->buildDisplayAddress() ?: ('Property #' . ($fault ?? $wo)->property_id);
        $title = 'The owner stopped a repair - ' . $addr;
        $body = $this->ownerName($owner) . ' pressed "Stop this" on "' . $this->jobTitle($fault, $wo) . '": ' . $reason . '. The job is on hold: nothing can be sent, approved or started until the owner says go ahead. '
            . ($hold->contractor_told_note ? $hold->contractor_told_note . ' ' : '') . 'Record the owner\'s decision to resume it.';
        $this->tellAgents($fault, $wo, self::EV_HOLD, $title, $body, 'warning');

        return ['result' => 'stopped', 'hold' => $hold->fresh()];
    }

    /** Which contractor / crew had the job emailed, and the "On hold" email to them. A contractor reached only by WhatsApp cannot be emailed - the agents are told to phone. */
    private function tellContractorAndCrew(RentalOwnerHold $hold, ?RentalFaultReport $fault, ?RentalWorkOrder $wo): void
    {
        if (! $wo) {
            return;
        }
        try {
            $rows = RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->orderBy('id')->get()->filter(fn ($r) => $r->counted());
            $emails = $rows->where('channel', RentalJobDispatch::CHANNEL_EMAIL)->filter(fn ($r) => filter_var((string) $r->destination, FILTER_VALIDATE_EMAIL))
                ->unique(fn ($r) => strtolower((string) $r->destination));
            $phoneOnly = $rows->where('channel', RentalJobDispatch::CHANNEL_WHATSAPP)
                ->reject(fn ($r) => $emails->contains(fn ($e) => $e->recipient_kind === $r->recipient_kind && $e->recipient_name === $r->recipient_name));
            $told = [];
            foreach ($emails as $r) {
                Mail::to($r->destination)->send(new RentalJobOnHoldMail($r->recipient_name ?: 'there', $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id), $this->jobTitle($fault, $wo), \App\Models\Agency::displayNameFor($wo->agency, '')));
                $told[] = $r->recipient_name ?: $r->destination;
            }
            $notes = [];
            if ($told) {
                $notes[] = 'Emailed "On hold - do not attend until you hear from us" to ' . implode(', ', array_unique($told)) . '.';
            }
            if ($phoneOnly->isNotEmpty()) {
                $notes[] = 'Phone ' . $phoneOnly->pluck('recipient_name')->filter()->unique()->implode(', ') . ' - the job reached them by WhatsApp, so they could not be emailed.';
            }
            if ($notes) {
                $hold->forceFill(['contractor_told_at' => $told ? now() : null, 'contractor_told_note' => mb_substr(implode(' ', $notes), 0, 250)])->save();
                $this->log($fault, $wo, RentalFaultReportUpdate::TYPE_NOTE, null, implode(' ', $notes), 'note');
            }
        } catch (\Throwable $e) {
            Log::warning('Owner hold: could not tell the contractor / crew', ['hold' => $hold->id, 'error' => $e->getMessage()]);
        }
    }

    // ───────────────────────── Resume (a fresh authority) ─────────────────────────

    /** The owner pressed "Go ahead after all". @return 'resumed'|'not_held' */
    public function resumeByOwner(RentalFaultReport|RentalWorkOrder $subject, Contact $owner, string $via): string
    {
        [$fault, $wo] = $this->jobOf($subject);
        $this->assertIsOwner($fault ?? $wo, $owner);
        $hold = null;
        $result = DB::transaction(function () use ($subject, $fault, $wo, $owner, $via, &$hold) {
            if ($fault) {
                RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->find($fault->id);
            }
            $hold = $this->activeFor($subject);
            if (! $hold) {
                return 'not_held';
            }
            $point = $hold->rental_authorisation_id ? (RentalAuthorisation::withoutGlobalScopes()->find($hold->rental_authorisation_id)?->point ?? RentalAuthorisation::POINT_INSPECT) : RentalAuthorisation::POINT_INSPECT;
            $row = $this->authorisations->recordFreshAuthority($fault ?? $wo, $point, RentalAuthorisation::BASIS_OWNER_LINK, null, $owner, null, $via === RentalOwnerHold::VIA_LINK ? RentalAuthorisation::VIA_LINK : RentalAuthorisation::VIA_LINK, now(), 'Went ahead after stopping it.');
            $hold->forceFill(['resumed_at' => now(), 'resume_basis' => RentalOwnerHold::RESUME_OWNER_LINK, 'resumed_by_contact_id' => $owner->id, 'resume_authorisation_id' => $row->id])->save();
            $hold->load(['resumedByContact', 'stoppedBy']);
            $this->log($fault, $wo, RentalFaultReportUpdate::TYPE_OWNER_RESUMED, null, $this->resumeLine($hold), 'owner_resumed');

            return 'resumed';
        });
        if ($result === 'resumed') {
            $addr = ($fault ?? $wo)->property?->buildDisplayAddress() ?: ('Property #' . ($fault ?? $wo)->property_id);
            $this->tellAgents($fault, $wo, self::EV_RESUMED, 'The owner said go ahead after all - ' . $addr,
                $this->ownerName($owner) . ' pressed "Go ahead after all" on "' . $this->jobTitle($fault, $wo) . '". The hold is lifted. If the contractor or crew were told to wait, send the job again.', 'info');
        }

        return $result;
    }

    /**
     * The agent pressed Resume and recorded the owner's decision (owner, how, when, what they said, proof). Same fields as "Record the owner's decision".
     *
     * @param array<string,mixed> $d owner_contact_id, told_via, told_at?, note
     */
    public function resumeByAgent(User $by, RentalFaultReport|RentalWorkOrder $subject, array $d, ?UploadedFile $proof = null): RentalOwnerHold
    {
        [$fault, $wo] = $this->jobOf($subject);
        $job = $fault ?? $wo;
        $owner = $this->authorisations->ownersFor($job)->firstWhere('id', (int) ($d['owner_contact_id'] ?? 0));
        if (! $owner) {
            throw new \InvalidArgumentException('Pick which owner told you - choose one of the property\'s owners.');
        }
        $via = (string) ($d['told_via'] ?? '');
        if (! in_array($via, RentalAuthorisation::VIAS, true)) {
            throw new \InvalidArgumentException('Say how the owner told you: phone call, WhatsApp or email.');
        }
        $note = trim((string) ($d['note'] ?? ''));
        if ($note === '') {
            throw new \InvalidArgumentException('Write down what the owner said - it is required.');
        }
        $toldAt = $this->authorisations->toldAt($job, $d['told_at'] ?? null);
        $proofPath = $proof ? app(RentalFaultReportService::class)->storeApprovalScreenshot($proof, (int) $job->property_id) : null;

        $hold = null;
        $row = null;
        DB::transaction(function () use ($by, $subject, $fault, $wo, $owner, $via, $toldAt, $note, $proofPath, &$hold, &$row) {
            if ($fault) {
                RentalFaultReport::withoutGlobalScopes()->lockForUpdate()->find($fault->id);
            }
            $hold = $this->activeFor($subject);
            if (! $hold) {
                throw new \LogicException('This job is not on hold.');
            }
            $point = $hold->rental_authorisation_id ? (RentalAuthorisation::withoutGlobalScopes()->find($hold->rental_authorisation_id)?->point ?? RentalAuthorisation::POINT_INSPECT) : RentalAuthorisation::POINT_INSPECT;
            $row = $this->authorisations->recordFreshAuthority($fault ?? $wo, $point, RentalAuthorisation::BASIS_OWNER_INSTRUCTION, $by, $owner, $proofPath, $via, $toldAt, $note);
            $hold->forceFill(['resumed_at' => now(), 'resume_basis' => RentalOwnerHold::RESUME_OWNER_INSTRUCTION, 'resumed_by_user_id' => $by->id, 'resume_authorisation_id' => $row->id])->save();
            $hold->load(['resumedByUser', 'stoppedBy']);
            $this->log($fault, $wo, RentalFaultReportUpdate::TYPE_OWNER_RESUMED, $by, $this->resumeLine($hold) . ' "' . $note . '"', 'owner_resumed');
        });
        $this->authorisations->tellOwner($row);

        return $hold->fresh();
    }

    // ───────────────────────── Query this ─────────────────────────

    public function queriesFor(RentalFaultReport|RentalWorkOrder $subject): Collection
    {
        [$fault, $wo] = $this->jobOf($subject);

        return RentalOwnerQuery::withoutGlobalScopes()->with(['askedBy', 'answeredBy'])
            ->where(function ($q) use ($fault, $wo) {
                if ($fault) {
                    $q->orWhere('rental_fault_report_id', $fault->id);
                }
                if ($wo) {
                    $q->orWhere('rental_work_order_id', $wo->id);
                }
            })->orderBy('id')->get();
    }

    /** The owner asked a question. The job is NOT stopped. */
    public function ask(RentalFaultReport|RentalWorkOrder $subject, Contact $owner, string $question, string $via): RentalOwnerQuery
    {
        $question = trim($question);
        if (mb_strlen($question) < 3) {
            throw new \InvalidArgumentException('Please type your question.');
        }
        if (mb_strlen($question) > 1000) {
            throw new \InvalidArgumentException('Please keep the question to 1000 characters.');
        }
        [$fault, $wo] = $this->jobOf($subject);
        if (! $fault && ! $wo) {
            throw new \InvalidArgumentException('That job could not be found.');
        }
        $this->assertIsOwner($fault ?? $wo, $owner);
        $row = RentalOwnerQuery::withoutGlobalScopes()->create([
            'agency_id' => ($fault ?? $wo)->agency_id, 'rental_fault_report_id' => $fault?->id, 'rental_work_order_id' => $wo?->id, 'asked_by_contact_id' => $owner->id,
            'asked_via' => $via === RentalOwnerQuery::VIA_LINK ? RentalOwnerQuery::VIA_LINK : RentalOwnerQuery::VIA_PORTAL, 'question' => $question, 'asked_at' => now(),
        ]);
        $this->log($fault, $wo, RentalFaultReportUpdate::TYPE_OWNER_QUERY, null, $this->ownerName($owner) . ' (owner) asked: ' . $question, 'owner_query');
        $addr = ($fault ?? $wo)->property?->buildDisplayAddress() ?: ('Property #' . ($fault ?? $wo)->property_id);
        $this->tellAgents($fault, $wo, self::EV_QUERY, 'The owner has a question - ' . $addr, $this->jobTitle($fault, $wo) . ': ' . mb_substr($question, 0, 300) . ' (Answer it from the "Owner\'s question" block on the fault page.)', 'info');

        return $row;
    }

    public function answer(User $by, RentalOwnerQuery $query, string $answer): RentalOwnerQuery
    {
        $answer = trim($answer);
        if ($answer === '') {
            throw new \InvalidArgumentException('Write your answer first.');
        }
        if (mb_strlen($answer) > 2000) {
            throw new \InvalidArgumentException('Please keep the answer to 2000 characters.');
        }
        if ($query->isAnswered()) {
            throw new \LogicException('This question has already been answered.');
        }
        $query->forceFill(['answered_by_user_id' => $by->id, 'answer' => $answer, 'answered_at' => now()])->save();
        $fault = $query->rental_fault_report_id ? RentalFaultReport::withoutGlobalScopes()->find($query->rental_fault_report_id) : null;
        $wo = $query->rental_work_order_id ? RentalWorkOrder::withoutGlobalScopes()->find($query->rental_work_order_id) : null;
        $this->log($fault, $wo, RentalFaultReportUpdate::TYPE_OWNER_QUERY_ANSWERED, $by, $by->name . ' answered the owner\'s question: ' . $answer, 'note');

        $owner = $query->askedBy;
        if ($owner && $owner->email) {
            try {
                $job = $fault ?? $wo;
                $target = $fault ? ['fault' => $fault->id] : ['wo' => $wo->id];
                $url = \App\Support\PortalLink::forEmail($owner->email, \App\Support\PortalLink::VIEW_OWNER, $target, (int) $owner->id);
                Mail::to($owner->email)->send(new RentalOwnerQueryAnswerMail($owner->first_name ?: 'there', $job->property?->buildDisplayAddress() ?: ('Property #' . $job->property_id), $this->jobTitle($fault, $wo), $query->question, $answer, $by->name, $url));
                $query->forceFill(['answer_sent_at' => now()])->save();
            } catch (\Throwable $e) {
                Log::warning('Owner query: could not email the answer', ['query' => $query->id, 'error' => $e->getMessage()]);
            }
        }

        return $query->fresh();
    }

    // ───────────────────────── helpers ─────────────────────────

    private function assertIsOwner(RentalFaultReport|RentalWorkOrder $job, Contact $owner): void
    {
        if (! $this->authorisations->ownersFor($job)->contains('id', $owner->id)) {
            throw new \InvalidArgumentException('That person is not an owner of this property.');
        }
    }

    private function jobTitle(?RentalFaultReport $fault, ?RentalWorkOrder $wo): string
    {
        return $fault ? (string) ($fault->ownerVersion()['title'] ?? $fault->title) : (string) $wo?->title;
    }

    /** One line in the fault's history and the work order's (whichever exist). */
    private function log(?RentalFaultReport $fault, ?RentalWorkOrder $wo, string $faultType, ?User $by, string $text, string $woType): void
    {
        if ($fault) {
            $fault->recordHistory($faultType, $by, $text);
        }
        if ($wo) {
            $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => $woType, 'note' => $text, 'created_by_user_id' => $by?->id]);
        }
    }

    /** Both lease agents - in-app AND a direct email each (in-app alone depends on each agent's own notification switches). */
    private function tellAgents(?RentalFaultReport $fault, ?RentalWorkOrder $wo, string $event, string $title, string $body, string $severity): void
    {
        $job = $fault ?? $wo;
        $property = $job?->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }
        $url = $fault ? route('corex.rental-fault-reports.show', $fault->id) : route('corex.rental-work-orders.show', $wo->id);
        $title = \App\Support\RentalAlertTitle::withProperty($title, $property);
        try {
            $lease = $job->lease;
            $agents = app(LeaseAgentService::class)->recipientsForEvent($lease, $property, (int) $job->agency_id);
            foreach ($agents as $agent) {
                try {
                    app(NotificationDispatcher::class)->fire($agent, $event, $fault ?? $wo, [
                        'title' => $title, 'body' => mb_substr($body, 0, 500), 'action_url' => $url, 'severity' => $severity, 'threshold_hit_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Owner notice: in-app alert failed', ['agent' => $agent->id, 'error' => $e->getMessage()]);
                }
            }
            foreach ($agents->filter(fn ($a) => $a->email)->unique('email') as $agent) {
                Mail::to($agent->email)->send(new RentalOwnerNoticeToAgentMail($agent->name, $title, $body, $url));
            }
        } catch (\Throwable $e) {
            Log::warning('Owner notice: could not tell the agents', ['job' => $job?->id, 'error' => $e->getMessage()]);
        }
    }
}
