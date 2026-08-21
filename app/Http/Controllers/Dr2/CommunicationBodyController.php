<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Contact;
use App\Services\Communications\CommunicationStorageService;
use App\Services\Communications\Dr2DealPartyEmailResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CX-112 (Johan, 2026-08-21) — "an agent must be able to read an email before filing/
 * confirming it." Shared by both DR2 screens that show an email row (Unfiled Emails, and
 * the filed-emails section on a deal) so agents learn ONE viewer, not two.
 *
 * Reuses compliance.communication-archive._thread-bubble.blade.php as-is for content
 * rendering (escaped plain-text body, quote-strip toggle, attachments, transcript — all
 * already built and already safe, see CX-112 investigation) — this controller only supplies
 * the DR2-appropriate authorization for that reused partial's attachment links, gated on
 * view_deals rather than the stricter access_communication_archive permission the partial's
 * original caller (Comms Suspense/Archive) uses.
 *
 * On-demand only: body/attachment content is never eager-loaded for a list — this controller
 * exists specifically because both list screens fetch it per-row, only when a row is expanded.
 */
class CommunicationBodyController extends Controller
{
    public function __construct(private Dr2DealPartyEmailResolver $dealParties)
    {
    }

    /** GET /deals-dr2/communications/{communication}/body — the rendered bubble for ONE email. */
    public function show(Request $request, Communication $communication)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        $communication->loadMissing('attachments');

        $recipients = view('dr2._email-recipients', [
            'communication' => $communication,
        ] + $this->recipientData($communication, $agencyId))->render();

        $bubble = view('compliance.communication-archive._thread-bubble', [
            'm' => $communication,
            'attachmentRoute' => 'deals-dr2.comms-body.attachment',
            'attachmentRetryRoute' => 'deals-dr2.comms-body.attachment', // DR2 is email-only (no
            // WhatsApp voice notes — CX-109 excludes WhatsApp from the deal register entirely),
            // so the retry affordance is unreachable in practice; pointed at a route that
            // exists rather than left dangling.
            'transcribeRoute' => 'deals-dr2.comms-body.attachment',
        ])->render();

        return $recipients . $bubble;
    }

    /**
     * CX-113 Phase G (Johan, 2026-08-22) — "cant see all the email addresses it was
     * sent from or sent to... say so inline." Builds From/To/Cc (or, for a legacy row
     * captured before Phase G, an honest "Recipients" fallback — see
     * Dr2DealPartyEmailResolver's dealMatchesForEmails() doc block) plus, per address,
     * whatever the system already knows: a named deal-party role (near-conclusive when
     * unique) using the SAME "Role on N deals" specificity wording as the ranked
     * deal-search badges (never a separate taxonomy), or — failing that — a known
     * contact's name.
     *
     * @return array{to: array<int,string>, cc: array<int,string>, legacyRecipients: ?array<int,string>, annotations: array<string,string>}
     */
    private function recipientData(Communication $communication, int $agencyId): array
    {
        $to = $communication->to_identifiers;
        $cc = $communication->cc_identifiers;

        // Phase G shipped after this row was captured — to_identifiers/cc_identifiers
        // were never persisted for it (see the ingestion fix's commit message: the
        // role split existed in memory at parse time and was discarded before Phase
        // G). The best we can honestly show is the full merged set, unlabeled.
        $legacyRecipients = null;
        if ($to === null && $cc === null) {
            $from = strtolower(trim((string) $communication->from_identifier));
            $legacyRecipients = collect($communication->participant_identifiers ?? [])
                ->map(fn ($e) => strtolower(trim((string) $e)))
                ->filter(fn ($e) => $e !== '' && $e !== $from)
                ->unique()->values()->all();
        }

        $allAddresses = collect([$communication->from_identifier])
            ->merge($to ?? [])->merge($cc ?? [])->merge($legacyRecipients ?? [])
            ->filter()->unique()->values()->all();

        $annotations = $this->annotationsFor($allAddresses, $agencyId);

        return [
            'to' => $to ?? [],
            'cc' => $cc ?? [],
            'legacyRecipients' => $legacyRecipients,
            'annotations' => $annotations,
        ];
    }

    /** @return array<string, string> normalised email => one-line annotation */
    private function annotationsFor(array $addresses, int $agencyId): array
    {
        $dealMatches = $this->dealParties->dealMatchesForEmails($agencyId, $addresses);
        $partyFrequency = $this->dealParties->partyDealFrequency($agencyId);

        $roleDisplay = [
            'buyer' => 'buyer', 'seller' => 'seller', 'attorney' => 'attorney',
            'bond_originator' => 'bond originator', 'bond_attorney' => 'bond attorney',
            'coc_supplier' => 'COC supplier', 'other_party' => 'party',
        ];

        $out = [];
        foreach ($addresses as $addr) {
            $email = strtolower(trim((string) $addr));
            $matches = $dealMatches[$email] ?? [];
            if ($matches !== []) {
                $freq = max(1, $partyFrequency[$email] ?? count($matches));
                $roleLabel = $roleDisplay[$matches[0]['role']] ?? 'party';
                if ($freq <= 1) {
                    $deal = $matches[0];
                    $label = trim(($deal['deal_no'] ? "#{$deal['deal_no']}" : '') . ($deal['property_address'] ? " · {$deal['property_address']}" : ''));
                    $out[$email] = "{$roleLabel} on deal {$label}";
                } else {
                    $out[$email] = ucfirst($roleLabel) . " on {$freq} deals";
                }
                continue;
            }

            $contact = Contact::query()->where('agency_id', $agencyId)
                ->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'first_name', 'last_name']);
            if ($contact) {
                $out[$email] = trim($contact->full_name) !== '' ? "{$contact->full_name} — contact" : 'known contact';
            }
        }

        return $out;
    }

    /** GET /deals-dr2/communications/attachments/{attachment} — same streaming as the
     * compliance archive's attachment(), DR2-scoped authorization instead. */
    public function attachment(Request $request, CommunicationAttachment $attachment, CommunicationStorageService $storage)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $communication = Communication::query()->withoutGlobalScopes()->find($attachment->communication_id);
        abort_unless($communication && (int) $communication->agency_id === (int) $agencyId, 404);

        abort_unless($attachment->isPlayable(), 404);

        $disk = Storage::disk($storage->disk());
        abort_unless($disk->exists($attachment->storage_path), 404);

        $mime = $attachment->mime ?: 'application/octet-stream';
        $downloadName = $attachment->filename ?: ('attachment-' . $attachment->id);

        return response()->file($disk->path($attachment->storage_path), [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . $downloadName . '"',
            'Cache-Control'          => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
