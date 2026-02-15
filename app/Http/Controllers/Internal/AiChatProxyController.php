<?php

namespace App\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class AiChatProxyController extends Controller
{
    public function chat(Request $request)
    {
        // Must be logged in (route will be protected by auth middleware)
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $message = (string)($request->input('message') ?? '');
        if (trim($message) === '') {
            return response()->json(['error' => 'Missing message'], 422);
        }

        // Forward to local AI service
        $resp = Http::timeout(120)
            ->acceptJson()
            ->asJson()
            ->post('http://127.0.0.1:3100/chat', [
                'message' => $message,
                // optional identity/context hooks for later:
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role ?? null,
                    'branch_id' => $user->branch_id ?? null,
                ],
            ]);

        if (!$resp->successful()) {
            return response()->json([
                'error' => 'AI service error',
                'status' => $resp->status(),
                'body' => $resp->body(),
            ], 502);
        }

        return response($resp->body(), 200)->header('Content-Type', 'application/json');
    }
}
