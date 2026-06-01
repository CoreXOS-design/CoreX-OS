<?php

namespace App\Services\AI\Intents;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\CalendarEventService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles the "schedule_event" intent from IntentExtractionService.
 * Creates a CalendarEvent flagged as AI-created (created_by_ai = true).
 *
 * The created event is fully visible to the agent with an AI badge so
 * they can review/undo within 30s — preserves the "Ellie advises, humans
 * decide" principle in the narrow, reversible, audit-tagged form approved
 * in .ai/specs/ellie-voice.md.
 */
class ScheduleEventIntentHandler
{
    public function __construct(private CalendarEventService $calendar) {}

    /**
     * @param array $slots from IntentExtractionService::extract()['slots']
     * @return array{event: CalendarEvent, matched_contact: ?Contact, matched_property: ?Property}
     */
    public function handle(array $slots, User $user, string $transcript): array
    {
        $datetimeStr = (string) ($slots['datetime'] ?? '');
        $title       = trim((string) ($slots['title'] ?? '')) ?: 'Appointment';
        $duration    = max(15, (int) ($slots['duration_minutes'] ?? 60));

        try {
            $eventDate = Carbon::parse($datetimeStr);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Could not parse datetime from voice command: ' . $datetimeStr);
        }
        $endDate = (clone $eventDate)->addMinutes($duration);

        $contact  = $this->matchContact((string) ($slots['contact_name'] ?? ''), $user);
        $property = $this->matchProperty((string) ($slots['property_ref'] ?? ''), $user);

        $event = $this->calendar->createManual([
            'agency_id'     => $user->agency_id,
            'branch_id'     => $user->branch_id ?? null,
            'event_type'    => 'manual',
            // 'manual' is in calendar_event_class_settings so it passes
            // the web cockpit's whereIn('category', $visibleClassKeys)
            // filter. AI attribution is carried by created_by_ai +
            // ai_source instead — that's how the AI badge renders.
            'category'      => 'manual',
            'title'         => $title,
            'description'   => trim((string) ($slots['notes'] ?? '')) ?: null,
            'event_date'    => $eventDate,
            'end_date'      => $endDate,
            'all_day'       => false,
            'priority'      => 'normal',
            'send_reminder' => true,
            'contact_id'    => $contact?->id,
            'property_id'   => $property?->id,
            'created_by_ai' => true,
            'ai_source'     => 'ellie_voice',
            'ai_transcript' => $transcript,
            'colour'        => CalendarEvent::TYPE_COLOURS['manual'] ?? null,
        ], $user);

        Log::info('Ellie voice: created calendar event', [
            'event_id'    => $event->id,
            'user_id'     => $user->id,
            'contact_id'  => $contact?->id,
            'property_id' => $property?->id,
        ]);

        return [
            'event'            => $event,
            'matched_contact'  => $contact,
            'matched_property' => $property,
        ];
    }

    private function matchContact(string $name, User $user): ?Contact
    {
        $name = trim($name);
        if ($name === '') return null;

        return Contact::query()
            ->where(function ($q) use ($name) {
                $q->where('first_name', 'like', '%' . $name . '%')
                  ->orWhere('last_name', 'like', '%' . $name . '%')
                  ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", ['%' . $name . '%']);
            })
            ->first();
    }

    private function matchProperty(string $ref, User $user): ?Property
    {
        $ref = trim($ref);
        if ($ref === '') return null;

        return Property::query()
            ->where(function ($q) use ($ref) {
                $q->where('address', 'like', '%' . $ref . '%')
                  ->orWhere('suburb', 'like', '%' . $ref . '%');
            })
            ->first();
    }
}
