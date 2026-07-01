<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single home for the manual-calendar-event CREATE + link-sync flow.
 *
 * Extracted from CalendarController so the web cockpit and the v1 mobile API
 * (CommandCenterApiController::calendarStore) build events through the SAME
 * code path. Before this existed, the mobile POST create ran a thin stub that
 * validated only singular property_id/contact_id and dropped attendees[] /
 * property_ids[] on the floor — so no invitations were sent and multi-property
 * links were never filed. Divergence like that is exactly the "half-built
 * feature" the CoreX standard forbids; centralising the flow here means a fix
 * or an added link-type reaches every ingress at once.
 */
class CalendarEventCreator
{
    /**
     * Create a manual calendar event with all of its property / contact /
     * attendee / deal links and agent invitations.
     *
     * Accepts the full create payload:
     *   title, category (required), event_date, end_date, description,
     *   property_id, property_ids[], contact_id, contact_ids[],
     *   attendees[].{id,type,role}, deal_id,
     *   and the optional mobile-only fields event_type, priority, all_day,
     *   send_reminder (the web cockpit omits these → web defaults apply).
     */
    public function create(array $data, User $user): CalendarEvent
    {
        // Resolve property_ids from either property_ids[] array or single property_id
        $propertyIds = $data['property_ids'] ?? (!empty($data['property_id']) ? [$data['property_id']] : []);
        $data['_resolved_property_ids'] = $propertyIds;

        // Class-config cap enforcement: reject multiple properties for single-property classes
        if (count($propertyIds) > 1) {
            $classConfig = CalendarEventClassSetting::withoutGlobalScopes()
                ->where('event_class', $data['category'])
                ->where(fn ($q) => $q->where('agency_id', $user->effectiveAgencyId())->orWhereNull('agency_id'))
                ->orderByRaw('agency_id IS NULL')
                ->first();
            if ($classConfig && !$classConfig->allow_multiple_properties) {
                $propertyIds = [array_shift($propertyIds)]; // Keep only first
                $data['_resolved_property_ids'] = $propertyIds;
            }
        }

        // For multi-property events: append count to title if user didn't already
        if (count($propertyIds) > 1 && !str_contains($data['title'], 'properties')) {
            $data['title'] = $data['title'] . ' — ' . count($propertyIds) . ' properties';
        }

        return DB::transaction(function () use ($data, $user, $propertyIds) {
            $eventDate = $data['event_date'];

            $event = CalendarEvent::create([
                'event_type'    => $data['event_type'] ?? 'manual',
                'category'      => $data['category'],
                'title'         => $data['title'],
                'description'   => ($data['description'] ?? '') ?: null,
                'event_date'    => $eventDate,
                'end_date'      => ($data['end_date'] ?? null) ?: null,
                // Web derives all_day from a midnight timestamp; the mobile API
                // may set it explicitly. Honour an explicit flag, else fall
                // back to the web-cockpit derivation so web behaviour is byte
                // identical.
                'all_day'       => array_key_exists('all_day', $data)
                    ? (bool) $data['all_day']
                    : (Carbon::parse($eventDate)->format('H:i:s') === '00:00:00'),
                'status'        => 'pending',
                'priority'      => $data['priority'] ?? 'normal',
                'send_reminder' => array_key_exists('send_reminder', $data) ? (bool) $data['send_reminder'] : true,
                'source_type'   => 'manual',
                'user_id'       => $user->id,
                'created_by_id' => $user->id,
                'agency_id'     => $user->agency_id ?: 1,
                'branch_id'     => $user->branch_id,
                'property_id'   => $data['property_id'] ?? ($propertyIds[0] ?? null),
                'contact_id'    => ($data['contact_ids'] ?? [])[0] ?? ($data['contact_id'] ?? null),
            ]);

            $this->syncEventLinks($event, $data, $user);

            return $event;
        });
    }

