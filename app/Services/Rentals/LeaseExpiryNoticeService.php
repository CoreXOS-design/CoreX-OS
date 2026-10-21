<?php

declare(strict_types=1);

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * AT-489 (Johan, 21 Oct 2026) - the AGENCY'S EXPIRY NOTICE to a tenant, on Consumer Protection Act law (s14(2)(c); counted per s2(6): first
 * day excluded, last day included, Saturdays, Sundays and South African public holidays left out).
 *
 * A fixed-term lease must be told its term is ending not more than 80 and not less than 40 business days before it ends:
 *   due between   end - 80 business days  and  end - 40 business days          (the law - LeaseSetting::CPA_EXPIRY_NOTICE_*)
 *   CoreX sends   end - N business days                                         (the agency's setting, 40-80, default 60)
 *   agents alert  when the notice has NOT gone out and N' business days remain  (the agency's setting, default 45, between 40 and N)
 *   overdue       the notice has not gone out and fewer than 40 business days remain (lease screen + Command Centre)
 *
 * Wording is the agency's own: an active "Lease expiry notice" template in Settings -> Rental Notice Templates. CoreX ships none (and never
 * touches the lease agreements' own wording). With no template CoreX cannot send for the agency: it alerts the agents instead. A notice an
 * agent sends by hand from that template (Send notice) counts, and so does "mark as given" for one handed over another way.
 *
 * The 20 business days a TENANT gives to cancel early (s14(2)(b)) is a different rule: LeaseNoticeReviewService, `cpa_early_cancel_business_days`.
 */
class LeaseExpiryNoticeService
{
    public const EVENT_DUE = 'lease.expiry_notice_due';

    public const STATE_NONE = 'none';          // no expiry notice applies (month-to-month, not active, renewed, notice already given, ended)
    public const STATE_SENT = 'sent';
    public const STATE_NOT_DUE = 'not_due';    // before the send day
    public const STATE_DUE = 'due';            // send day reached, still more business days left than the alert point
    public const STATE_ALERT = 'alert';        // inside the alert point, still inside the legal window
    public const STATE_OVERDUE = 'overdue';    // fewer than 40 business days left and it has not gone out

    public function __construct(private readonly BusinessDayService $days) {}

    /** Does an expiry notice apply to this lease at all? */
    public function applies(Lease $lease): bool
    {
        if ($lease->status !== Lease::STATUS_ACTIVE || $lease->is_month_to_month || ! $lease->end_date) {
            return false;
        }
        // a tenancy already ending another way: the tenant or landlord gave notice, or it has been renewed
        if ($lease->renewed_lease_id || $lease->hasActiveNotice()) {
            return false;
        }

        return true;
    }

    /**
     * The three dates of the notice for a given end date: the legal window and the day CoreX sends it.
     *
     * @return array{from:string,to:string,send_on:string,max:int,min:int,send:int,alert:int,end:string}|null null when there is no end date
     */
    public function dates(int $agencyId, ?CarbonInterface $end): ?array
    {
        if ($end === null) {
            return null;
        }
        $max = LeaseSetting::CPA_EXPIRY_NOTICE_MAX_BUSINESS_DAYS;
        $min = LeaseSetting::CPA_EXPIRY_NOTICE_MIN_BUSINESS_DAYS;
        $send = LeaseSetting::expiryNoticeSendBusinessDaysFor($agencyId);

        return [
            'from' => $this->days->daysBefore($end, $max)->toDateString(),
            'to' => $this->days->daysBefore($end, $min)->toDateString(),
            'send_on' => $this->days->daysBefore($end, $send)->toDateString(),
            'max' => $max,
            'min' => $min,
            'send' => $send,
            'alert' => LeaseSetting::expiryNoticeAlertBusinessDaysFor($agencyId),
            'end' => Carbon::instance($end)->toDateString(),
        ];
    }

    /**
     * Where a lease stands. 'remaining' is the business days left from today to the end date (today excluded, end date included).
     *
     * @return array{state:string,dates:?array,remaining:?int,sent_at:?Carbon,sent_via:?string,alerted_at:?Carbon}
     */
    public function status(Lease $lease, ?CarbonInterface $today = null): array
    {
        $today = Carbon::instance($today ?? now())->startOfDay();
        $dates = $lease->end_date && ! $lease->is_month_to_month ? $this->dates((int) $lease->agency_id, $lease->end_date) : null;
        $base = ['dates' => $dates, 'remaining' => null, 'sent_at' => $lease->expiry_notice_sent_at, 'sent_via' => $lease->expiry_notice_sent_via, 'alerted_at' => $lease->expiry_notice_alerted_at];

        if (! $this->applies($lease)) {
            return $base + ['state' => self::STATE_NONE];
        }
        $remaining = $this->days->countBetween($today, $lease->end_date);
        $base['remaining'] = $remaining;

        if ($lease->expiry_notice_sent_at) {
            return $base + ['state' => self::STATE_SENT];
        }
        if ($remaining < $dates['min']) {
            return $base + ['state' => self::STATE_OVERDUE];
        }
        if ($remaining <= $dates['alert']) {
            return $base + ['state' => self::STATE_ALERT];
        }
        if ($remaining <= $dates['send']) {
            return $base + ['state' => self::STATE_DUE];
        }

        return $base + ['state' => self::STATE_NOT_DUE];
    }

