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
    /** Short link /u/{token} — the token's HMAC is the credential (see CampaignSendService::unsubscribeToken). */
    public function showShort(Request $request, string $token, CampaignSendService $sends): View|Response
    {
        return $this->renderConfirm($request, $sends->sendForUnsubscribeToken($token));
    }

    public function confirmShort(Request $request, string $token, CampaignSendService $sends): View|Response
    {
        return $this->doConfirm($sends->sendForUnsubscribeToken($token), $sends);
    }

    /** Long signed link — kept so emails already sent keep working. */
    public function show(Request $request, int $send): View|Response
    {
        return $this->renderConfirm($request, CampaignSend::find($send));
    }

    public function confirm(Request $request, int $send, CampaignSendService $sends): View|Response
    {
        return $this->doConfirm(CampaignSend::find($send), $sends);
    }

    private function renderConfirm(Request $request, ?CampaignSend $row): View|Response
    {
        if (! $row) {
            return response()->view('campaigns.public.unsubscribe', ['state' => 'missing'], 404);
        }

        return view('campaigns.public.unsubscribe', [
            'state' => $row->unsubscribed_at ? 'done' : 'confirm',
            'email' => $this->mask($row->to_email),
            'action' => $request->fullUrl(),
        ]);
    }

    private function doConfirm(?CampaignSend $row, CampaignSendService $sends): View|Response
    {
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
