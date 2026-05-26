<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\WhistleblowEmailLog;
use Illuminate\Http\Request;

class CommunicationsLogController extends Controller
{
    public function index(Request $request)
    {
        $query = WhistleblowEmailLog::with('sentBy')
            ->orderByDesc('sent_at');

        if ($request->filled('type')) {
            $query->where('email_type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $logs = $query->paginate(25);

        return view('compliance.communications.index', compact('logs'));
    }
}
