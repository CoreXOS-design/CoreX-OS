<?php

declare(strict_types=1);

namespace App\Listeners\Contacts;

use App\Events\Contact\ContactTestimonialSubmitted;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * When a client submits a testimonial from the mobile app, notify the connected
 * agent — both in-app (database channel → notification bell) and by email — so
 * the agent sees the kind words land and can review/publish them.
 *
 * Synchronous + failure-isolated: a mail-transport hiccup or a departed agent
 * must never break the client's "thank you, submitted" response. The testimonial
 * is already persisted before this runs; the notification is best-effort.
 *
 * Spec: .ai/specs/testimonials.md §13.
 */
class NotifyAgentOfClientTestimonial
{
    public function handle(ContactTestimonialSubmitted $event): void
    {
        try {
            if (!$event->agentUserId) {
                return; // No connected agent on the contact — nothing to notify.
            }

            // ClientUser auth carries no User-agency context, so the global
            // AgencyScope is bypassed and the lookup is pinned to the
            // testimonial's own agency (mirrors ClientPortalController's agent
            // resolution). SoftDeletes excludes departed agents.
            /** @var User|null $agent */
            $agent = User::query()
                ->withoutGlobalScope(AgencyScope::class)
                ->where('id', $event->agentUserId)
                ->where('agency_id', $event->testimonial->agency_id)
                ->first();

            if (!$agent || empty($agent->email)) {
                return;
            }

            $contact     = $event->contact;
            $testimonial = $event->testimonial;

            $clientName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            $clientName = $clientName !== '' ? $clientName : ($testimonial->display_name ?: 'A client');

            $stars = $testimonial->rating ? str_repeat('★', (int) $testimonial->rating) . " ({$testimonial->rating}/5) — " : '';
            $quote = Str::limit(trim((string) $testimonial->body), 280);

            $agent->notify(new PillarEventNotification(
                eventKey:     'contact.testimonial_submitted',
                pillar:       'Contact',
                title:        "{$clientName} left you a testimonial",
                body:         $stars . '“' . $quote . '”',
                subjectType:  \App\Models\Contact::class,
                subjectId:    $contact->id,
                subjectLabel: $clientName,
                actionUrl:    '/corex/contacts/' . $contact->id . '#tab-notes',
                severity:     'info',
                payload:      [
                    'testimonial_id' => $testimonial->id,
                    'contact_id'     => $contact->id,
                    'rating'         => $testimonial->rating,
                ],
                channels:     ['database', 'mail'],
                // Send via the dedicated CoreX mailer (from mail@corexos.co.za)
                // so the email delivers through real SMTP even where the default
                // mailer is a sink (staging). config/mail.php → mailers.corex.
                mailer:       'corex',
            ));
        } catch (Throwable $e) {
            Log::error('Failed to notify agent of client testimonial', [
                'testimonial_id' => $event->testimonial->id ?? null,
                'agent_user_id'  => $event->agentUserId,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
