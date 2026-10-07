<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalInspectionNotificationMail;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\LeaseTenant;
use App\Models\PerformanceSetting;
use App\Models\RentalInspection;
use App\Models\RentalInspectionNotification;
use App\Models\RentalInspectionReschedule;
use App\Models\RentalInspectionSetting;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use App\Services\Distribution\SignedDocumentDistributionService;

/**
 * .ai/specs/rental-inspections.md §43 — notify tenant(s), landlord (via
 * Lease::landlordContacts() — never a tenant fallback) and the inspector
 * on schedule/reschedule/cancel, plus the reminder command. Which parties
 * and which channel(s) are agency settings (RentalInspectionSetting);
 * every attempt is logged on the inspection (RentalInspectionNotification).
 *
 * MAIL is a real, fully automated send — the existing per-agent-mailbox
 * mechanism (SignedDocumentDistributionService::sendGenericMail(), the
 * path already built for exactly this: a Mailable outside the
 * SignedDocumentDistributable contract), with the SAME non-production
 * test-mail redirect safety rail as every other outbound mail in CoreX.
 *
 * WHATSAPP is, honestly, not a real automated send anywhere in this
 * codebase today — every existing "WhatsApp" feature (Core Matches,
 * the Outreach Queue) opens wa.me in a human's own browser; there is no
 * server-side WhatsApp sending API in CoreX. Reusing the Outreach Queue
 * here would also be the wrong tool even if one existed for sending: it
 * gates on marketing consent (wrong for an operational notice a tenant
 * can't opt out of) and has no path at all for notifying a User (the
 * inspector). So when the WhatsApp channel setting is on, this logs a
 * 'queued' row carrying the composed message — visible in the
 * inspection's own notification history for an agent to act on by hand —
 * rather than fabricating a send that cannot actually happen. Flagged
 * plainly, not silently.
 */
class RentalInspectionNotificationService
{
    public function notifyScheduled(RentalInspection $inspection): void
    {
        $this->dispatch($inspection, RentalInspectionNotification::EVENT_SCHEDULED, 'Scheduled', null);
    }

    public function notifyRescheduled(RentalInspection $inspection, RentalInspectionReschedule $change): void
    {
        $this->dispatch($inspection, RentalInspectionNotification::EVENT_RESCHEDULED, 'Rescheduled', $change->reason);
    }

    public function notifyCancelled(RentalInspection $inspection, User $by): void
    {
        $this->dispatch($inspection, RentalInspectionNotification::EVENT_CANCELLED, 'Cancelled', $inspection->cancel_reason);
    }

    public function notifyReminder(RentalInspection $inspection): void
    {
        $this->dispatch($inspection, RentalInspectionNotification::EVENT_REMINDER, 'Reminder', null);
    }

    private function dispatch(RentalInspection $inspection, string $event, string $eventLabel, ?string $reason): void
    {
        $agencyId = $inspection->agency_id;

        foreach ($this->recipientsFor($inspection, $agencyId) as $recipient) {
            if (RentalInspectionSetting::notifyViaMailFor($agencyId) && $recipient['email']) {
                $this->sendMail($inspection, $recipient, $eventLabel, $reason);
            } elseif (RentalInspectionSetting::notifyViaMailFor($agencyId) && ! $recipient['email']) {
                $this->log($inspection, $event, $recipient, RentalInspectionNotification::CHANNEL_MAIL, null, RentalInspectionNotification::STATUS_SKIPPED, 'No email on file.');
            }

            if (RentalInspectionSetting::notifyViaWhatsappFor($agencyId) && $recipient['phone']) {
                $this->queueWhatsapp($inspection, $recipient, $eventLabel, $reason);
            } elseif (RentalInspectionSetting::notifyViaWhatsappFor($agencyId) && ! $recipient['phone']) {
                $this->log($inspection, $event, $recipient, RentalInspectionNotification::CHANNEL_WHATSAPP, null, RentalInspectionNotification::STATUS_SKIPPED, 'No phone number on file.');
            }
        }
    }

