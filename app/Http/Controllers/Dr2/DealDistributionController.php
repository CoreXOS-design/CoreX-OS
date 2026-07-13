<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\DealDocumentDistribution;
use App\Services\DealV2\Dr2DistributionComposer;
use App\Services\DealV2\Dr2DistributionSendService;
use Illuminate\Http\Request;

/**
 * AT-228 — party-first document distribution on the DR2 deal. Compose-and-review (the matrix
 * pre-loads who gets what; the agent authorises), then send. Gated deals_v2.distribute_documents.
 */
class DealDistributionController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('deals_v2.distribute_documents'), 403);
    }

    /** GET — compose-and-review for one party (defaults from the matrix). */
    public function compose(Deal $deal, Request $request, Dr2DistributionComposer $composer)
    {
        $this->guard($request);
        $parties = $composer->parties($deal);
        $role    = (string) $request->query('party', $parties[0]['role'] ?? 'seller');
        $party   = collect($parties)->firstWhere('role', $role) ?? ($parties[0] ?? null);
        abort_if($party === null, 404);

        return view('dr2.distribute-compose', [
            'deal'        => $deal,
            'party'       => $party,
            'parties'     => $parties,
            'corpus'      => $composer->documentCorpus($deal),
            'sizeLimitMb' => (int) ($composer->sizeLimitBytes((int) $deal->agency_id) / 1024 / 1024),
            'modes'       => [
                DealDocumentDistribution::MODE_SECURE_LINK      => 'Secure link (OTP)',
                DealDocumentDistribution::MODE_DIRECT_ATTACHMENT => 'Attachment',
            ],
            'channels'    => [
                DealDocumentDistribution::CHANNEL_EMAIL    => 'Email',
                DealDocumentDistribution::CHANNEL_WHATSAPP => 'WhatsApp',
            ],
        ]);
    }

    /** POST — confirm + send. Recipient is re-resolved server-side (never trust the client address). */
    public function send(Deal $deal, Request $request, Dr2DistributionComposer $composer, Dr2DistributionSendService $sender)
    {
        $this->guard($request);

        $data = $request->validate([
            'party_role'    => ['required', 'string', 'max:40'],
            'recipient_id'  => ['nullable', 'integer'],
            'document_ids'  => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer'],
            'delivery_mode' => ['required', 'in:secure_link,direct_attachment'],
            'channel'       => ['required', 'in:email,whatsapp'],
            'message'       => ['nullable', 'string', 'max:4000'],
        ]);

        // Server-authoritative recipient: re-resolve from the deal + role, match the chosen id.
        $recipients = $composer->recipientsFor($deal, $data['party_role']);
        $recipient  = collect($recipients)->first(function ($r) use ($data) {
            return ! $data['recipient_id'] || (int) ($r['id'] ?? 0) === (int) $data['recipient_id'];
        }) ?? ($recipients[0] ?? null);

        if (! $recipient) {
            return back()->with('error', 'No recipient is linked for this party on the deal.');
        }

        try {
            $result = $sender->sendToParty(
                $deal, $data['party_role'], $recipient, $data['document_ids'],
                $data['delivery_mode'], $data['channel'], $data['message'] ?? null, $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $parts = $result['parts'] > 1 ? " in {$result['parts']} parts" : '';
        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('success', "Sent {$result['rows']} document(s) to " . ($recipient['name'] ?? 'the recipient') . " via {$result['channel']}{$parts}.");
    }
}
