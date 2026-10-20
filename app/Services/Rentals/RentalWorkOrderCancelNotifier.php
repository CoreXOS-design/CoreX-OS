<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalJobCancelledMail;
use App\Models\Agency;
use App\Models\RentalJobDispatch;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Audit fix C-A4 (19 Oct 2026) - rentals-faults-work-orders.md section 29: cancelling a work order used to tell nobody. The contractor / crew who were EMAILED the job (the
 * dispatch ledger is the record of who that was - the same one the owner's "On hold" notice uses) are told not to attend, and the tenant is told when an appointment had been
 * arranged with them. A contractor reached only by WhatsApp cannot be emailed: the history line says "phone them". ONE history line records exactly who was told. Called
 * from RentalWorkOrder::cancel(), which runs once per work order (a second cancel is refused), so each person is told once. Never throws: a mail failure must not undo a cancel.
 */
final class RentalWorkOrderCancelNotifier
{
    public function notify(RentalWorkOrder $wo): void
    {
        try {
            $address = $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id);
            $agency = Agency::displayNameFor($wo->agency, '');
            $rows = RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->orderBy('id')->get()->filter(fn ($r) => $r->counted());
            $emails = $rows->where('channel', RentalJobDispatch::CHANNEL_EMAIL)->filter(fn ($r) => filter_var((string) $r->destination, FILTER_VALIDATE_EMAIL))
                ->unique(fn ($r) => strtolower((string) $r->destination));
            $phoneOnly = $rows->where('channel', RentalJobDispatch::CHANNEL_WHATSAPP)
                ->reject(fn ($r) => $emails->contains(fn ($e) => $e->recipient_kind === $r->recipient_kind && $e->recipient_name === $r->recipient_name));

            $told = [];
            foreach ($emails as $r) {
                Mail::to($r->destination)->send(new RentalJobCancelledMail($r->recipient_name ?: 'there', $address, (string) $wo->title, $agency, 'worker'));
                $told[] = $r->recipient_name ?: $r->destination;
            }
            // a work order that was only ever "sent" through the contractor's own record (no ledger row) still tells that contractor
            if ($emails->isEmpty() && $wo->assignment_type === RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER && $wo->ordered_at) {
                $provider = $wo->supplier()->with('serviceContacts')->first();
                $contact = $provider?->serviceContacts->first();
                $email = $contact?->email ?: $provider?->email;
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($email)->send(new RentalJobCancelledMail((string) ($contact?->name ?: $provider->name), $address, (string) $wo->title, $agency, 'worker'));
                    $told[] = (string) ($contact?->name ?: $provider->name);
                }
            }

            $tenantsTold = [];
            if ($wo->appointment_at && $wo->lease_id && RentalPortalSetting::notifyTenantOnStatusChangeFor($wo->agency_id)) {
                foreach ($wo->lease?->tenantContacts() ?? [] as $tenant) {
                    if ($tenant->email && filter_var($tenant->email, FILTER_VALIDATE_EMAIL)) {
                        Mail::to($tenant->email)->send(new RentalJobCancelledMail($tenant->first_name ?: 'there', $address, (string) $wo->title, $agency, 'tenant'));
                        $tenantsTold[] = trim(($tenant->first_name ?? '') . ' ' . ($tenant->last_name ?? '')) ?: $tenant->email;
                    }
                }
            }

            $notes = [];
            if ($told) {
                $notes[] = 'Emailed "Job cancelled - do not attend" to ' . implode(', ', array_unique($told)) . '.';
            }
            if ($phoneOnly->isNotEmpty()) {
                $notes[] = 'Phone ' . $phoneOnly->pluck('recipient_name')->filter()->unique()->implode(', ') . ' - the job reached them by WhatsApp, so they could not be emailed.';
            }
            if ($tenantsTold) {
                $notes[] = 'Emailed the tenant (' . implode(', ', $tenantsTold) . ') that the appointment will not go ahead.';
            }
            if ($notes) {
                $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'note', 'note' => implode(' ', $notes)]);
            }
        } catch (\Throwable $e) {
            Log::warning('Work order cancel: could not tell the contractor / crew / tenant', ['work_order' => $wo->id, 'error' => $e->getMessage()]);
        }
    }
}
