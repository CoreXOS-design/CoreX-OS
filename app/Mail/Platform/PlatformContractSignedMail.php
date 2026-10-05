<?php

namespace App\Mail\Platform;

use App\Models\Platform\PlatformContractEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** The signed, sealed PDF copy — to the signer and to the CoreX user who sent it. */
class PlatformContractSignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $agencyName;

    public function __construct(public PlatformContractEnvelope $envelope)
    {
        $this->agencyName = $envelope->agency?->name ?? 'the agency';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Signed: ' . $this->envelope->title . ' — ' . $this->agencyName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.platform.contract-signed');
    }

    public function attachments(): array
    {
        $path = $this->envelope->sealed_pdf_path;
        if (!$path || !Storage::disk('local')->exists($path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $path)
                ->as(\Illuminate\Support\Str::slug($this->envelope->title . ' ' . $this->agencyName) . '-signed.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
