<?php

namespace App\Observers;

use App\Models\BuyerActivityLog;
use App\Models\Contact;
use App\Models\ContactAccessLog;

class ContactAccessLogObserver
{
    public function created(ContactAccessLog $log): void
    {
        $contact = Contact::withoutGlobalScopes()->find($log->contact_id);
        if (!$contact || !$contact->is_buyer) {
            return;
        }

        // Low-priority signal: only update if more recent
        if (!$contact->last_activity_at || $log->accessed_at->gt($contact->last_activity_at)) {
            $contact->updateQuietly(['last_activity_at' => $log->accessed_at]);
        }

        // Log activity (lightweight — no full state recompute for access events).
        // AT-253 Rule 17 — a contact with no tenant files no activity anywhere.
        if (! $contact->agency_id) {
            \Log::warning('AT-253 contact-access activity skipped: contact has no agency', [
                'contact_id' => $contact->id,
            ]);

            return;
        }

        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id,   // AT-253 Rule 17 — the CONTACT's tenant
            'activity_type' => 'contact_access',
            'activity_date' => $log->accessed_at,
            'logged_by_user_id' => $log->user_id,
        ]);
    }
}
