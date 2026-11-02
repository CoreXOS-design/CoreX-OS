<?php

namespace App\Services\Campaigns;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Mail\Campaigns\CampaignStepMail;
use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignSend;
use App\Models\Campaigns\CampaignStep;
use App\Models\Campaigns\CampaignSuppression;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * AT-534 — the Campaigns send engine (.ai/specs/campaigns.md §6). The never-send-twice guarantee is the UNIQUE
 * (campaign_step_id, campaign_lead_id) index plus claim-before-send: a send row is committed BEFORE any SMTP traffic, a second claim
 * of the same pair fails on the index, and a row left 'sending' (crash mid-send) becomes 'unknown' and is never sent again.
 */
class CampaignSendService
{
    /** Sanitised reasons that mean the sender's mailbox itself is unusable (§6.2-7). */
    private const MAILBOX_DOWN_REASONS = [
        'auth_failed', 'connect_failed', 'connection_refused', 'connect_timeout', 'tls_failed', 'incomplete_credentials', 'blocked_by_guard',
    ];

    public function __construct(
        private PerMailboxMailTransportBuilder $transport,
        private CampaignMergeRenderer $renderer,
        private CampaignSettings $settings,
    ) {
    }

    // ── Start / pause / resume ────────────────────────────────────────────────────────────────

    /** Why $campaign cannot start, in plain words — or null when it can (§6.3). */
    public function startBlocker(Campaign $campaign): ?string
    {
        if ($campaign->trashed()) {
            return 'This campaign is archived. Restore it first.';
        }
        if ($campaign->status !== Campaign::STATUS_DRAFT) {
            return 'Only a draft campaign can be started.';
        }
        if (! $campaign->steps()->exists()) {
            return 'Add at least one email step before starting.';
        }
        $sender = $campaign->sender;
        if (! $sender || $sender->trashed()) {
            return 'Choose a sending address that is not archived.';
        }
        $list = $campaign->list;
        if (! $list || $list->trashed()) {
            return 'This campaign\'s list is archived. Choose another list.';
        }
        if ((array) $campaign->send_days === []) {
            return 'Choose at least one send day.';
        }
        if (! $list->leads()->whereIn('status', [CampaignLead::STATUS_NEW, CampaignLead::STATUS_QUEUED])->exists()) {
            return 'The list has no leads that can be emailed.';
        }

        return null;
    }

    public function start(Campaign $campaign, int $userId): void
    {
        DB::transaction(function () use ($campaign, $userId) {
            $campaign->list->leads()->where('status', CampaignLead::STATUS_NEW)
                ->update(['status' => CampaignLead::STATUS_QUEUED, 'status_changed_at' => now(), 'updated_by' => $userId]);
            $campaign->forceFill([
                'status' => Campaign::STATUS_RUNNING, 'started_at' => now(), 'paused_reason' => null, 'updated_by' => $userId,
            ])->save();
        });
    }

    public function pause(Campaign $campaign, ?string $reason, ?int $userId): void
    {
        $campaign->forceFill(['status' => Campaign::STATUS_PAUSED, 'paused_reason' => $reason, 'updated_by' => $userId])->save();
    }

    public function resume(Campaign $campaign, int $userId): void
    {
        $campaign->forceFill(['status' => Campaign::STATUS_RUNNING, 'paused_reason' => null, 'updated_by' => $userId])->save();
    }

    // ── One run (§6.2) ────────────────────────────────────────────────────────────────────────

    /**
     * Send what is due for $campaign right now, within its window and what is left of today's limit.
     *
     * @return array{sent:int, failed:int, skipped:int, stopped_reason:?string}
     */
    public function runBatch(Campaign $campaign, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'stopped_reason' => null];

        $this->markStaleSendingUnknown();

        $campaign->refresh();
        if ($campaign->trashed() || $campaign->status !== Campaign::STATUS_RUNNING) {
            $result['stopped_reason'] = 'not_running';

            return $result;
        }
        $campaign->forceFill(['last_run_at' => $now])->save();

