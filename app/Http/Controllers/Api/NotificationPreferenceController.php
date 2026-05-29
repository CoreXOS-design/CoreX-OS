<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CommandCenter\NotificationPreferenceService;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferenceService $service) {}

    public function index(Request $request)
    {
        return response()->json($this->service->snapshot($request->user()));
    }

    public function update(Request $request)
    {
        $payload = $request->validate([
            'master'                       => 'sometimes|array',
            'master.in_app'                => 'sometimes|boolean',
            'master.email'                 => 'sometimes|boolean',
            'master.push'                  => 'sometimes|boolean',
            'preferences'                  => 'sometimes|array',
            'preferences.*.key'            => 'required_with:preferences|string',
            'preferences.*.enabled'        => 'sometimes|boolean',
            'preferences.*.threshold'      => 'sometimes|nullable|integer|min:0',
            'preferences.*.channel_in_app' => 'sometimes|boolean',
            'preferences.*.channel_email'  => 'sometimes|boolean',
            'preferences.*.channel_push'   => 'sometimes|boolean',
            'open_hours'                   => 'sometimes|array',
            'open_hours.enabled'           => 'sometimes|boolean',
            'open_hours.start'             => 'sometimes|date_format:H:i',
            'open_hours.end'               => 'sometimes|date_format:H:i',
            'cooldown_minutes'             => 'sometimes|integer|min:0|max:10080',
        ]);

        $saved = $this->service->applyUpdates($request->user(), $payload);
        return response()->json(['ok' => true, 'saved' => $saved]);
    }
}
