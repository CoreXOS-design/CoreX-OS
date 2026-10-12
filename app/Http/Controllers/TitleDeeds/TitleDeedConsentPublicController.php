<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedConsentLink;
use App\Services\TitleDeeds\TitleDeedConsentService;
use Illuminate\Http\Request;

/**
 * .ai/specs/title-deed-register.md §8.3 (c) - the page an owner reaches from their personal consent link. No login: the
 * token is the credential, and it resolves ONLY its own request. Every dead link (wrong, used, expired, archived deed) is the
 * same plain page, so a stranger cannot tell which.
 */
class TitleDeedConsentPublicController extends Controller
{
    public function __construct(private TitleDeedConsentService $consents) {}

    public function show(Request $request, string $token)
    {
        $link = TitleDeedConsentLink::findLiveByToken($token);
        $consent = $link ? TitleDeedConsent::withoutGlobalScopes()->find($link->consent_id) : null;
        $deed = $consent ? TitleDeed::withoutGlobalScopes()->find($consent->title_deed_id) : null;

        if (! $link || ! $consent || ! $deed || $deed->trashed() || $deed->isCollected()) {
            return $this->page('title-deeds.public.consent', ['state' => 'invalid', 'message' => 'This link is not available.']);
        }

        $open = $link->used_at === null && $consent->status === TitleDeedConsent::STATUS_WAITING
            && $link->expires_at->isFuture() && $consent->expires_at->isFuture();

        if ($open) {
            $this->consents->recordOpen($token);

            return $this->page('title-deeds.public.consent', [
                'state' => 'open',
                'line' => $consent->rep_name . ', ID ending ' . $consent->repIdLast4() . ', is at ' . (Agency::withoutGlobalScopes()->find($deed->agency_id)?->name ?: 'our office')
                    . ' to collect your title deed for ' . $deed->property_display . '.',
                'token' => $token,
            ]);
        }

        $state = in_array($consent->status, [TitleDeedConsent::STATUS_APPROVED, TitleDeedConsent::STATUS_DECLINED, TitleDeedConsent::STATUS_USED], true) || $link->used_at !== null ? 'already' : 'expired';

        return $this->page('title-deeds.public.consent', [
            'state' => $state,
            'message' => $state === 'already' ? 'This has already been answered, so there is nothing more to do. Thank you.' : 'This request is no longer open. If it still matters, please contact the office.',
        ]);
    }

    public function respond(Request $request, string $token)
    {
        $decision = $request->input('decision') === 'approve' ? 'approve' : ($request->input('decision') === 'decline' ? 'decline' : null);
        if (! $decision) {
            return redirect()->route('title-deeds.consent.show', $token);
        }

        $result = $this->consents->respond($token, $decision, $request->ip(), $request->userAgent());

        return $this->page('title-deeds.public.consent', ['state' => $result['state'], 'message' => $result['message']]);
    }

    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