        if (! $campaign->insideWindow($now)) {
            $result['stopped_reason'] = 'outside_window';

            return $result;
        }

        $budget = min($this->remainingToday($campaign, $now), max(1, (int) config('campaigns.send_batch_size')));
        if ($budget <= 0) {
            $result['stopped_reason'] = 'daily_limit';

            return $result;
        }

        $sender = $campaign->sender;
        if (! $sender || $sender->trashed()) {
            $this->pause($campaign, 'The sending address was archived. Choose another and resume.', null);
            $result['stopped_reason'] = 'paused';

            return $result;
        }

        $mailboxDownStreak = 0;
        foreach ($this->duePairs($campaign, $now, $budget) as $pair) {
            if ($result['sent'] + $result['failed'] >= $budget) {
                break;
            }

            $outcome = $this->sendPair($campaign, $pair['step'], $pair['lead'], $pair['retry'] ?? null);
            if ($outcome === 'sent') {
                $result['sent']++;
                $mailboxDownStreak = 0;
            } elseif ($outcome === 'skipped') {
                $result['skipped']++;
            } else {
                $result['failed']++;
                $mailboxDownStreak = in_array($outcome, self::MAILBOX_DOWN_REASONS, true) ? $mailboxDownStreak + 1 : 0;
                if ($mailboxDownStreak >= 2) {
                    $this->pause($campaign, 'Paused: ' . $sender->email . ' could not send (' . $this->friendlyReason($outcome) . '). Fix it under Settings → Sending addresses, then resume.', null);
                    $result['stopped_reason'] = 'paused';

                    return $result;
                }
            }
        }

        $this->completeIfFinished($campaign);

