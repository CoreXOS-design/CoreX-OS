<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'platform'    => 'required|in:ios,android,web',
            'token'       => 'required|string|max:512',
            'app_version' => 'sometimes|nullable|string|max:32',
        ]);

        $user  = $request->user();
        $token = trim($data['token']);

        // One active row per physical device. A single FCM token can only belong
        // to one app install at a time, so when it registers here it supersedes
        // any OTHER user's active row for the same token (e.g. after a re-login on
        // the same handset). Without this, an agency-wide fan-out resolves the same
        // token under two users and buzzes the device twice per event.
        DeviceToken::where('token', $token)
            ->where('user_id', '!=', $user->id)
            ->delete();

        // updateOrCreate excludes soft-deleted rows, so a previously-removed
        // (user, token) row would force an INSERT that violates the
        // (user_id, token) unique index — a 500. Look it up WITH trashed and
        // revive instead. This is idempotent: registering the same token N times
        // never creates duplicate rows.
        $existing = DeviceToken::withTrashed()
            ->where('user_id', $user->id)
            ->where('token', $token)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->platform     = $data['platform'];
            $existing->app_version  = $data['app_version'] ?? null;
            $existing->last_seen_at = now();
            $existing->save();
        } else {
            DeviceToken::create([
                'user_id'      => $user->id,
                'platform'     => $data['platform'],
                'token'        => $token,
                'app_version'  => $data['app_version'] ?? null,
                'last_seen_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $token)
    {
        $user = $request->user();
        DeviceToken::where('user_id', $user->id)->where('token', trim($token))->delete();
        return response()->json(['ok' => true]);
    }
}
