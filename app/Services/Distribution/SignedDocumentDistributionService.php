<?php

namespace App\Services\Distribution;

use App\Contracts\ReportsUnreachableRecipients;
use App\Contracts\SignedDocumentDistributable;
use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Document;
use App\Models\SignedDocumentDistributionLog;
use App\Models\User;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §41, 2026-09-28, Johan's ruling — the SHARED distribution service for
 * any signed document a module needs to file, email, and share a public
 * link for. Rental inspections are the first consumer (this build);
 * Inventory is the next (cc2). This class knows nothing about rental
 * inspections, leases, or observations — every module-specific decision
 * (who the parties are, the subject line, which property it belongs to)
 * comes from the App\Contracts\SignedDocumentDistributable the caller
 * hands in. Full interface contract + a worked example: see
 * .ai/specs/signed-document-distribution.md — read that before wiring a
 * new consumer, not this file.
 *
 * Reuses two existing, already-proven pieces verbatim rather than
 * inventing a third outbound-mail path: PerMailboxMailTransportBuilder
 * (sends through the agent's OWN configured mailbox when one exists,
 * falling back to the shared CoreX mailer otherwise) and
 * ImapSentFolderAppender (writes a real Sent-Items copy into that same
 * mailbox — best-effort, never fails an already-successful send). Both
 * originally built for e-sign (AT-395); this is their first consumer
 * outside Docuperfect.
 *
 * TEST-MAIL SAFETY RAIL, non-negotiable: PerMailboxMailTransportBuilder
 * connects DIRECTLY to the agent's own configured SMTP server — it does
 * NOT go through Laravel's default `MAIL_MAILER`, so QA's existing
 * "outbound is neutralised" protection (BUILD_STANDARD §8) does NOT
 * apply to this path. Every send outside a real `production` environment
 * is therefore forcibly redirected to the configured mail.non_production_redirect address (suppressed if unset) — this is
 * not a convenience, it is the only thing standing between a QA click
 * and a real tenant/landlord inbox.
 */
class SignedDocumentDistributionService
{
    public function __construct(
        private PerMailboxMailTransportBuilder $mailTransportBuilder = new PerMailboxMailTransportBuilder(),
        private ?ImapSentFolderAppender $sentFolderAppender = null,
    ) {
        $this->sentFolderAppender = $sentFolderAppender ?? app(ImapSentFolderAppender::class);
    }

    /**
     * File the given PDF to the document's own property. Idempotent —
     * keyed on (source_type, source_id): a second call for the same
     * document returns the ALREADY-filed Document unchanged, never files
     * a duplicate. Safe to call from both an automatic completion hook
     * and a backfill command that may run more than once.
     */
    public function fileToProperty(SignedDocumentDistributable $doc, string $pdfBytes, string $filename): ?Document
    {
        $existing = Document::where('source_type', $doc->distributionSourceType())
            ->where('source_id', $doc->distributionSourceId())
            ->first();
        if ($existing) {
            return $existing;
        }

        $property = $doc->distributionProperty();

        try {
            $storedPath = 'signed-documents/' . $doc->distributionSourceType() . '/' . $doc->distributionSourceId() . '/' . Str::random(20) . '.pdf';
            Storage::disk('local')->put($storedPath, $pdfBytes);

            $document = Document::withoutAgencyStamping(fn () => Document::create([
                'original_name' => $filename,
                'storage_path' => $storedPath,
                'disk' => 'local',
                'mime_type' => 'application/pdf',
                'size' => Storage::disk('local')->size($storedPath),
                'source_type' => $doc->distributionSourceType(),
                'source_id' => $doc->distributionSourceId(),
                'agency_id' => $property?->agency_id,
                'branch_id' => $property?->branch_id,
            ]));

            if ($property) {
                $document->properties()->syncWithoutDetaching([$property->id]);
            }

            $this->log(doc: $doc, channel: 'filed', status: 'sent');

            return $document;
        } catch (\Throwable $e) {
            Log::warning('SignedDocumentDistributionService::fileToProperty failed', [
                'distributable_type' => get_class($doc),
                'source_id' => $doc->distributionSourceId(),
                'error' => $e->getMessage(),
            ]);
            $this->log(doc: $doc, channel: 'filed', status: 'failed', error: $e->getMessage());

            return null;
        }
    }

    /**
     * Ensure a currently-valid public share link exists — generates one
     * ONLY when none exists or the existing one has expired. Never
     * regenerates an already-valid link: RentalInspection::
     * generatePublicLink() unconditionally overwrites/invalidates
     * whatever token exists (that IS its revoke mechanism), so calling
     * it when a valid link is already live would silently break a link
     * already forwarded to a tenant. Safe to call repeatedly (backfill,
     * every completion hook run).
     */
    public function ensurePublicLink(SignedDocumentDistributable $doc): ?string
    {
        if (! $doc->hasValidPublicLink()) {
            $doc->generatePublicLink();
            $this->log(doc: $doc, channel: 'public_link', status: 'sent');
        }

        return $doc->publicShareUrl();
    }

