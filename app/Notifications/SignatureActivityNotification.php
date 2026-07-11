<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app notification for all signature/document activity.
 * Database channel only — no email. Agents see these in the notification bell.
 */
class SignatureActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $type,
        private string $message,
        private ?string $url = null,
        private ?int $documentId = null,
        private array $metadata = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => $this->type,
            'message'     => $this->message,
            'url'         => $this->url,
            'document_id' => $this->documentId,
            'metadata'    => $this->metadata,
        ];
    }

    // Factory methods for each notification type

    public static function partySigned(string $partyName, string $partyRole, string $documentName, int $documentId, string $reviewUrl): self
    {
        $role = ucfirst(str_replace('_', ' ', $partyRole));
        return new self(
            type: 'party_signed',
            message: "{$partyName} ({$role}) has signed: {$documentName}",
            url: $reviewUrl,
            documentId: $documentId,
        );
    }

    public static function wetInkUploaded(string $signerName, string $documentName, int $documentId, string $inspectUrl): self
    {
        return new self(
            type: 'wet_ink_uploaded',
            message: "{$signerName} uploaded a signed copy of {$documentName} — review needed",
            url: $inspectUrl,
            documentId: $documentId,
        );
    }

    public static function documentCompleted(string $documentName, int $documentId, string $viewUrl): self
    {
        return new self(
            type: 'document_completed',
            message: "All parties have signed: {$documentName}",
            url: $viewUrl,
            documentId: $documentId,
        );
    }

    public static function amendmentDetected(string $documentName, int $documentId, string $reviewUrl): self
    {
        return new self(
            type: 'amendment_detected',
            message: "Amendment detected on {$documentName} — review needed",
            url: $reviewUrl,
            documentId: $documentId,
        );
    }

    public static function salesDocumentReturned(string $clientName, string $documentName, int $documentId, string $dashboardUrl): self
    {
        return new self(
            type: 'sales_doc_returned',
            message: "{$clientName} has returned signed: {$documentName}",
            url: $dashboardUrl,
            documentId: $documentId,
        );
    }

    public static function salesDocumentAllReturned(string $documentName, int $documentId, string $dashboardUrl): self
    {
        return new self(
            type: 'sales_doc_all_returned',
            message: "All parties have returned signed: {$documentName}",
            url: $dashboardUrl,
            documentId: $documentId,
        );
    }

    /**
     * P0-5 — the signed pack could not be split into its individual documents,
     * so NOTHING was auto-filed. The agent must know, because compliance will
     * otherwise believe the FICA / Disclosure were never signed.
     */
    public static function packFilingNeedsAttention(string $documentName, int $documentId, string $viewUrl): self
    {
        return new self(
            type: 'pack_filing_needs_attention',
            message: "Signed, but not filed automatically: {$documentName}. "
                . 'The documents in this pack could not be separated, so none were filed. '
                . 'The signed copy is safe — please file the documents manually and report this.',
            url: $viewUrl,
            documentId: $documentId,
        );
    }

    public static function sectionRejected(string $signerName, string $documentName, int $documentId, string $reviewUrl): self
    {
        return new self(
            type: 'section_rejected',
            message: "{$signerName} rejected a section of {$documentName}",
            url: $reviewUrl,
            documentId: $documentId,
        );
    }
}