        return $result;
    }

    /** How many more emails today's limit allows (§6.2-2). */
    public function remainingToday(Campaign $campaign, CarbonInterface $now): int
    {
        return max(0, (int) $campaign->daily_limit - $this->countedToday($campaign, $now));
    }

    public function countedToday(Campaign $campaign, CarbonInterface $now): int
    {
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        return CampaignSend::where('campaign_id', $campaign->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('sent_at', [$start, $end])
                    ->orWhere(fn ($q2) => $q2->whereIn('status', [CampaignSend::STATUS_SENDING, CampaignSend::STATUS_UNKNOWN])->whereBetween('updated_at', [$start, $end]));
            })
            ->count();
    }

    /** A row still 'sending' after the stale window may or may not have left: 'unknown', never re-sent (§6.2-6). */
    public function markStaleSendingUnknown(): int
    {
        return CampaignSend::where('status', CampaignSend::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes((int) config('campaigns.sending_stale_minutes')))
            ->update(['status' => CampaignSend::STATUS_UNKNOWN, 'updated_at' => now()]);
    }

    /**
     * The lead/step pairs due now, oldest-due first, at most $limit (§6.2-3). Retryable failures come first (they were due earliest).
     *
     * @return list<array{step: CampaignStep, lead: CampaignLead, due: CarbonInterface, retry?: CampaignSend}>
     */
    public function duePairs(Campaign $campaign, CarbonInterface $now, int $limit): array
    {
        $steps = $campaign->steps()->get()->values();
        if ($steps->isEmpty() || $limit <= 0) {
            return [];
        }
        $pairs = [];

        // Retries — the mailer threw, so the mail was not accepted; claimed again by a conditional update.
        $retries = CampaignSend::where('campaign_id', $campaign->id)
            ->where('status', CampaignSend::STATUS_FAILED)
            ->where('attempts', '<', (int) config('campaigns.max_attempts'))
            ->whereHas('lead', fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', CampaignLead::TERMINAL))
            ->with(['lead', 'step'])->orderBy('updated_at')->limit($limit)->get();
        foreach ($retries as $send) {
            $pairs[] = ['step' => $send->step, 'lead' => $send->lead, 'due' => $send->updated_at, 'retry' => $send];
        }

        $active = [CampaignLead::STATUS_QUEUED, CampaignLead::STATUS_SENT];
        foreach ($steps as $i => $step) {
            $query = CampaignLead::query()
                ->where('campaign_leads.campaign_list_id', $campaign->campaign_list_id)
                ->whereIn('campaign_leads.status', $active)
                ->whereNotExists(fn ($q) => $q->from('campaign_sends')->whereColumn('campaign_sends.campaign_lead_id', 'campaign_leads.id')->where('campaign_sends.campaign_step_id', $step->id));

            if ($i === 0) {
                foreach ($query->orderBy('campaign_leads.id')->limit($limit)->get() as $lead) {
                    $pairs[] = ['step' => $step, 'lead' => $lead, 'due' => $campaign->started_at ?? $now];
                }
                continue;
            }

            $prev = $steps[$i - 1];
            $cutoff = $now->copy()->subDays((int) $step->day_gap);
            $rows = $query->join('campaign_sends as prev', function ($join) use ($prev) {
                $join->on('prev.campaign_lead_id', '=', 'campaign_leads.id')->where('prev.campaign_step_id', '=', $prev->id);
            })
                ->where('prev.status', CampaignSend::STATUS_SENT)
                ->where('prev.sent_at', '<=', $cutoff)
                ->orderBy('prev.sent_at')->orderBy('campaign_leads.id')
                ->limit($limit)
                ->get(['campaign_leads.*', 'prev.sent_at as prev_sent_at']);
            foreach ($rows as $lead) {
                $due = \Illuminate\Support\Carbon::parse($lead->prev_sent_at)->addDays((int) $step->day_gap);
                unset($lead->prev_sent_at);
                $pairs[] = ['step' => $step, 'lead' => $lead, 'due' => $due];
            }
        }

        usort($pairs, function ($a, $b) {
            $retryFirst = isset($b['retry']) <=> isset($a['retry']);

            return $retryFirst !== 0 ? $retryFirst : [$a['due']->getTimestamp(), $a['lead']->id] <=> [$b['due']->getTimestamp(), $b['lead']->id];
        });

        return array_slice($pairs, 0, $limit);
    }

    /**
     * Claim, then send, one pair. Returns 'sent', 'skipped' (claimed elsewhere / suppressed / not sendable) or the sanitised failure
     * reason.
     */
    public function sendPair(Campaign $campaign, CampaignStep $step, CampaignLead $lead, ?CampaignSend $retry = null): string
    {
        $lead = CampaignLead::find($lead->id);
        if (! $lead || $lead->isTerminal()) {
            return 'skipped';
        }
        if (CampaignSuppression::isSuppressed($lead->email)) {
            $lead->terminate(CampaignLead::STATUS_SUPPRESSED, 'suppressed');

            return 'skipped';
        }

        $sender = $campaign->sender;
        $send = $retry ? $this->claimRetry($retry) : $this->claimNew($campaign, $step, $lead, $sender->email);
        if (! $send) {
            return 'skipped';
        }

        $mail = new CampaignStepMail(
            fromEmail: $sender->email,
            fromName: $sender->from_name,
            subjectLine: $send->subject,
            bodyText: $send->body,
            messageIdValue: (string) $send->message_id,
            unsubscribePostUrl: $this->unsubscribeUrl($send),
            bodyHtml: $send->body_html,
            signatureImagePath: $send->body_html !== null ? $sender->signatureImageFullPath() : null,
        );
        $mail->to($send->to_email);

        try {
            $this->transport->send($sender->toTransientMailbox(), $mail);
        } catch (OutgoingMailboxSendFailedException $e) {
            $send->forceFill(['status' => CampaignSend::STATUS_FAILED, 'error_reason' => $e->sanitisedReason])->save();
            $sender->forceFill(['last_send_error' => $e->sanitisedReason, 'last_send_error_at' => now()])->save();
            Log::warning('Campaigns: send failed', ['campaign_send_id' => $send->id, 'sender_id' => $sender->id, 'reason' => $e->sanitisedReason]);

            return $e->sanitisedReason;
        }

        $send->forceFill(['status' => CampaignSend::STATUS_SENT, 'sent_at' => now(), 'error_reason' => null])->save();
        $lead->moveTo(CampaignLead::STATUS_SENT);
        $sender->forceFill(['last_sent_at' => now(), 'last_send_error' => null, 'last_send_error_at' => null])->save();

        return 'sent';
    }

    /** Insert the send row (rendered snapshot + Message-ID) and commit it before any SMTP traffic. Null = this pair is already claimed. */
    private function claimNew(Campaign $campaign, CampaignStep $step, CampaignLead $lead, string $fromEmail): ?CampaignSend
    {
        $subject = Str::limit($this->renderer->renderSubject($step->subject, $lead), 250, '');
        $body = $this->renderer->render($step->body, $lead);

        try {
            return DB::transaction(function () use ($campaign, $step, $lead, $fromEmail, $subject, $body) {
                $send = CampaignSend::create([
                    'campaign_id' => $campaign->id,
                    'campaign_step_id' => $step->id,
                    'campaign_lead_id' => $lead->id,
                    'status' => CampaignSend::STATUS_SENDING,
                    'attempts' => 1,
                    'to_email' => $lead->email,
                    'from_email' => $fromEmail,
                    'subject' => $subject,
                    'body' => $body,
                ]);
                $send->forceFill([
                    'message_id' => $this->messageIdFor($send->id, $fromEmail),
                    'body' => $this->composeBody($body, $campaign, $send),
                    'body_html' => $this->composeHtml($body, $campaign, $send),
                ])->save();

                return $send;
            });
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }
    }

    /** A failed row is retried only through this conditional update, so two runs can never both claim it. */
    private function claimRetry(CampaignSend $send): ?CampaignSend
    {
        $claimed = CampaignSend::whereKey($send->id)
            ->where('status', CampaignSend::STATUS_FAILED)
            ->where('attempts', '<', (int) config('campaigns.max_attempts'))
            ->update(['status' => CampaignSend::STATUS_SENDING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        return $claimed === 1 ? $send->fresh() : null;
    }

    /** Step body + signature + opt-out footer + unsubscribe link (§6.5). */
    public function composeBody(string $renderedBody, Campaign $campaign, CampaignSend $send): string
    {
        $parts = [rtrim($renderedBody)];
        $signature = trim((string) $campaign->sender?->signature);
        if ($signature !== '') {
            $parts[] = $signature;
        }
        $parts[] = $this->settings->all()['opt_out_footer'] . "\n" . $this->unsubscribeUrl($send);

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * HTML version of the same email (spec 9.3) - ONLY when the sending address has a signature image; null = plain text as before.
     * Snapshotted on the send row at claim time, like the text body, so a retry sends the identical message.
     */
    public function composeHtml(string $renderedBody, Campaign $campaign, CampaignSend $send): ?string
    {
        $sender = $campaign->sender;
        if (! $sender || $sender->signatureImageFullPath() === null) {
            return null;
        }

        return app(CampaignHtmlRenderer::class)->render(
            rtrim($renderedBody),
            trim((string) $sender->signature) !== '' ? trim((string) $sender->signature) : null,
            (string) $this->settings->all()['opt_out_footer'],
            $this->unsubscribeUrl($send),
        );
    }

    public function unsubscribeUrl(CampaignSend $send): string
    {
        return URL::signedRoute('campaigns.public.unsubscribe', ['send' => $send->id]);
    }

    public function messageIdFor(int $sendId, string $fromEmail): string
    {
        $domain = Str::after($fromEmail, '@') ?: 'corexos.co.za';

        return 'cx-campaign-' . $sendId . '-' . Str::lower(Str::random(12)) . '@' . $domain;
    }

    /** No lead has anything left to send and nothing is in flight → done (§6.2-8). */
    public function completeIfFinished(Campaign $campaign): bool
    {
        if ($campaign->status !== Campaign::STATUS_RUNNING || $this->hasPendingWork($campaign)) {
            return false;
        }
        $campaign->forceFill(['status' => Campaign::STATUS_DONE, 'done_at' => now()])->save();

        return true;
    }

    public function hasPendingWork(Campaign $campaign): bool
    {
        $inFlight = CampaignSend::where('campaign_id', $campaign->id)
            ->where(fn ($q) => $q->where('status', CampaignSend::STATUS_SENDING)
                ->orWhere(fn ($q2) => $q2->where('status', CampaignSend::STATUS_FAILED)->where('attempts', '<', (int) config('campaigns.max_attempts'))
                    ->whereHas('lead', fn ($l) => $l->whereNotIn('status', CampaignLead::TERMINAL))))
            ->exists();
        if ($inFlight) {
            return true;
        }
        // A far-future "now" with no limit finds every pair that is not yet sent but will become due.
        $far = now()->addYears(10);

        return $this->duePairs($campaign, $far, 1) !== [];
    }

    /** When the next email is expected to go (§7.1 "Next run"); null when nothing is pending or the campaign is not running. */
    public function nextRun(Campaign $campaign, ?CarbonInterface $now = null): ?CarbonInterface
    {
        $now ??= now();
        if ($campaign->status !== Campaign::STATUS_RUNNING) {
            return null;
        }
        $pair = $this->duePairs($campaign, now()->addYears(10), 1)[0] ?? null;
        if (! $pair && ! $this->hasPendingWork($campaign)) {
            return null;
        }
        $at = $pair && $pair['due']->greaterThan($now) ? $pair['due']->copy() : $now->copy();
        if ($this->remainingToday($campaign, $now) <= 0 && $at->isSameDay($now)) {
            $at = $now->copy()->addDay()->startOfDay();
        }

        return $campaign->nextWindowOpen($at);
    }

    // ── Stops (unsubscribe / STOP / bounce / reply — §6.4, §6.6) ─────────────────────────────

    public function unsubscribe(CampaignSend $send): bool
    {
        $already = $send->unsubscribed_at !== null;
        DB::transaction(function () use ($send) {
            if (! $send->unsubscribed_at) {
                $send->forceFill(['unsubscribed_at' => now()])->save();
            }
            $send->lead?->terminate(CampaignLead::STATUS_STOPPED, 'unsubscribe');
            CampaignSuppression::suppress($send->to_email, 'unsubscribe', $send->id);
        });

        return ! $already;
    }

    public function recordStopReply(CampaignSend $send): void
    {
        DB::transaction(function () use ($send) {
            if (! $send->replied_at) {
                $send->forceFill(['replied_at' => now()])->save();
            }
            $send->lead?->terminate(CampaignLead::STATUS_STOPPED, 'reply_stop');
            CampaignSuppression::suppress($send->to_email, 'reply_stop', $send->id);
        });
    }

    public function recordReply(CampaignSend $send): void
    {
        if (! $send->replied_at) {
            $send->forceFill(['replied_at' => now()])->save();
        }
        $send->lead?->terminate(CampaignLead::STATUS_REPLIED, 'reply');
    }

    public function recordHardBounce(CampaignSend $send): void
    {
        DB::transaction(function () use ($send) {
            if (! $send->bounced_at) {
                $send->forceFill(['bounced_at' => now()])->save();
            }
            $send->lead?->terminate(CampaignLead::STATUS_BOUNCED, 'hard_bounce');
            CampaignSuppression::suppress($send->to_email, 'hard_bounce', $send->id);
        });
    }

    public function friendlyReason(?string $reason): string
    {
        return match ($reason) {
            'auth_failed' => 'login failed — check the username and password',
            'connect_failed', 'connection_refused', 'connect_timeout' => 'could not reach the mail server',
            'tls_failed' => 'secure connection failed — check the port and encryption',
            'incomplete_credentials' => 'server, username or password missing',
            'blocked_by_guard' => 'sending is switched off on this server',
            'send_rejected' => 'the mail server refused the message',
            null, '' => '',
            default => 'sending failed',
        };
    }

    /** @return Collection<int, Campaign> */
    public function runnable(): Collection
    {
        return Campaign::where('status', Campaign::STATUS_RUNNING)->get();
    }
}