    /**
     * Email every party the document's own distributionRecipients()
     * names, from the resolved agent's mailbox (falling back to the
     * shared CoreX mailer), the agent CC'd, a real Sent-Items copy
     * appended when a mailbox exists. Every attempt — success or
     * failure, one row per recipient — is logged.
     *
     * $mode is 'auto' (fired from a completion hook) or 'manual' (an
     * agent's own Resend click) — logged verbatim, never inferred.
     *
     * §45.6 (Build I-4): `$onlyEmails` (lower-cased addresses) narrows the send to those recipients — the
     * per-recipient Resend. A document that also implements ReportsUnreachableRecipients has its unreachable
     * parties recorded as `skipped` rows (with the reason) on a FULL send, never silently dropped. The sending
     * agent is CC'd unless their own address is already one of the recipients (no double copy).
     *
     * `$withoutPdfEmails` (lower-cased addresses): these recipients get the same email with the link but WITHOUT the PDF
     * attached (a consumer's own rule, e.g. rental inspections for a person who refused to sign — spec §51).
     *
     * @param array<int, string>|null $onlyEmails
     * @param array<int, string>|null $withoutPdfEmails
     * @return array<int, array{role:string, email:string, status:string, message_id:?string, error:?string}>
     */
    public function emailParties(
        SignedDocumentDistributable $doc,
        string $pdfPath,
        string $pdfFilename,
        string $mode,
        ?User $sendAs = null,
        ?User $triggeredBy = null,
        ?array $onlyEmails = null,
        ?array $withoutPdfEmails = null,
    ): array {
        $agent = $sendAs ?? $doc->distributionAgent();
        $testOverride = ! app()->environment('production');
        $results = [];
        $testRecipient = (string) config('mail.non_production_redirect');

        $recipients = $doc->distributionRecipients();
        if ($onlyEmails !== null) {
            $recipients = array_values(array_filter($recipients, fn ($r) => in_array(mb_strtolower($r['email']), $onlyEmails, true)));
        } elseif ($doc instanceof ReportsUnreachableRecipients) {
            foreach ($doc->distributionUnreachableRecipients() as $party) {
                $this->log(
                    doc: $doc, channel: 'email', status: 'skipped', mode: $mode,
                    role: $party['role'], contactId: $party['contact_id'] ?? null,
                    email: null, error: $party['reason'], sentByUserId: $triggeredBy?->id,
                );
                $results[] = ['role' => $party['role'], 'email' => '', 'status' => 'skipped', 'message_id' => null, 'error' => $party['reason'], 'contact_id' => $party['contact_id'] ?? null];
            }
        }
        $recipientEmails = array_map(fn ($r) => mb_strtolower($r['email']), $recipients);

        foreach ($recipients as $recipient) {
            if ($testOverride && $testRecipient === '') {
                // No redirect target configured outside production: never
                // reach a real inbox — suppress and log as not sent.
                $result = ['status' => 'failed', 'message_id' => null, 'error' => 'Suppressed: non-production and mail.non_production_redirect is not set'];
                $this->log(
                    doc: $doc, channel: 'email', status: $result['status'], mode: $mode,
                    role: $recipient['role'], contactId: $recipient['contact_id'] ?? null,
                    email: $recipient['email'], messageId: null, error: $result['error'],
                    sentByUserId: $triggeredBy?->id,
                );
                $results[] = array_merge(['role' => $recipient['role'], 'email' => $recipient['email']], $result);
                continue;
            }
            $toEmail = $testOverride ? $testRecipient : $recipient['email'];

            $mail = new SignedDocumentDistributionMail(
                recipientName: $recipient['name'],
                documentLabel: $doc->distributionDocumentLabel(),
                propertyAddress: $doc->distributionProperty()?->buildDisplayAddress() ?? '',
                emailSubject: $doc->distributionSubject(),
                publicUrl: $doc->publicShareUrl(),
                pdfPath: in_array(mb_strtolower($recipient['email']), $withoutPdfEmails ?? [], true) ? null : $pdfPath,
                pdfFilename: $pdfFilename,
            );
            $mail->fromAgent($agent);
            if ($agent?->outward_email && ! in_array(mb_strtolower($agent->outward_email), $recipientEmails, true)) {
                $mail->cc($testOverride ? $testRecipient : $agent->outward_email);
            }

            $result = $this->dispatch($toEmail, $mail);

            $this->log(
                doc: $doc,
                channel: 'email',
                status: $result['status'],
                mode: $mode,
                role: $recipient['role'],
                contactId: $recipient['contact_id'] ?? null,
                email: $recipient['email'],
                messageId: $result['message_id'],
                error: $result['error'],
                sentByUserId: $triggeredBy?->id,
            );

            $results[] = array_merge(['role' => $recipient['role'], 'email' => $recipient['email'], 'contact_id' => $recipient['contact_id'] ?? null], $result);
        }

        return $results;
    }

