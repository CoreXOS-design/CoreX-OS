<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\IntentExtractionService;
use App\Services\AI\Intents\ScheduleEventIntentHandler;
use App\Services\AI\SpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MobileEllieVoiceController extends Controller
{
    public function __construct(
        private SpeechToTextService $stt,
        private IntentExtractionService $intent,
        private ScheduleEventIntentHandler $scheduleHandler,
    ) {}

    /**
     * POST /api/mobile/ellie/voice
     * Multipart: audio (required, audio/* up to ~5MB / 30s)
     *
     * Pipeline:
     *   1. local Whisper → transcript
     *   2. Claude Haiku 4.5 → intent + slots
     *   3. dispatch by intent (currently: schedule_event only) or fall back to chat
     */
    public function process(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->hasPermission('use_ellie_voice')) {
            return response()->json(['error' => 'Permission denied.'], 403);
        }
        if (! ($user->agency?->ai_voice_enabled)) {
            return response()->json(['error' => 'AI voice commands are not enabled for your agency.'], 403);
        }

        $request->validate([
            'audio' => 'required|file|max:5120', // 5MB
        ]);

        // 1. Transcribe
        try {
            $stt = $this->stt->transcribe($request->file('audio'));
        } catch (\Throwable $e) {
            Log::warning('EllieVoice: transcribe failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not transcribe audio: ' . $e->getMessage()], 422);
        }

        $transcript = trim($stt['text']);
        if ($transcript === '') {
            return response()->json([
                'transcript' => '',
                'intent'     => 'unknown',
                'message'    => "I didn't catch that — please try again.",
            ]);
        }

        // 2. Extract intent
        $intent = $this->intent->extract($transcript);

        // 3. Dispatch
        if ($intent['intent'] === 'schedule_event') {
            try {
                $result = $this->scheduleHandler->handle($intent['slots'], $user, $transcript);
                $event  = $result['event'];

                return response()->json([
                    'transcript'   => $transcript,
                    'intent'       => 'schedule_event',
                    'action'       => 'created',
                    'event_id'     => $event->id,
                    'event'        => [
                        'id'          => $event->id,
                        'title'       => $event->title,
                        'description' => $event->description,
                        'event_date'  => optional($event->event_date)->toIso8601String(),
                        'end_date'    => optional($event->end_date)->toIso8601String(),
                        'contact_id'  => $event->contact_id,
                        'property_id' => $event->property_id,
                        'created_by_ai' => true,
                    ],
                    'message' => sprintf(
                        'Scheduled "%s" for %s.',
                        $event->title,
                        optional($event->event_date)->format('D d M, H:i') ?: 'the requested time'
                    ),
                ], 201);
            } catch (\Throwable $e) {
                Log::warning('EllieVoice: schedule handler failed', ['error' => $e->getMessage()]);
                return response()->json([
                    'transcript' => $transcript,
                    'intent'     => 'schedule_event',
                    'action'     => 'failed',
                    'message'    => 'I heard "' . $transcript . '" but could not schedule it: ' . $e->getMessage(),
                ], 422);
            }
        }

        // Unknown intent — return transcript so the mobile can fall back to Ellie chat
        return response()->json([
            'transcript' => $transcript,
            'intent'     => 'unknown',
            'message'    => 'I heard you, but I am not sure what to do with that yet. Try: "Schedule a viewing tomorrow at 11."',
        ]);
    }

    /**
     * DELETE /api/mobile/ellie/voice/events/{event}
     * Soft-delete an AI-created event within the 30s undo window.
     */
    public function undoEvent(Request $request, int $eventId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->hasPermission('use_ellie_voice')) {
            return response()->json(['error' => 'Permission denied.'], 403);
        }

        $event = \App\Models\CommandCenter\CalendarEvent::query()
            ->where('id', $eventId)
            ->where('created_by_ai', true)
            ->where('created_by_id', $user->id)
            ->first();

        if (! $event) {
            return response()->json(['error' => 'Event not found or not undoable.'], 404);
        }

        // Only allow undo within 30s of creation
        if ($event->created_at && $event->created_at->diffInSeconds(now()) > 30) {
            return response()->json(['error' => 'Undo window expired.'], 410);
        }

        $event->delete(); // soft delete
        return response()->json(['message' => 'Undone.']);
    }
}
