<?php

namespace App\Services\Rentals;

use App\Mail\Signatures\SignedDocumentMail;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-portal-access.md §18 — Johan, 2026-10-07: when a PAPER-signed (wet ink) lease is attached, its
 * tenant(s) and landlord(s) get the lease copy by email and their portal link, exactly as they do when a lease is signed
 * in e-sign. Same rules as SignatureService::sendCompletionEmails():
 *   - who: the lease's tenants and landlords (never the agent), ONE mail per distinct email address (a person who is
 *     both gets one), a party without an email is skipped and named in the tenancy log;
 *   - what: the same SignedDocumentMail with the signed copy attached and, per person, the "Your CoreX portal" block
 *     (only when automatic portal access is on and that audience's portal is on — RentalPortalAccessService decides);
 *   - how: through RentalMailDispatcher as the agent who attached it (their own mailbox path, audited fallback to the
 *     shared mailer, non-production redirect via OutboundMailGuard) — never a plain Mail::to();
 *   - once: an atomic claim on leases.signed_copy_emailed_at, taken by the run that sends. A second submit, a retry or a
 *     re-upload finds the claim and sends nothing. If NOTHING could be delivered the claim is released, so the lease is
 *     not left looking "sent" when nobody received it.
 * Best effort: a fault here never undoes the capture, and every outcome is written to the tenancy log.
 */
class LeaseSignedCopyMailer
{
    public function __construct(
        private readonly RentalMailDispatcher $dispatcher,
        private readonly RentalPortalAccessService $portal,
    ) {
    }

    /**
     * @return array{status:string, sent:array<int,string>, failed:array<int,string>, no_email:array<int,string>}
     *         status: sent | partial | none_delivered | already_sent | no_file | no_recipients
     */
    public function sendOnPaperAttach(Lease $lease, User $agent): array
    {
        $out = ['status' => 'sent', 'sent' => [], 'failed' => [], 'no_email' => []];

        $lease->loadMissing(['tenants.contact', 'property']);
        $document = $lease->signedDocument();
        $disk = Storage::disk($document?->disk ?: 'local');
        if (! $document || ! $document->storage_path || ! $disk->exists($document->storage_path)) {
            return ['status' => 'no_file'] + $out;
        }

        [$recipients, $out['no_email']] = $this->recipients($lease);
        if ($recipients === []) {
            $this->log($lease, $agent, 'Signed copy not emailed — no tenant or landlord has an email address saved', $out);

            return ['status' => 'no_recipients'] + $out;
        }

        // The once-only claim: only the run that flips NULL -> now() sends.
        $claimed = Lease::withoutGlobalScopes()->whereKey($lease->id)->whereNull('signed_copy_emailed_at')->update(['signed_copy_emailed_at' => now()]);
        if ($claimed === 0) {
            return ['status' => 'already_sent'] + $out;
        }

        $path = $disk->path($document->storage_path);
        $name = $document->original_name ?: 'Signed lease agreement';
        $address = $lease->property?->buildDisplayAddress();
        $documentName = 'Lease agreement' . ($address ? " — {$address}" : '');

        foreach ($recipients as $email => $contact) {
            try {
                $mail = (new SignedDocumentMail(
                    recipientName: trim((string) $contact->first_name) ?: (trim((string) $contact->full_name) ?: 'there'),
                    documentName: $documentName,
                    envelopeUrl: null,
                    progress: [],
                    pdfPath: $path,
                    pdfFilename: $name,
                    documents: [['path' => $path, 'name' => $name, 'mime' => $document->mime_type ?: 'application/pdf']],
                    portal: $this->portal->mailBlockForLease($lease, $email, (int) $contact->id),
                ))->fromAgent($agent);

                $this->dispatcher->send($email, $mail);
                $out['sent'][] = $email;
            } catch (\Throwable $e) {
                Log::warning('Signed paper lease copy email failed', ['lease_id' => $lease->id, 'contact_id' => $contact->id, 'error' => $e->getMessage()]);
                $out['failed'][] = $email;
            }
        }

        if ($out['sent'] === []) {
            // Nobody received it — do not leave the lease claiming it was sent.
            Lease::withoutGlobalScopes()->whereKey($lease->id)->update(['signed_copy_emailed_at' => null]);
            $out['status'] = 'none_delivered';
            $this->log($lease, $agent, 'Signed copy could not be emailed to ' . implode(', ', $out['failed']), $out);

            return $out;
        }

        $out['status'] = $out['failed'] === [] ? 'sent' : 'partial';
        $this->log($lease, $agent, 'Signed copy emailed to ' . implode(', ', $out['sent'])
            . ($out['failed'] ? ' (could not reach ' . implode(', ', $out['failed']) . ')' : '')
            . ($out['no_email'] ? ' (no email saved for ' . implode(', ', $out['no_email']) . ')' : ''), $out);

        return $out;
    }

    /**
     * @return array{0: array<string,Contact>, 1: array<int,string>}  email => contact (one per distinct address), and the names with no email
     */
    private function recipients(Lease $lease): array
    {
        $byEmail = [];
        $noEmail = [];
        foreach ($lease->tenantContacts()->concat($lease->landlordContacts()) as $contact) {
            $email = strtolower(trim((string) $contact->email));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->portal->isPlaceholderEmail($email)) {
                $noEmail[$contact->id] = trim((string) $contact->full_name) ?: ('Contact #' . $contact->id);
                continue;
            }
            $byEmail[$email] ??= $contact;
        }

        return [$byEmail, array_values($noEmail)];
    }

    private function log(Lease $lease, User $agent, string $description, array $out): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => LeaseEvent::TYPE_LEASE_SIGNED_COPY_EMAILED,
            'description' => $description,
            'actor_user_id' => $agent->id,
            'metadata' => ['sent' => $out['sent'], 'failed' => $out['failed'], 'no_email' => $out['no_email']],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
