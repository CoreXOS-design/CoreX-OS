<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Http\Controllers\Controller;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedEvent;
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
    public function __construct(
        private TitleDeedHandoverService $handover,
        private TitleDeedConsentService $consents,
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

        // WhatsApp messages still waiting for a person to send them (consent requests for the current request).
        $prepared = TitleDeedEvent::query()
            ->where('title_deed_id', $deed->id)
            ->where('kind', TitleDeedEvent::KIND_CONSENT_REQUEST)
            ->where('channel', TitleDeedEvent::CHANNEL_WHATSAPP)
            ->where('status', TitleDeedEvent::STATUS_PREPARED)
            ->when($consent['consent_id'], fn ($q) => $q->where('consent_id', $consent['consent_id']))
            ->orderBy('id')->get();

        return view('title-deeds.handover', [
            'deed' => $deed,
            'ctx' => $context,
            'prepared' => $prepared,
            'pollUrl' => route('api.v1.title-deeds.consent-status', $deed->id),
        ]);
    }

    public function submit(Request $request, int $id): RedirectResponse
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
            'typed_name' => ['nullable', 'string', 'max:191'],
            'signature_image' => ['nullable', 'string', 'max:1450000'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'authority_letter' => ['nullable', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
            'collector_id_photo' => ['nullable', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,heic,pdf'],
        ], [
            'authority_letter.mimes' => 'The authority letter must be a PDF or a photo.',
            'collector_id_photo.mimes' => "The ID photo must be a photo or a PDF.",
        ]);

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
