<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['items' => [], 'unread' => 0]);
        }

        $notifications = $user->notifications()
            ->latest()
            ->take(20)
            ->get();

        return response()->json([
            'items'  => $notifications,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        $notification = $user->notifications()->where('id', $id)->first();
        $notification?->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false], 401);
        }

        $user->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }
}