    /** The agency's active expiry-notice template (its own wording), if it has one. */
    public function templateFor(int $agencyId): ?RentalNoticeTemplate
    {
        return RentalNoticeTemplate::withoutGlobalScopes()->where('agency_id', $agencyId)->where('notice_type', RentalNoticeTemplate::TYPE_EXPIRY_NOTICE)
            ->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->first();
    }

    /** Record that the notice has gone out ($via: corex | agent | manual). Idempotent: the first record stands. */
    public function markSent(Lease $lease, string $via, ?User $by = null, ?string $note = null): void
    {
        if ($lease->expiry_notice_sent_at) {
            return;
        }
        $lease->forceFill([
            'expiry_notice_sent_at' => now(), 'expiry_notice_sent_via' => $via,
            'expiry_notice_sent_by_user_id' => $by?->id, 'expiry_notice_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
        ])->saveQuietly();

        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_EXPIRY_NOTICE_SENT, 'actor_user_id' => $by?->id, 'occurred_at' => now(), 'created_at' => now(),
            'description' => match ($via) {
                'corex' => 'Lease expiry notice sent to the tenant by CoreX',
                'agent' => 'Lease expiry notice sent to the tenant',
                default => 'Lease expiry notice marked as given to the tenant' . ($note ? ': ' . $note : ''),
            },
            'metadata' => ['via' => $via],
        ]);
    }

    /**
     * Send the notice for the agency, in the agency's own template, to the tenants, from the tenant's agent's own mailbox.
     *
     * @return array{ok:bool,reason:?string}
     */
    public function sendNow(Lease $lease): array
    {
        $template = $this->templateFor((int) $lease->agency_id);
        if (! $template) {
            return ['ok' => false, 'reason' => 'no_template'];
        }
        if (! collect($lease->tenantContacts())->contains(fn ($c) => ! empty($c->email))) {
            return ['ok' => false, 'reason' => 'no_tenant_email'];
        }
        $sender = $this->senderFor($lease);
        if (! $sender) {
            return ['ok' => false, 'reason' => 'no_sender'];
        }

        try {
            app(RentalNoticeService::class)->send($lease, $template, [], true, false, $sender);
        } catch (\Throwable $e) {
            Log::warning('AT-489 expiry notice send failed', ['lease' => $lease->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'send_failed'];
        }
        $lease->refresh();
        // RentalNoticeService::send() noted it as an agent's send; this one was CoreX's.
        $lease->forceFill(['expiry_notice_sent_via' => 'corex'])->saveQuietly();

        return ['ok' => true, 'reason' => null];
    }

    /** The person the notice comes from: the tenant's agent, else the owner's agent, else whoever created the lease. */
    private function senderFor(Lease $lease): ?User
    {
        $ids = app(LeaseAgentService::class)->effectiveIds($lease);
        foreach ([$ids['tenant'] ?? null, $ids['owner'] ?? null, $lease->created_by_user_id] as $id) {
            if ($id && ($user = User::withoutGlobalScopes()->whereKey($id)->where('is_active', true)->first())) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Tell the lease's agents, once, that the notice has not gone out. Returns how many were reached (the alert is recorded even when nobody could be).
     */
    public function alertAgents(Lease $lease, string $reason = 'alert'): int
    {
        $property = $lease->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return 0;
        }
        $st = $this->status($lease);
        $end = $lease->end_date->format('j M Y');
        $title = \App\Support\RentalAlertTitle::withProperty('Lease expiry notice has not gone out', $property);
        $body = match ($reason) {
            'no_template' => 'The lease ends on ' . $end . ' and the expiry notice is due. Your agency has no "Lease expiry notice" template set up, so CoreX cannot send it: send it yourself or add the template (Settings -> Rental Notice Templates).',
            'no_tenant_email' => 'The lease ends on ' . $end . ' and the expiry notice is due, but no tenant has an email address on the lease. Hand it over another way and mark it as given on the lease.',
            default => 'The lease ends on ' . $end . ' and the expiry notice to the tenant has not gone out. ' . ($st['remaining'] ?? '?') . ' business days remain; it must go out not less than '
                . LeaseSetting::CPA_EXPIRY_NOTICE_MIN_BUSINESS_DAYS . ' business days before the lease ends (Consumer Protection Act).',
        };
        $url = route('corex.leases.show', $lease->id);
        $reached = 0;
        foreach (app(LeaseAgentService::class)->recipientsForEvent($lease, $property, (int) $lease->agency_id, null) as $agent) {
            try {
                if (app(NotificationDispatcher::class)->fire($agent, self::EVENT_DUE, $lease, [
                    'title' => $title, 'body' => $body, 'action_url' => $url, 'severity' => 'warning', 'threshold_hit_at' => now(),
                ])) {
                    $reached++;
                }
            } catch (\Throwable $e) {
                Log::warning('AT-489 expiry notice alert failed', ['lease' => $lease->id, 'agent' => $agent->id, 'error' => $e->getMessage()]);
            }
        }
        $lease->forceFill(['expiry_notice_alerted_at' => now()])->saveQuietly();
        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_EXPIRY_NOTICE_ALERTED, 'actor_user_id' => null, 'occurred_at' => now(), 'created_at' => now(),
            'description' => 'Lease agents told the expiry notice has not gone out (' . ($st['remaining'] ?? '?') . ' business days remain)',
            'metadata' => ['reason' => $reason, 'reached' => $reached],
        ]);

        return $reached;
    }
}