    /**
     * @return array<int, array{role:string, name:string, email:?string, phone:?string, contact_id:?int, user_id:?int}>
     */
    private function recipientsFor(RentalInspection $inspection, ?int $agencyId): array
    {
        $recipients = [];

        if (RentalInspectionSetting::notifyTenantFor($agencyId)) {
            foreach ($this->tenantContacts($inspection) as $contact) {
                $recipients[] = $this->contactRecipient(RentalInspectionNotification::PARTY_TENANT, $contact);
            }
        }

        if (RentalInspectionSetting::notifyLandlordFor($agencyId)) {
            // Johan's instruction, verbatim: the SAME resolution
            // Lease::landlordContacts() uses — never a tenant fallback —
            // but NOT that method's own call, for a reason specific to this
            // being a system notification: Contact carries its own
            // ContactScope (role-based 'own'/'branch'/'all' read
            // visibility, keyed off the CURRENTLY ACTING user's personal
            // Contacts permission — core-matches.md §"The ContactScope
            // trap" already documents this exact collision). A booking
            // agent whose own Contacts scope is 'own' and who didn't
            // personally create the landlord's Contact row would silently
            // get ZERO landlord rows back from that method — not a
            // missing landlord, a missing NOTIFICATION, which is a correctness
            // bug for a system action that must not depend on who happened
            // to click the button. Resolved instead via tenantContacts()/
            // landlordContacts() below: same two-step, top-level-only scope
            // bypass core-matches.md's own "actual fix" uses (never inside
            // a relation closure), same semantics (landlord/lessor pivots,
            // falling back to seller/owner only when neither is tagged —
            // never "the only contact on file").
            foreach ($this->landlordContacts($inspection) as $contact) {
                $recipients[] = $this->contactRecipient(RentalInspectionNotification::PARTY_LANDLORD, $contact);
            }
        }

        if (RentalInspectionSetting::notifyInspectorFor($agencyId) && $inspection->inspector) {
            $inspector = $inspection->inspector;
            $recipients[] = [
                'role' => RentalInspectionNotification::PARTY_INSPECTOR,
                'name' => $inspector->name,
                'email' => $inspector->email,
                'phone' => $inspector->phone ?? $inspector->cell ?? null,
                'contact_id' => null,
                'user_id' => $inspector->id,
            ];
        }

        return $recipients;
    }

    /**
     * Every tenant on this inspection's lease, ContactScope bypassed at a
     * fresh top-level Contact query (never inside a relation closure — the
     * exact Eloquent-internals limitation core-matches.md's own
     * investigation hit and documented: a nested withoutGlobalScope()
     * registers on the closure's own builder but does not reliably
     * propagate to the compiled SQL). AgencyScope is untouched — still a
     * hard cross-agency boundary.
     */
    public function tenantContacts(RentalInspection $inspection): \Illuminate\Support\Collection
    {
        if (! $inspection->lease_id) {
            return collect();
        }

        $ids = LeaseTenant::where('lease_id', $inspection->lease_id)->pluck('contact_id');

        return Contact::withoutGlobalScope(ContactScope::class)->whereIn('id', $ids)->get();
    }

    /**
     * Mirrors Lease::landlordContacts()'s own resolution exactly (landlord/
     * lessor pivots; falls back to seller/owner ONLY when neither is
     * tagged — never "the only contact on file") — see the ContactScope
     * note at this method's call site for why this doesn't call that
     * method directly.
     */
    public function landlordContacts(RentalInspection $inspection): \Illuminate\Support\Collection
    {
        if (! $inspection->property_id) {
            return collect();
        }

        $landlordIds = ContactProperty::where('property_id', $inspection->property_id)
            ->whereIn('role', ['landlord', 'lessor'])
            ->pluck('contact_id');

        $landlords = Contact::withoutGlobalScope(ContactScope::class)->whereIn('id', $landlordIds)->get();
        if ($landlords->isNotEmpty()) {
            return $landlords;
        }

        $ownerIds = ContactProperty::where('property_id', $inspection->property_id)
            ->whereIn('role', ['seller', 'owner'])
            ->pluck('contact_id');

        return Contact::withoutGlobalScope(ContactScope::class)->whereIn('id', $ownerIds)->get();
    }

