<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\FicaSubmission;
use App\Services\Compliance\ApprovalQueueCounts;
use App\Services\Docuperfect\EsignApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/approvals/pending — the polling feed behind the approvals toast. Spec §8.5.
 *
 * Self-scoped: only items the authenticated user may act on, inside their own / branch / all
 * scope, created in the last 24 hours. Dismissal is client-side; nothing here is mutated.
 */
class ApprovalsController extends Controller
{
    public function __construct(
        private ApprovalQueueCounts $counts,
        private EsignApprovalService $esign,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        $user     = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $since    = now()->subDay();
        $items    = [];

        if ($agencyId > 0) {
            $summary = $this->counts->forUser($user);

            if ($summary['esign'] > 0) {
                foreach ($this->esign->queueQuery($user)->where('esign_approvals.created_at', '>=', $since)->limit(20)->get() as $a) {
                    $items[] = [
                        'id'         => 'esign-' . $a->id,
                        'kind'       => 'document',
                        'label'      => 'DOCUMENT',
                        'title'      => $a->signatureTemplate?->document?->name ?? 'Untitled document',
                        'body'       => ($a->requester?->name ?? 'An agent') . ' is waiting for approval to send',
                        'url'        => route('docuperfect.approvals.index'),
                        'created_at' => optional($a->created_at)->toIso8601String(),
                    ];
                }
            }

            if ($summary['fica']['ro'] > 0 || $summary['fica']['co'] > 0) {
                $statuses = [];
                if ($summary['fica']['ro'] > 0) $statuses[] = 'agent_approved';
                if ($summary['fica']['co'] > 0) $statuses[] = 'referred_to_co';
                $rows = FicaSubmission::whereIn('status', $statuses)->visibleTo($user)
                    ->where('updated_at', '>=', $since)->with('contact')->latest('updated_at')->limit(20)->get();
                foreach ($rows as $s) {
                    $tab = $s->status === 'referred_to_co' ? 'co_queue' : 'ro_queue';
                    $items[] = [
                        'id'         => 'fica-' . $s->id . '-' . $s->status,
                        'kind'       => 'fica',
                        'label'      => 'FICA',
                        'title'      => trim((string) (optional($s->contact)->first_name . ' ' . optional($s->contact)->last_name)) ?: 'A contact',
                        'body'       => $s->status === 'referred_to_co' ? 'Referred to you for a decision' : 'Waiting for reviewer approval',
                        'url'        => url('/corex/compliance/fica?tab=' . $tab),
                        'created_at' => optional($s->updated_at)->toIso8601String(),
                    ];
                }
            }

            if ($summary['whistleblow'] > 0) {
                $rows = WhistleblowComplaint::where('status', 'pending_approval')->visibleTo($user)
                    ->where('created_at', '>=', $since)->with('reporter')->latest('created_at')->limit(20)->get();
                foreach ($rows as $c) {
                    $items[] = [
                        'id'         => 'wb-' . $c->id,
                        'kind'       => 'report',
                        'label'      => 'COMPLIANCE REPORT',
                        'title'      => 'CDX-WB-' . $c->id . ' · ' . ($c->property_address ?: 'a property'),
                        'body'       => ($c->reporter?->name ?? 'An agent') . ' filed a report that needs your decision',
                        'url'        => route('compliance.whistleblow.show', $c),
                        'created_at' => optional($c->created_at)->toIso8601String(),
                    ];
                }
            }
        }

        return response()->json(['items' => $items]);
    }
}
