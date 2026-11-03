<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaigns\CampaignSend;
use App\Services\Campaigns\CampaignSendService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * AT-534 — the public unsubscribe link in every campaign email (.ai/specs/campaigns.md §6.4). The `signed` middleware is the
 * credential. GET never writes (mail scanners pre-open links); POST unsubscribes — it is also the RFC 8058 one-click target.
 */
class PublicUnsubscribeController extends Controller
{
    public function show(Request $request, int $send): View|Response
    {
        $row = CampaignSend::find($send);
        if (! $row) {
            return response()->view('campaigns.public.unsubscribe', ['state' => 'missing'], 404);
        }

        return view('campaigns.public.unsubscribe', [
            'state' => $row->unsubscribed_at ? 'done' : 'confirm',
            'email' => $this->mask($row->to_email),
            'action' => $request->fullUrl(),
        ]);
    }

    public function confirm(Request $request, int $send, CampaignSendService $sends): View|Response
    {
        $row = CampaignSend::find($send);
        if (! $row) {
            return response()->view('campaigns.public.unsubscribe', ['state' => 'missing'], 404);
        }
        $first = $sends->unsubscribe($row);

        return view('campaigns.public.unsubscribe', [
            'state' => $first ? 'unsubscribed' : 'done',
            'email' => $this->mask($row->to_email),
        ]);
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = mb_substr($local, 0, 2);

        return $shown . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
    }
}
