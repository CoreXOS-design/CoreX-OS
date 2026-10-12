<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TitleDeeds\Concerns\GuardsTitleDeedForms;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\TitleDeeds\TitleDeedSigningLink;
use App\Services\TitleDeeds\TitleDeedFormGuard;
use App\Services\TitleDeeds\TitleDeedSigningService;
use App\Services\TitleDeeds\TitleDeedConsentService;
use App\Services\TitleDeeds\TitleDeedHandoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/title-deed-register.md §8.3 — the handover screen and its actions. The deed is resolved through
 * TitleDeed::visibleTo() on every route (OWN / BRANCH / AGENCY); the handover itself re-checks everything server-side.
 */
class TitleDeedHandoverController extends Controller
{
    use GuardsTitleDeedForms;

    public function __construct(
        private TitleDeedHandoverService $handover,
        private TitleDeedConsentService $consents,
        private TitleDeedSigningService $signing,
    ) {}

    public function show(Request $request, int $id)
    {
        $deed = $this->find($request, $id);
        if ($deed->isCollected()) {
            return redirect()->route('title-deeds.show', $deed->id)->with('info', 'This title deed has already been collected.');
        }

        $deed->load('agent');
        $context = $this->handover->context($request->user(), $deed);
        $consent = $context['consent'];

        // WhatsApp messages still waiting for a person (to open them, or to say whether they sent them): consent requests for the
        // current request, and the signing link.
        $prepared = TitleDeedEvent::query()
            ->where('title_deed_id', $deed->id)
            ->whereIn('kind', [TitleDeedEvent::KIND_CONSENT_REQUEST, TitleDeedEvent::KIND_SIGNING_LINK])
            ->where('channel', TitleDeedEvent::CHANNEL_WHATSAPP)
            ->whereIn('status', [TitleDeedEvent::STATUS_PREPARED, TitleDeedEvent::STATUS_OPENED])
            ->orderBy('id')->get()
            ->filter(fn ($e) => $e->kind !== TitleDeedEvent::KIND_CONSENT_REQUEST || ! $consent['consent_id'] || (int) $e->consent_id === (int) $consent['consent_id'])
            ->values();

        $link = TitleDeedSigningLink::query()->where('title_deed_id', $deed->id)->whereIn('status', [TitleDeedSigningLink::STATUS_WAITING, TitleDeedSigningLink::STATUS_SIGNED])->latest('id')->first();
        $linkState = $link ? $this->signing->status($deed) : null;
        $guard = app(TitleDeedFormGuard::class);
        $user = $request->user();

        return view('title-deeds.handover', [
            'deed' => $deed,
            'ctx' => $context,
            'prepared' => $prepared,
            'pollUrl' => route('api.v1.title-deeds.consent-status', $deed->id),
            'signingPollUrl' => route('api.v1.title-deeds.signing-status', $deed->id),
            'link' => $link,
            'linkState' => $linkState,
            'linkQr' => $link && $link->isLive() ? $this->signing->qrDataUri($link) : null,
            'linkUrl' => $link && $link->isLive() ? $link->url() : null,
            'signByLink' => $context['policy']->signByLink,
            'tokens' => [
                'handover' => $guard->issue($user, 'handover', $deed->id),
                'handover_link' => $guard->issue($user, 'handover_link', $deed->id),
                'consent' => $guard->issue($user, 'consent', $deed->id),
                'phone_consent' => $guard->issue($user, 'phone_consent', $deed->id),
                'link_email' => $guard->issue($user, 'link_email', $deed->id),
                'link_whatsapp' => $guard->issue($user, 'link_whatsapp', $deed->id),
            ],
        ]);
    }

    public function submit(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);

