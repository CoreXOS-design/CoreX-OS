<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Services\SellerOutreach\WhatsappOutreachSummaryService;
use Illuminate\Http\Request;

/**
 * AT-91 — WhatsApp Outreach Summary board.
 *
 * Read-only agents × outreach-states matrix. Page-level gate is the
 * permission:outreach.summary.view route middleware; the controller check
 * below is defence-in-depth. Row visibility is enforced inside the service by
 * ContactScope (agent → own, BM → branch, admin → all).
 *
 * Spec: .ai/specs/whatsapp-outreach-summary.md
 */
class WhatsappOutreachSummaryController extends Controller
{
    public function index(Request $request, WhatsappOutreachSummaryService $service)
    {
        abort_unless(
            $request->user()?->hasPermission('outreach.summary.view') === true,
            403
        );

        $board = $service->board();

        return view('corex.outreach-summary.index', [
            'rows' => $board['rows'],
            'totals' => $board['totals'],
            'hasAwaiting' => $board['has_awaiting'],
        ]);
    }
}
