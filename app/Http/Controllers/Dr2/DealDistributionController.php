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

    /**
     * AT-334 quick win — inline email capture for a party that is linked but has
     * NO email on file. Saves STRAIGHT to the underlying record so the row flips to
     * "Send to <party>" without navigating away. The target record is re-resolved
     * server-side from (deal, role) — never a client-supplied id — mirroring send().
     * A seller/buyer recipient is a Contact (AT-125 canonical email + mirror); an
     * attorney/bond recipient is a provider firm/contact (simple email column).
     */
    public function savePartyEmail(Deal $deal, string $role, Request $request, Dr2DistributionComposer $composer)
    {
        $this->guard($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = trim($data['email']);

        $recipients = $composer->recipientsFor($deal, $role);
        abort_if(empty($recipients), 422, 'No linked recipient for this party — link one on the deal first.');

        $missing = array_values(array_filter($recipients, fn ($r) => empty($r['email'])));
        if (count($missing) === 0) {
            return response()->json(['ok' => false, 'message' => 'This party already has an email on file.'], 409);
        }
        if (count($missing) > 1) {
            return response()->json(['ok' => false, 'message' => 'This party has several recipients missing an email — open the contact to add it there.'], 409);
        }
        $target = $missing[0];

        if (($target['type'] ?? null) === 'contact') {
            $contact = \App\Models\Contact::withoutGlobalScopes()->find($target['id']);
            abort_if(! $contact, 404, 'Contact not found.');
            $ce = \App\Models\ContactEmail::create([
                'agency_id'  => $contact->agency_id,
                'contact_id' => $contact->id,
                'email'      => $email,
                'is_primary' => true,
            ]);
            app(\App\Services\Contacts\ContactIdentifierService::class)->setPrimaryEmail($ce);
        } else { // provider — set on the provider CONTACT if present, else the firm
            if (! empty($target['contact_id'])) {
                $pc = \App\Models\DealV2\AgencyServiceProviderContact::withoutGlobalScopes()->find($target['contact_id']);
                abort_if(! $pc, 404, 'Provider contact not found.');
                $pc->email = $email;
                $pc->save();
            } else {
                $firm = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->find($target['id']);
                abort_if(! $firm, 404, 'Provider not found.');
                $firm->email = $email;
                $firm->save();
            }
        }

        return response()->json([
            'ok'          => true,
            'email'       => $email,
            'compose_url' => route('deals-dr2.distribute.compose', ['deal' => $deal, 'party' => $role]),
        ]);
    }
}
