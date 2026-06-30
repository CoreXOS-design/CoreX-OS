<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\AgentCaptureConsent;
use App\Models\Contact;
use App\Services\Communications\AgentCaptureConsentService;
use Illuminate\Http\Request;

/**
 * AT-136 — per-agent WhatsApp capture consent surfaces.
 *
 * Agent: "My WhatsApp Capture" list + the per-contact toggle decide which matched
 * contacts' chat BODIES are archived. Admin/CO: a capability-gated review of
 * opt-outs (declaration + reason, NEVER message content) with a flag-for-opt-in
 * (the business call — sees, does not override). SEPARATE from the AT-125 contact
 * marketing opt-out.
 */
class AgentCaptureConsentController extends Controller
{
    public function __construct(protected AgentCaptureConsentService $consent) {}

    /** Agent's central "My WhatsApp Capture" screen — their matched contacts. */
    public function myCapture(Request $request)
    {
        $rows = AgentCaptureConsent::forAgent($request->user()->id)
            ->with('contact:id,first_name,last_name,phone')
            ->orderByRaw("FIELD(status,'pending','opted_out','opted_in')") // pending first
            ->orderByDesc('updated_at')
            ->get();

        return view('communications.capture-consent.my-capture', [
            'rows'         => $rows,
            'pendingCount' => $rows->where('status', AgentCaptureConsent::STATUS_PENDING)->count(),
        ]);
    }

    /**
     * Agent sets their decision for a contact (from the central list OR the contact
     * record toggle). The agent always decides for THEMSELVES.
     */
    public function decide(Request $request)
    {
        $data = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'status'     => 'required|in:opted_in,opted_out',
            'reason'     => 'nullable|string|max:1000',
        ]);

        // AgencyScope ensures the contact is in the agent's agency (404 otherwise).
        $contact = Contact::findOrFail($data['contact_id']);

        $row = $this->consent->setDecision(
            $request->user()->id,
            $contact,
            $data['status'],
            $data['reason'] ?? null,
            $request->user()
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $row->status]);
        }

        return back()->with('success', 'WhatsApp capture preference saved for this contact.');
    }

    /**
     * Admin/CO review of capture opt-outs (FICA backstop). Declaration + reason
     * only — NEVER message content. Capability-gated.
     */
    public function review(Request $request)
    {
        abort_unless($request->user()->hasPermission('communications.capture_review'), 403);

        $rows = AgentCaptureConsent::query()
            ->where('status', AgentCaptureConsent::STATUS_OPTED_OUT)
            ->with(['agent:id,name', 'contact:id,first_name,last_name'])
            ->orderByDesc('decided_at')
            ->get();

        return view('communications.capture-consent.review', ['rows' => $rows]);
    }

    /**
     * Admin/CO flags an opted-out contact they judge to be BUSINESS, requesting the
     * agent opt-in. Sees, does NOT override. Capability-gated.
     */
    public function flag(AgentCaptureConsent $consent, Request $request)
    {
        abort_unless($request->user()->hasPermission('communications.capture_review'), 403);

        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        $this->consent->flagForOptIn($consent, $request->user(), $data['note'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Flagged for the agent to review — this is a request, not an override.');
    }
}
