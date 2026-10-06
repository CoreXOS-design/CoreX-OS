<?php

namespace App\Mail\PlatformEsign;

use App\Models\Platform\PlatformCompany;
use App\Models\PlatformEsign\Document;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every Platform E-Sign / Subscription Agreement email is sent FROM the platform company record (Admin → Platform
 * Company Profile: "Sending address" + "Sender name"), never the box-wide MAIL_FROM_* (which is whichever agency the install
 * was first set up for). Replies go to the CoreX owner who sent the agreement.
 */
trait SendsFromPlatformCompany
{
    protected function platformEnvelope(string $subject, ?Document $doc): Envelope
    {
        $sender = $doc && $doc->created_by ? User::withoutGlobalScopes()->find($doc->created_by) : null;
        $replyTo = $sender && filter_var($sender->email, FILTER_VALIDATE_EMAIL)
            ? [new Address(strtolower((string) $sender->email), (string) $sender->name)]
            : [];

        return new Envelope(from: PlatformCompany::current()->mailFrom(), replyTo: $replyTo, subject: $subject);
    }
}
