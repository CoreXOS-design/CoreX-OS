<?php

namespace App\Mail\PlatformEsign;

use App\Models\PlatformEsign\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The signed, sealed PDF — to every signer and to the CoreX user who sent it. */
class SignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $doc)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Signed: ' . $this->doc->title);
    }

    public function content(): Content
    {
        return new Content(view: 'platform-esign.email.signed');
    }

    public function attachments(): array
    {
        $path = $this->doc->sealed_pdf_path;
        if (!$path || !Storage::disk('local')->exists($path)) {
            return [];
        }

        return [Attachment::fromStorageDisk('local', $path)->as(Str::slug($this->doc->title) . '-signed.pdf')->withMime('application/pdf')];
    }
}
