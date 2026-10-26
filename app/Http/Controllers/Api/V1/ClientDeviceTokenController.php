<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 (P1) — a tenant / owner registers (or removes) their phone for push.
 * Mirror of Api\DeviceTokenController, keyed to the ClientUser. Spec: .ai/specs/at-508-mobile-rentals.md §4 P1.
 */
class ClientDeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform'    => 'required|in:ios,android,web',
            'token'       => 'required|string|max:512',
            'app_version' => 'sometimes|nullable|string|max:32',
        ]);

        $client = $request->user();
        $token  = trim($data['token']);

        // One active row per physical handset: a re-login on the same phone supersedes any other owner of the token.
        DeviceToken::where('token', $token)
            ->where(fn ($q) => $q->where('client_user_id', '!=', $client->id)->orWhereNull('client_user_id'))
            ->delete();

        $row = DeviceToken::withTrashed()->where('client_user_id', $client->id)->where('token', $token)->first()
            ?? new DeviceToken(['client_user_id' => $client->id, 'token' => $token]);
        if ($row->exists && $row->trashed()) {
            $row->restore();
        }
        $row->fill(['platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null, 'last_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        DeviceToken::where('client_user_id', $request->user()->id)->where('token', trim($token))->delete();

        return response()->json(['ok' => true]);
    }
}
