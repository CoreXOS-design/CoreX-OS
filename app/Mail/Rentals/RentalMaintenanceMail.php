<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalWorkOrder;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §17.16 — the base of every maintenance-flow mail to an owner or a contractor.
 * Sent AS the property's responsible agent (or the agent who pressed the button) through the agency mailbox path
 * (RentalMailDispatcher::send()), never a plain `Mail::to()->send(Mailable)`. The agency's name, logo-less header and
 * footer come from the agency record — nothing here names any one agency.
 *
 * Subclasses supply the subject, the Blade view under emails/rentals/maintenance/ and, optionally, one PDF attachment.
 */
abstract class RentalMaintenanceMail extends BaseSignatureMail
{
    protected ?string $pdfContents = null;
    protected ?string $pdfFilename = null;

    /** @var array<int, array{0: string, 1: string}> further attachments (contents, filename) — e.g. the contractor's revised quote document */
    protected array $moreAttachments = [];

    public function withAttachment(string $contents, string $filename): static
    {
        $this->moreAttachments[] = [$contents, $filename];

        return $this;
    }

    abstract protected function subjectText(): string;

    abstract protected function viewName(): string;

    /** @return array<string, mixed> */
    abstract protected function viewData(): array;

    abstract protected function workOrderForMail(): RentalWorkOrder;

    protected function agencyName(): string
    {
        $agency = $this->workOrderForMail()->agency ?? $this->workOrderForMail()->property?->agency;

        return $agency?->trading_name ?: ($agency?->name ?: config('mail.from.name', 'Your agency'));
    }

    protected function address(): string
    {
        $wo = $this->workOrderForMail();

        return $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: '[' . $this->agencyName() . '] ' . $this->subjectText(),
        );
    }

    public function content(): Content
    {
        return new Content(view: $this->viewName(), with: array_merge([
            'agencyName' => $this->agencyName(),
            'address' => $this->address(),
            'footer' => $this->getAgentFooter(),
        ], $this->viewData()));
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $attachments = [];
        if ($this->pdfContents !== null) {
            $contents = $this->pdfContents;
            $attachments[] = Attachment::fromData(fn () => $contents, $this->pdfFilename ?: 'document.pdf')->withMime($this->pdfMime());
        }
        foreach ($this->moreAttachments as [$more, $name]) {
            $attachments[] = Attachment::fromData(fn () => $more, $name)->withMime($this->mimeFor($name));
        }

        return $attachments;
    }

    protected function pdfMime(): string
    {
        return $this->mimeFor((string) $this->pdfFilename);
    }

    protected function mimeFor(string $filename): string
    {
        $name = strtolower($filename);

        return match (true) {
            str_ends_with($name, '.png') => 'image/png',
            str_ends_with($name, '.jpg'), str_ends_with($name, '.jpeg') => 'image/jpeg',
            str_ends_with($name, '.webp') => 'image/webp',
            default => 'application/pdf',
        };
    }

    /** The attachment, for tests and for the audit note ("sent with X attached"). */
    public function attachmentName(): ?string
    {
        return $this->pdfContents !== null ? $this->pdfFilename : null;
    }
}
