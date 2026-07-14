<?php

declare(strict_types=1);

namespace App\Mail\Billing;

use App\Support\Money\Zar;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * "Margate Properties moved to the CoreX Agency plan (11 users)."
 *
 * Spec: .ai/specs/agency-billing.md §7.5  (AT-11)
 *
 * Takes SCALARS, not the domain event and not the Agency model — see
 * SendAgencyPlanChangedEmail's docblock for why. Everything this email says was
 * true at the moment of the switch, and is captured then, so nothing it prints
 * can drift or blow up if the agency is edited or archived before the queue
 * drains.
 *
 * Sent via the 'corex' mailer so it delivers even where the default mailer is
 * 'log' (staging).
 */
class AgencyPlanChangedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $fromPlanLabel;
    public string $toPlanLabel;
    public string $previousMonthly;
    public string $newMonthly;
    public string $billingUrl;
    public bool $isUpgrade;
    public Carbon $switchedAt;

    public function __construct(
        public int $agencyId,
        public string $agencyName,
        public string $fromPlan,
        public string $toPlan,
        public int $seats,
        public float $previousMonthlyZar,
        public float $newMonthlyZar,
        public string $occurredAt,
    ) {
        $this->fromPlanLabel   = (string) config("corex-billing.{$fromPlan}.label", ucfirst($fromPlan));
        $this->toPlanLabel     = (string) config("corex-billing.{$toPlan}.label", ucfirst($toPlan));
        $this->previousMonthly = Zar::format($previousMonthlyZar);
        $this->newMonthly      = Zar::format($newMonthlyZar);
        $this->isUpgrade       = $toPlan === 'agency' && $fromPlan === 'team';
        $this->switchedAt      = Carbon::parse($occurredAt);

        // route(), not a hand-built path: the billing routes sit behind a `corex/`
        // prefix, so concatenating '/admin/billing' onto APP_URL produces a 404.
        $this->billingUrl      = route('admin.billing.index');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[CoreX Billing] {$this->agencyName} moved to the {$this->toPlanLabel} plan ({$this->seats} users)",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.plan-changed',
        );
    }
}