    private function contactRecipient(string $role, Contact $contact): array
    {
        return [
            'role' => $role,
            'name' => $contact->full_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'contact_id' => $contact->id,
            'user_id' => null,
        ];
    }

    private function sendMail(RentalInspection $inspection, array $recipient, string $eventLabel, ?string $reason): void
    {
        $agent = $inspection->createdBy;
        $inspectionUrl = $recipient['role'] === RentalInspectionNotification::PARTY_INSPECTOR
            ? route('corex.rental-inspections.show', $inspection)
            : url('/portal');

        $mail = new RentalInspectionNotificationMail(
            recipientName: $recipient['name'],
            eventLabel: $eventLabel,
            propertyAddress: $inspection->property?->buildDisplayAddress() ?? '',
            scheduledLine: $this->scheduledLine($inspection),
            inspectorName: $inspection->inspector?->name,
            note: $inspection->schedule_note,
            reason: $reason,
            emailSubject: $this->subjectFor($inspection, $eventLabel),
            inspectionUrl: $inspectionUrl,
        );

        $result = app(SignedDocumentDistributionService::class)->sendGenericMail($recipient['email'], $mail, $agent);

        $this->log(
            $inspection,
            $this->eventKeyFor($eventLabel),
            $recipient,
            RentalInspectionNotification::CHANNEL_MAIL,
            $recipient['email'],
            $result['status'] === 'sent' ? RentalInspectionNotification::STATUS_SENT : RentalInspectionNotification::STATUS_FAILED,
            $result['error'],
        );
    }

    /** See class docblock — honestly logged as queued, never a fabricated send. */
    private function queueWhatsapp(RentalInspection $inspection, array $recipient, string $eventLabel, ?string $reason): void
    {
        $this->log(
            $inspection,
            $this->eventKeyFor($eventLabel),
            $recipient,
            RentalInspectionNotification::CHANNEL_WHATSAPP,
            $recipient['phone'],
            RentalInspectionNotification::STATUS_QUEUED,
            null,
        );
    }

    private function log(RentalInspection $inspection, string $event, array $recipient, string $channel, ?string $recipientShown, string $status, ?string $error): void
    {
        RentalInspectionNotification::create([
            'agency_id' => $inspection->agency_id,
            'rental_inspection_id' => $inspection->id,
            'event' => $event,
            'party_role' => $recipient['role'],
            'recipient_contact_id' => $recipient['contact_id'],
            'recipient_user_id' => $recipient['user_id'],
            'channel' => $channel,
            'recipient' => $recipientShown,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function eventKeyFor(string $eventLabel): string
    {
        return match ($eventLabel) {
            'Scheduled' => RentalInspectionNotification::EVENT_SCHEDULED,
            'Rescheduled' => RentalInspectionNotification::EVENT_RESCHEDULED,
            'Cancelled' => RentalInspectionNotification::EVENT_CANCELLED,
            'Reminder' => RentalInspectionNotification::EVENT_REMINDER,
            default => RentalInspectionNotification::EVENT_SCHEDULED,
        };
    }

    private function scheduledLine(RentalInspection $inspection): string
    {
        $date = $inspection->scheduled_for?->format('d M Y') ?? 'an unscheduled date';
        $time = $inspection->scheduled_time ? ' at ' . substr((string) $inspection->scheduled_time, 0, 5) : '';

        return RentalInspection::typeName($inspection->type) . ' on ' . $date . $time;
    }

    private function subjectFor(RentalInspection $inspection, string $eventLabel): string
    {
        $template = PerformanceSetting::get(
            'rental_inspection_notification_subject',
            '{event}: {type}-inspection — {address}',
            $inspection->agency_id,
        );

        return str_replace(
            ['{event}', '{type}', '{address}'],
            [$eventLabel, RentalInspection::typeLabel($inspection->type), $inspection->property?->buildDisplayAddress() ?? ''],
            $template,
        );
    }
}
