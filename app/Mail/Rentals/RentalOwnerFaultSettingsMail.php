<?php

namespace App\Mail\Rentals;

use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalPropertyFaultSetting;
use App\Services\Rentals\RentalOwnerFaultSettingsService;
use App\Support\PortalLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.6 - the owner is told, at once, that their agent recorded (or re-sent) "How I want faults
 * handled" for their property: each value, who captured it, on what evidence, and two equal buttons - "Open my settings" (the portal, the owner's
 * view) and "I do not agree with this" (a signed link tied to this person, this property and this version).
 */
class RentalOwnerFaultSettingsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public array $lines;
    public string $capturedLine;
    public string $openUrl;
    public string $disagreeUrl;

    public function __construct(public RentalPropertyFaultSetting $setting, Property $property, Contact $owner)
    {
        $this->onQueue('mail');
        $svc = app(RentalOwnerFaultSettingsService::class);
        $this->recipientName = trim((string) $owner->first_name) ?: 'there';
        $this->propertyAddress = $property->buildDisplayAddress() ?: ('Property #' . $property->id);
        $money = fn ($x) => $x === null ? 'not set' : \App\Models\DealV2\AgencyServiceProvider::formatRand((float) $x);
        $this->lines = [
            'Repairs my agent may authorise without asking me' => 'up to ' . $money($setting->repair_limit),
            'Emergency repairs my agent may authorise without asking me' => $setting->emergency_limit !== null ? 'up to ' . $money($setting->emergency_limit) : 'not set',
            'A contractor who charges a call-out fee, without asking me first' => $setting->callout_mode === 'yes' ? 'yes, up to ' . $money($setting->callout_limit) : ($setting->callout_mode === 'no' ? 'no - ask me first' : 'not set'),
        ];
        $who = $setting->capturedBy?->name ?: 'Your agent';
        $basis = $setting->basisLabel();
        $this->capturedLine = $who . ' recorded this' . ($setting->captured_at ? ' on ' . $setting->captured_at->format('j M Y') : '')
            . ($basis ? ' - basis: ' . ($setting->capture_basis === RentalPropertyFaultSetting::BASIS_CARRIED_OVER ? strtolower($basis) : 'you told us by ' . strtolower($basis) . ($setting->capture_occurred_at ? ' on ' . $setting->capture_occurred_at->format('j M Y') : '')) : '')
            . ($setting->capture_note ? '. Note: "' . $setting->capture_note . '"' : '') . '.';
        $this->openUrl = PortalLink::forContact($owner, PortalLink::VIEW_OWNER, ['handling' => $property->id]);
        $this->disagreeUrl = URL::temporarySignedRoute('rentals.fault-settings.dispute', now()->addDays(60), ['property' => $property->id, 'contact' => $owner->id, 'v' => (int) $setting->version]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "How you want faults handled - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.owner-fault-settings');
    }
}
