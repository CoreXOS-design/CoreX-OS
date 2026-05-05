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

        // Log activity (lightweight — no full state recompute for access events)
        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id ?? 1,
            'activity_type' => 'contact_access',
            'activity_date' => $log->accessed_at,
            'logged_by_user_id' => $log->user_id,
        ]);
    }
}
