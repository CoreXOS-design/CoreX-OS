<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Services\SystemUpdateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What's New — the user-facing archive of system updates.
 *
 * Spec: .ai/specs/system-updates.md §7.5.
 *
 * Exists because a user who closes the pop-up in a hurry otherwise has no way back
 * to the information (the standing CoreX rule against dead ends), and because it is
 * where the modal's overflow goes when more than the cap are pending.
 *
 * Read-only, auth-only. The eligibility filter (audience + joined-before rule) is
 * applied SERVER-SIDE via the same service the modal uses — an admin-only update
 * must never reach a non-admin's archive.
 */
class WhatsNewController extends Controller
{
    public function index(Request $request, SystemUpdateService $service): View
    {
        $user = $request->user();

        $updates = $service->archiveFor($user);

        // Which of them are still unacknowledged — drives the "New" chip.
        $pendingIds = $service->pendingFor($user)->pluck('id')->all();

        // Type filter (spec §5). An unknown value simply filters nothing out.
        $type = (string) $request->query('type', '');
        if ($type !== '' && array_key_exists($type, config('system-updates.types', []))) {
            $updates = $updates->where('type', $type)->values();
        } else {
            $type = '';
        }

        return view('corex.whats-new.index', [
            'updates'    => $updates,
            'pendingIds' => $pendingIds,
            'activeType' => $type,
        ]);
    }
}
