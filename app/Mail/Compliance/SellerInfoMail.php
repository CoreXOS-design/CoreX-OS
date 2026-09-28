<?php

namespace App\Mail\Compliance;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SellerInfoMail extends BaseSignatureMail
{
    public Agency $agency;
    public string $tier;
    public string $sellerName;
    public string $agentMessage;
    public string $tierLabel;

    private static array $tierViews = [
        'tier_1' => 'emails.compliance.seller-info.tier1',
        'tier_2' => 'emails.compliance.seller-info.tier2',
        'tier_3' => 'emails.compliance.seller-info.tier3',
    ];

    private static array $tierSubjects = [
        'tier_1' => 'Why Proper Paperwork Protects YOU',
        'tier_2' => 'Why an FFC Matters When Choosing an Agent',
        'tier_3' => 'Important: Verifying Your Agent\'s Credentials',
    ];

    /**
     * 2026-09-28 — $agent is who this pack goes out AS: it drives the From/
     * Reply-To (BaseSignatureMail::getFromAddress()) and, via fromAgent(),
     * lets ComplianceMailDispatcher route the send through that agent's own
     * communication mailbox so the email lands in their Sent Items like
     * every other CoreX outbound. Falls back to the shared CoreX mailer,
     * unchanged, when $agent is null or has no mailbox configured.
     */
    public function __construct(
        Agency $agency,
        string $tier,
        string $sellerName,
        string $agentMessage = '',
        ?User $agent = null
    ) {
        $this->agency       = $agency;
        $this->tier         = $tier;
        $this->sellerName   = $sellerName;
        $this->agentMessage = $agentMessage;
        $this->tierLabel    = self::$tierSubjects[$tier] ?? 'Property Compliance Information';

        if ($agent) {
            $this->fromAgent($agent);
        }
    }

    public function envelope(): Envelope
    {
        $agencyShort = $this->agency->trading_name ?? $this->agency->name;
        $subject = "[{$agencyShort}] {$this->tierLabel}";

        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $viewName = self::$tierViews[$this->tier] ?? self::$tierViews['tier_1'];

        return new Content(view: $viewName);
    }
}