    /**
     * §24 ruling (2026-09-29) — a generic per-mailbox-with-fallback send for
     * a Mailable that is NOT part of the SignedDocumentDistributable
     * contract (Inventory's buyer-acceptance request: there is no
     * `distributionRecipients()` row for a buyer — they are not a party the
     * inventory itself requires a signature from). SAME mail.non_production_redirect
     * safety rail and SAME dispatch() as every other send this class makes
     * — reused, not duplicated, so that rail can never drift. Deliberately
     * writes no SignedDocumentDistributionLog row — that log is specific to
     * a document's own primary distribution history.
     *
     * @return array{status:string, message_id:?string, error:?string}
     */
    public function sendGenericMail(string $toEmail, BaseSignatureMail $mail, ?User $agent = null): array
    {
        $testOverride = ! app()->environment('production');
        $testRecipient = (string) config('mail.non_production_redirect');

        if ($testOverride && $testRecipient === '') {
            // No redirect target configured outside production: never
            // reach a real inbox — suppress and log as not sent.
            return ['status' => 'failed', 'message_id' => null, 'error' => 'Suppressed: non-production and mail.non_production_redirect is not set'];
        }

        $resolvedTo = $testOverride ? $testRecipient : $toEmail;

        $mail->fromAgent($agent);
        if ($agent?->outward_email) {
            $mail->cc($testOverride ? $testRecipient : $agent->outward_email);
        }

        return $this->dispatch($resolvedTo, $mail);
    }

    /**
     * The actual send — copied from SignatureService::dispatchSigningMail()
     * (AT-395), the proven per-mailbox-with-fallback pattern, adapted to
     * return a result array instead of throwing, since this caller sends
     * to MULTIPLE recipients and one failure must never stop the rest.
     */
    private function dispatch(string $recipientEmail, BaseSignatureMail $mail): array
    {
        $mailbox = $mail->resolvedMailbox();

        if (! $mailbox) {
            try {
                Mail::to($recipientEmail)->send($mail);

                return ['status' => 'sent', 'message_id' => null, 'error' => null];
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'message_id' => null, 'error' => $e->getMessage()];
            }
        }

        // A Mailable's envelope() never sets its own To — always supplied
        // externally (AT-395's own fix; see SignatureService's identical line).
        $mail->to($recipientEmail);

        try {
            $rawMime = $this->mailTransportBuilder->send($mailbox, $mail);
        } catch (OutgoingMailboxSendFailedException $e) {
            $mailbox->forceFill([
                'last_send_error' => $e->sanitisedReason,
                'last_send_error_at' => now(),
                'consecutive_send_failures' => (int) $mailbox->consecutive_send_failures + 1,
            ])->save();

            return ['status' => 'failed', 'message_id' => null, 'error' => $e->sanitisedReason];
        }

        $mailbox->forceFill([
            'last_sent_at' => now(),
            'last_send_error' => null,
            'last_send_error_at' => null,
            'consecutive_send_failures' => 0,
        ])->save();

        preg_match('/^Message-ID:\s*<([^>]+)>/mi', $rawMime, $matches);
        $messageId = $matches[1] ?? null;

        // Sent-folder copy is best-effort — never throws, never fails a
        // send that already succeeded (same discipline as SignatureService).
        $append = $this->sentFolderAppender->append($mailbox, $rawMime);
        $mailbox->forceFill($append['ok']
            ? ['last_sent_folder_append_at' => now(), 'last_sent_folder_append_error' => null]
            : ['last_sent_folder_append_error' => $append['reason']]
        )->save();

        return ['status' => 'sent', 'message_id' => $messageId, 'error' => null];
    }

    private function log(
        SignedDocumentDistributable $doc,
        string $channel,
        string $status,
        ?string $mode = null,
        ?string $role = null,
        ?int $contactId = null,
        ?string $email = null,
        ?string $messageId = null,
        ?string $error = null,
        ?int $sentByUserId = null,
    ): void {
        SignedDocumentDistributionLog::create([
            'agency_id' => $doc->distributionProperty()?->agency_id,
            'distributable_type' => get_class($doc),
            'distributable_id' => $doc->distributionSourceId(),
            'channel' => $channel,
            'mode' => $mode,
            'recipient_role' => $role,
            'recipient_contact_id' => $contactId,
            'recipient_email' => $email,
            'status' => $status,
            'message_id' => $messageId,
            'error' => $error,
            'sent_by_user_id' => $sentByUserId,
        ]);
    }
}
