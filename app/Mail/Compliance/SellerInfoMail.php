<?php

namespace App\Mail\Compliance;

use App\Models\Agency;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SellerInfoMail extends Mailable
{
    use Queueable, SerializesModels;

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

    public function __construct(
        Agency $agency,
        string $tier,
        string $sellerName,
        string $agentMessage = ''
    ) {
        $this->agency       = $agency;
        $this->tier         = $tier;
        $this->sellerName   = $sellerName;
        $this->agentMessage = $agentMessage;
        $this->tierLabel    = self::$tierSubjects[$tier] ?? 'Property Compliance Information';
    }

    public function envelope(): Envelope
    {
        $agencyShort = $this->agency->trading_name ?? $this->agency->name;
        $subject = "[{$agencyShort}] {$this->tierLabel}";

        $fromAddress = $this->agency->whistleblow_compliance_officer_email
            ?? config('mail.from.address');

        return new Envelope(
            from: new Address($fromAddress, $agencyShort),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $viewName = self::$tierViews[$this->tier] ?? self::$tierViews['tier_1'];

        return new Content(view: $viewName);
    }
}