    /**
     * Sync calendar_event_links for a manual event.
     * Deletes existing user-created links and re-inserts from provided data.
     *
     * Public because the web cockpit's update() path re-syncs links on edit
     * through the same method — keeping one implementation for create AND
     * update.
     */
    public function syncEventLinks(CalendarEvent $event, array $data, $user): void
    {
        // Only delete link types that are being re-submitted (prevent edit-wipe bug)
        $rolesToSync = [];
        if (array_key_exists('property_ids', $data) || array_key_exists('property_id', $data) || array_key_exists('_resolved_property_ids', $data)) {
            $rolesToSync[] = CalendarEventLink::ROLE_SUBJECT_PROPERTY;
        }
        if (array_key_exists('attendees', $data) || array_key_exists('contact_ids', $data)) {
            $rolesToSync[] = CalendarEventLink::ROLE_ATTENDEE;
            $rolesToSync[] = 'buyer_contact';
            $rolesToSync[] = 'seller_contact';
            $rolesToSync[] = 'agent_contact';
        }
        if (array_key_exists('deal_id', $data)) {
            $rolesToSync[] = CalendarEventLink::ROLE_RELATED_DEAL;
        }

        if (!empty($rolesToSync)) {
            DB::table('calendar_event_links')
                ->where('calendar_event_id', $event->id)
                ->whereNotNull('created_by_user_id')
                ->whereIn('role', $rolesToSync)
                ->delete();
        }

        $links = [];
        $now = now();
        // CAL-3 — every calendar_event_links row carries the parent event's
        // agency_id. The column is NOT NULL (migration
        // 2026_05_23_080300_add_agency_id_to_calendar_event_links_table)
        // and DB::table()->insert() bypasses BelongsToAgency's creating
        // hook, so we source it explicitly. The event's agency_id is the
        // canonical link — a link row cannot meaningfully belong to a
        // different agency than the event it points at. Falls back to the
        // creating user's effective agency only in the (impossible-by-
        // schema) case where the event row arrives with NULL agency_id;
        // the fallback exists so a malformed input still produces a
        // user-clear validation failure rather than another raw 500.
        $agencyId = (int) ($event->agency_id ?? $user->effectiveAgencyId() ?? 0);

        // Multi-property support: use property_ids[] if available, else single property_id
        $propertyIds = $data['_resolved_property_ids'] ?? ($data['property_ids'] ?? []);
        if (empty($propertyIds) && !empty($data['property_id'])) {
            $propertyIds = [$data['property_id']];
        }
        foreach ($propertyIds as $pid) {
            $links[] = [
                'agency_id'          => $agencyId,
                'calendar_event_id'  => $event->id,
                'linkable_type'      => Property::class,
                'linkable_id'        => (int) $pid,
                'role'               => CalendarEventLink::ROLE_SUBJECT_PROPERTY,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        // Derive default contact role from event class actor_role
        $classConfig = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('event_class', $data['category'] ?? '')
            ->where(fn ($q) => $q->where('agency_id', $user->effectiveAgencyId())->orWhereNull('agency_id'))
            ->orderByRaw('agency_id IS NULL')
            ->first();
        // CAL-7 Class 1 — null-safe with explicit fallback. When the class
        // config row is missing on staging (no seed), `actor_role` falls
        // through to the default `attendee` role rather than throwing or
        // mis-typing the saved link. Both that role and any class-resolved
        // role end up in calendar_event_links.role; the read-side relation
        // (CAL-7 Class 3) no longer whitelists, so any of these surface
        // correctly on every read surface.
        $defaultRole = match ($classConfig?->actor_role ?? 'neither') {
            'buyer_action' => 'buyer_contact',
            'seller_action' => 'seller_contact',
            default => CalendarEventLink::ROLE_ATTENDEE,
        };

        foreach (($data['attendees'] ?? $data['contact_ids'] ?? []) as $attendee) {
            if (is_array($attendee)) {
                $type = ($attendee['type'] ?? 'contact') === 'agent' ? \App\Models\User::class : Contact::class;
                $id = $attendee['id'];
                // Use role from frontend if provided, else default from class config
                $role = $attendee['role'] ?? ($type === \App\Models\User::class ? 'agent_contact' : $defaultRole);
            } else {
                $type = Contact::class;
                $id = $attendee;
                $role = $defaultRole;
            }
            $links[] = [
                'agency_id'          => $agencyId,
                'calendar_event_id'  => $event->id,
                'linkable_type'      => $type,
                'linkable_id'        => $id,
                'role'               => $role,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (!empty($data['deal_id'])) {
            $links[] = [
                'agency_id'          => $agencyId,
                'calendar_event_id'  => $event->id,
                'linkable_type'      => \App\Models\DealV2\DealV2::class,
                'linkable_id'        => $data['deal_id'],
                'role'               => CalendarEventLink::ROLE_RELATED_DEAL,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (!empty($links)) {
            DB::table('calendar_event_links')->insert($links);
        }

        // Create invitations for user attendees (agents)
        foreach ($links as $link) {
            if (($link['linkable_type'] ?? '') === \App\Models\User::class && (int) ($link['linkable_id'] ?? 0) !== (int) $user->id) {
                $conflicts = app(\App\Services\CommandCenter\Calendar\ConflictDetectionService::class)
                    ->checkUserConflicts((int) $link['linkable_id'], $event->event_date->toDateTimeString(), ($event->end_date ?? $event->event_date)->toDateTimeString(), $event->id);

                \App\Models\CommandCenter\CalendarEventInvitation::updateOrCreate(
                    ['event_id' => $event->id, 'invitee_user_id' => $link['linkable_id']],
                    [
                        'inviter_user_id' => $user->id,
                        'status' => 'pending',
                        'conflict_at_invite' => !empty($conflicts) ? $conflicts : null,
                    ]
                );

                // Notify invitee
                DB::table('notifications')->insert([
                    'id' => \Illuminate\Support\Str::uuid(),
                    'type' => 'invitation_received',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id' => $link['linkable_id'],
                    'data' => json_encode([
                        'message' => $user->name . ' invited you to: ' . $event->title,
                        'event_id' => $event->id,
                        'has_conflict' => !empty($conflicts),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
