<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealStageMove;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Http\Request;

/**
 * AT-158 WS-V2 — the deal-stage gate actions: confirm a pending prompt-mode
 * move, undo an applied move, or dismiss a prompt. All scope-gated to the
 * actor's permitted deals (own/branch/all) exactly like step completion.
 */
class DealStageController extends Controller
{
    public function __construct(private DealPipelineService $pipeline) {}

    public function confirm(Request $request, DealStageMove $move)
    {
        $this->authorizeMove($move);
        $this->pipeline->confirmStageMove($move, $request->user());

        return redirect()->route('deals-v2.show', $move->deal_id)
            ->with('status', 'Deal moved to "' . ucfirst($move->to_status) . '".');
    }

    public function undo(Request $request, DealStageMove $move)
    {
        $this->authorizeMove($move);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->pipeline->undoStageMove($move, $request->user(), $data['reason'] ?? null);

        return redirect()->route('deals-v2.show', $move->deal_id)
            ->with('status', 'Stage move undone — deal returned to "' . ucfirst($move->from_status) . '".');
    }

    public function dismiss(Request $request, DealStageMove $move)
    {
        $this->authorizeMove($move);
        $this->pipeline->dismissStageMove($move, $request->user());

        return redirect()->route('deals-v2.show', $move->deal_id)
            ->with('status', 'Stage prompt dismissed.');
    }

    /** The actor must hold deals_v2.edit AND have this deal within their scope. */
    private function authorizeMove(DealStageMove $move): void
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('deals_v2.edit'), 403);
        abort_unless(
            DealV2::query()->whereKey($move->deal_id)->visibleTo($user)->exists(),
            403,
            'You can only manage stage moves on deals within your scope.'
        );
    }
}
