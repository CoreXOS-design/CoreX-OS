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

        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        $unreadOnly = filter_var($request->query('unread', false), FILTER_VALIDATE_BOOL);

        $query = $unreadOnly ? $user->unreadNotifications() : $user->notifications();
        $notifications = $query->latest()->take($limit)->get();

        $items = $notifications->map(function ($n) {
            $data = $n->data ?? [];
            return [
                'id'         => $n->id,
                'type'       => $n->type,
                'event_key'  => $data['event_key'] ?? null,
                'pillar'     => $data['pillar'] ?? null,
                'title'      => $data['title'] ?? null,
                'body'       => $data['body'] ?? null,
                'subject'    => isset($data['subject_type']) ? [
                    'type'  => $data['subject_type'],
                    'id'    => $data['subject_id'] ?? null,
                    'label' => $data['subject_label'] ?? null,
                ] : null,
                'action_url' => $data['action_url'] ?? null,
                'severity'   => $data['severity'] ?? 'info',
                'read_at'    => optional($n->read_at)->toIso8601String(),
                'created_at' => optional($n->created_at)->toIso8601String(),
                'data'       => $data,
            ];
        });

        return response()->json([
            'items'  => $items,
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

    public function overdue(Request $request, \App\Services\CommandCenter\OverdueSnapshotService $service)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        return response()->json($service->forUser($user));
    }
}