        $request->validate([
            'method' => ['nullable', 'in:pin,id_check,representative'],
            'signing_link_id' => ['nullable', 'integer'],
            'send_email' => ['nullable', 'string', 'max:191'],
            'send_mobile' => ['nullable', 'string', 'max:40'],
            'pin' => ['nullable', 'string', 'max:20'],
            'collector_type' => ['nullable', 'in:owner,representative'],
            'collector_contact_id' => ['nullable', 'integer'],
            'rep_name' => ['nullable', 'string', 'max:191'],
            'rep_id_number' => ['nullable', 'string', 'max:40'],
            'rep_relationship' => ['nullable', 'string', 'max:100'],
            'id_number_seen' => ['nullable', 'string', 'max:40'],
            'consent_id' => ['nullable', 'integer'],
            'typed_name' => ['nullable', 'string', 'max:191'],
            'signature_image' => ['nullable', 'string', 'max:1450000'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'authority_letter' => ['nullable', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
            'collector_id_photo' => ['nullable', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ], [
            'authority_letter.mimes' => 'The authority letter must be a PDF or a photo.',
            'collector_id_photo.mimes' => "The ID photo must be a photo or a PDF.",
        ]);

        if ($redirect = $this->claimForm($request, $request->filled('signing_link_id') ? 'handover_link' : 'handover', $deed->id, route('title-deeds.show', $deed->id))) {
            return $redirect;
        }

        try {
            $this->handover->handover($request->user(), $deed, $request->all() + [
                'authority_letter' => $request->file('authority_letter'),
                'collector_id_photo' => $request->file('collector_id_photo'),
            ], ['ip' => $request->ip(), 'user_agent' => $request->userAgent()]);
        } catch (TitleDeedException $e) {
            if ($e->reason === TitleDeedException::ALREADY_COLLECTED) {
                return redirect()->route('title-deeds.show', $deed->id)->with('info', $e->getMessage());
            }

            return redirect()->route('title-deeds.handover', $deed->id)
                ->withInput($request->except(['pin', 'signature_image', 'authority_letter', 'collector_id_photo']))
                ->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.show', $deed->id)->with('success', 'Handed over and recorded.');
    }

    public function requestConsent(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);
        if ($redirect = $this->claimForm($request, 'consent', $deed->id, route('title-deeds.handover', $deed->id))) {
            return $redirect;
        }
        $rep = $request->validate([
            'rep_name' => ['required', 'string', 'max:191'],
            'rep_id_number' => ['required', 'string', 'max:40'],
            'rep_relationship' => ['required', 'string', 'max:100'],
        ], [
            'rep_name.required' => "Enter the representative's full name.",
            'rep_id_number.required' => "Enter the representative's ID number.",
            'rep_relationship.required' => "Enter the representative's relationship to the owner.",
        ]);

        try {
            $result = $this->consents->request($request->user(), $deed, $rep);
        } catch (TitleDeedException $e) {
            return redirect()->route('title-deeds.handover', $deed->id)->withInput()->with('error', $e->getMessage());
        }

        $s = $result['summary'];
        $bits = array_filter([
            $s['sent'] ? $s['sent'] . ' emailed' : null,
            $s['prepared'] ? $s['prepared'] . ' WhatsApp ready to send' : null,
            $s['failed'] ? $s['failed'] . ' failed' : null,
            $s['skipped'] ? $s['skipped'] . ' skipped' : null,
        ]);

        return redirect()->route('title-deeds.handover', $deed->id)->withInput($rep)
            ->with(($s['sent'] + $s['prepared']) > 0 ? 'success' : 'error', 'Owners asked to confirm: ' . ($bits ? implode(', ', $bits) : 'nobody could be reached') . '.');
    }

    public function cancelConsent(Request $request, int $id, int $consent): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $row = TitleDeedConsent::query()->where('title_deed_id', $deed->id)->findOrFail($consent);
        $this->consents->cancel($request->user(), $deed, $row);

        return redirect()->route('title-deeds.handover', $deed->id)->with('success', 'The request was withdrawn.');
    }

    public function phoneConsent(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);
        if ($redirect = $this->claimForm($request, 'phone_consent', $deed->id, route('title-deeds.handover', $deed->id))) {
            return $redirect;
        }
        $v = $request->validate([
            'rep_name' => ['required', 'string', 'max:191'],
            'rep_id_number' => ['required', 'string', 'max:40'],
            'rep_relationship' => ['required', 'string', 'max:100'],
            'called_contact_id' => ['required', 'integer'],
            'called_phone_id' => ['required', 'integer', 'min:0'],
            'spoke_to' => ['required', 'string', 'max:191'],
            'called_at' => ['nullable', 'date'],
        ], [
            'called_contact_id.required' => 'Pick which owner you called.',
            'called_phone_id.required' => 'Pick which stored number you called.',
            'spoke_to.required' => 'Say who you spoke to.',
        ]);

        try {
            $this->consents->recordPhoneConsent(
                $request->user(), $deed,
                ['rep_name' => $v['rep_name'], 'rep_id_number' => $v['rep_id_number'], 'rep_relationship' => $v['rep_relationship']],
                (int) $v['called_contact_id'], (int) $v['called_phone_id'], $v['spoke_to'], $v['called_at'] ?? null
            );
        } catch (TitleDeedException $e) {
            return redirect()->route('title-deeds.handover', $deed->id)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.handover', $deed->id)->with('success', 'Phone consent recorded.');
    }

    /* ═════════ Sign by link ═════════ */

    /** "Generate signing link": the same gates as the handover (PIN proven not spent, ID seen, consent, letter, override), no signature. */
    public function generateLink(Request $request, int $id): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $request->validate([
            'method' => ['required', 'in:pin,id_check,representative'],
            'pin' => ['nullable', 'string', 'max:20'],
            'collector_type' => ['nullable', 'in:owner,representative'],
            'collector_contact_id' => ['nullable', 'integer'],
            'rep_name' => ['nullable', 'string', 'max:191'],
            'rep_id_number' => ['nullable', 'string', 'max:40'],
            'rep_relationship' => ['nullable', 'string', 'max:100'],
            'id_number_seen' => ['nullable', 'string', 'max:40'],
            'consent_id' => ['nullable', 'integer'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'send_email' => ['nullable', 'string', 'max:191'],
            'send_mobile' => ['nullable', 'string', 'max:40'],
            'authority_letter' => ['nullable', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
            'collector_id_photo' => ['nullable', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ]);
        if ($redirect = $this->claimForm($request, 'handover', $deed->id, route('title-deeds.handover', $deed->id))) {
            return $redirect;
        }

        try {
            $this->handover->prepareLink($request->user(), $deed, $request->all() + [
                'authority_letter' => $request->file('authority_letter'),
                'collector_id_photo' => $request->file('collector_id_photo'),
            ], ['send_email' => trim((string) $request->input('send_email')), 'send_mobile' => trim((string) $request->input('send_mobile'))]);
        } catch (TitleDeedException $e) {
            return redirect()->route('title-deeds.handover', $deed->id)
                ->withInput($request->except(['pin', 'authority_letter', 'collector_id_photo']))->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.handover', $deed->id)->with('success', 'Signing link ready. Send it to the collector, or let them scan the QR code.');
    }

    public function emailLink(Request $request, int $id, int $link): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $row = TitleDeedSigningLink::query()->where('title_deed_id', $deed->id)->findOrFail($link);
        if ($redirect = $this->claimForm($request, 'link_email', $deed->id, route('title-deeds.handover', $deed->id))) {
            return $redirect;
        }

        try {
            if ($row->collector_type === 'representative') {
                $row = $this->signing->setRepresentativeContact($deed, $row, $request->input('send_email', $row->send_email), $row->send_mobile);
            }
            $r = $this->signing->sendEmail($request->user(), $deed, $row);
        } catch (TitleDeedException $e) {
            return redirect()->route('title-deeds.handover', $deed->id)->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.handover', $deed->id)->with($r['status'] === 'sent' ? 'success' : 'error', $r['status'] === 'sent' ? 'Signing link emailed.' : 'The email did not send: ' . ($r['error'] ?? 'unknown error') . '.');
    }

    public function whatsappLink(Request $request, int $id, int $link): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $row = TitleDeedSigningLink::query()->where('title_deed_id', $deed->id)->findOrFail($link);
        if ($redirect = $this->claimForm($request, 'link_whatsapp', $deed->id, route('title-deeds.handover', $deed->id))) {
            return $redirect;
        }

        try {
            if ($row->collector_type === 'representative') {
                $row = $this->signing->setRepresentativeContact($deed, $row, $row->send_email, $request->input('send_mobile', $row->send_mobile));
            }
            $this->signing->prepareWhatsapp($request->user(), $deed, $row);
        } catch (TitleDeedException $e) {
            return redirect()->route('title-deeds.handover', $deed->id)->with('error', $e->getMessage());
        }

        return redirect()->route('title-deeds.handover', $deed->id)->with('success', 'WhatsApp is ready - press "Open WhatsApp and send".');
    }

    public function cancelLink(Request $request, int $id, int $link): RedirectResponse
    {
        $deed = $this->find($request, $id);
        $row = TitleDeedSigningLink::query()->where('title_deed_id', $deed->id)->findOrFail($link);
        $this->handover->cancelLink($request->user(), $deed, $row);

        return redirect()->route('title-deeds.handover', $deed->id)->with('success', 'The signing link was cancelled.');
    }

    /** GET /api/v1/title-deeds/{id}/signing-status - what the handover screen polls while it waits for the collector. */
    public function signingStatus(Request $request, int $id): JsonResponse
    {
        $deed = $this->find($request, $id);

        return response()->json($this->signing->status($deed) + ['collected' => $deed->isCollected()])->header('Cache-Control', 'no-store, private');
    }

    /** GET /api/v1/title-deeds/{id}/consent-status - what the handover screen polls. */
    public function consentStatus(Request $request, int $id): JsonResponse
    {
        $deed = $this->find($request, $id);

        return response()->json($this->consents->status($deed) + ['collected' => $deed->isCollected()])
            ->header('Cache-Control', 'no-store, private');
    }

    private function find(Request $request, int $id): TitleDeed
    {
        return TitleDeed::query()->visibleTo($request->user())->findOrFail($id);
    }
}
