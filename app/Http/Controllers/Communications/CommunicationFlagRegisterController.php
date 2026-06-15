<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationFlag;
use App\Models\Communications\CommunicationFlagAlert;
use Illuminate\Http\Request;

/**
 * BM triage audit register (AT-36, addendum §5). Read-only, spreadsheet-style:
 * who discarded/classified what, when, the AI verdict (Phase B), and whether a
 * later agent or the machine disagreed. NO message content anywhere here — the
 * communication_flags table stores none.
 */
class CommunicationFlagRegisterController extends Controller
{
    public function index(Request $request)
    {
        $query = CommunicationFlag::query()->with(['user', 'contradictedBy']);

        if ($flag = $request->query('flag')) {
            $query->where('flag', $flag);
        }
        if ($request->query('contradicted') === '1') {
            $query->whereNotNull('contradicted_at');
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('identifier', 'like', "%{$search}%")
                  ->orWhere('identifier_name', 'like', "%{$search}%");
            });
        }

        $flags = $query->orderByDesc('flagged_at')->paginate(50)->withQueryString();
        $openAlerts = CommunicationFlagAlert::query()->open()->count();

        return view('communications.flag-register.index', [
            'flags'      => $flags,
            'openAlerts' => $openAlerts,
            'flag'       => $flag ?? null,
            'search'     => $search,
        ]);
    }
}
