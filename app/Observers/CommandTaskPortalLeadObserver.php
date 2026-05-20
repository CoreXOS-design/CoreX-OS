<?php

namespace App\Observers;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors every PP lead (created by app/Http/Controllers/PrivateProperty/PpWebhookController)
 * into the portal_leads table so the unified Portal Leads UI and the
 * property Intelligence panel can see both portals.
 *
 * This observer is the ONLY integration point with PP — the PP files
 * themselves remain untouched. The hook is `created` on CommandTask,
 * filtered to source_type='private_property_webhook' which the PP
 * webhook controller sets on the follow-up task it generates.
 *
 * NOTE: lead_source_raw for PP rows is a reconstruction from the task +
 * contact, NOT the original webhook body — we cannot capture that without
 * modifying PP code.
 */
class CommandTaskPortalLeadObserver
{
    public function created(CommandTask $task): void
    {
        if ($task->source_type !== 'private_property_webhook') {
            return;
        }

        try {
            $contact  = $task->contact_id ? Contact::query()->withoutGlobalScopes()->find($task->contact_id) : null;
            $property = $task->property_id ? Property::query()->withoutGlobalScopes()->find($task->property_id) : null;

            // contact_exists check: is there ANOTHER (older) contact in this agency with the same email/phone?
            $exists = false;
            $existingAgentId = null;
            if ($contact && ($contact->email || $contact->phone)) {
                $earlier = Contact::query()
                    ->withoutGlobalScopes()
                    ->where('agency_id', $task->agency_id)
                    ->where('id', '!=', $contact->id)
                    ->where(function ($q) use ($contact) {
                        if ($contact->email) $q->orWhere('email', $contact->email);
                        if ($contact->phone) $q->orWhere('phone', $contact->phone);
                    })
                    ->orderBy('id')
                    ->first();
                if ($earlier) {
                    $exists = true;
                    $existingAgentId = $earlier->created_by_user_id;
                }
            }

            $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: ($task->title ?? 'Unknown');

            $raw = [
                '__corex_reconstructed' => true,
                '__source'              => 'pp_command_task_observer',
                'task_id'               => $task->id,
                'task_title'            => $task->title,
                'task_description'      => $task->description,
                'contact_id'            => $contact?->id,
                'property_id'           => $property?->id,
                'listing_reference'     => $property?->id, // PP uses external ref = property id
            ];

            $lead = new PortalLead([
                'agency_id'                => $task->agency_id,
                'portal'                   => PortalLead::PORTAL_PP,
                'lead_type'                => 'Email',
                'listing_id'               => $property?->id,
                'listing_portal_ref'       => $property ? (string) $property->id : null,
                'contact_id'               => $contact?->id,
                'contact_exists'           => $exists,
                'existing_contact_agent_id'=> $exists ? $existingAgentId : null,
                'name'                     => $name,
                'email'                    => $contact?->email,
                'phone'                    => $contact?->phone,
                'message'                  => $task->description,
                'is_whatsapp'              => false,
                'lead_source_raw'          => $raw,
                'received_at'              => $task->created_at ?: now(),
            ]);
            $lead->agency_id = $task->agency_id;
            $lead->save();

            event(new NewPortalLeadReceived($lead));
        } catch (\Throwable $e) {
            // Never break PP ingest — log and swallow.
            Log::channel('single')->error('PP→portal_leads mirror failed', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
