<?php

namespace App\Models\Campaigns;

use App\Models\Communications\CommunicationMailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * AT-534 — a CoreX sending address for Campaigns (.ai/specs/campaigns.md §3.0). Platform data: no agency_id, no BelongsToAgency.
 * The password uses the same 'encrypted' cast + $hidden discipline as CommunicationMailbox.
 */
class CampaignSender extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'email', 'from_name', 'signature', 'signature_image_path', 'smtp_host', 'smtp_port', 'smtp_encryption', 'username', 'encrypted_password',
        'imap_host', 'imap_port', 'imap_encryption', 'is_default', 'last_send_error', 'last_send_error_at', 'last_sent_at',
        'last_inbox_check_at', 'last_inbox_error', 'created_by', 'updated_by',
    ];

    protected $hidden = ['encrypted_password'];

    protected $casts = [
        'encrypted_password' => 'encrypted',
        'smtp_port' => 'integer',
        'imap_port' => 'integer',
        'is_default' => 'boolean',
        'last_send_error_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'last_inbox_check_at' => 'datetime',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** Private folder (local disk) holding the uploaded signature pictures (spec 9.3). */
    public const SIGNATURE_IMAGE_DIR = 'campaign-signatures';

    /** Full path of the signature picture, or null when none is set or the file is gone. */
    public function signatureImageFullPath(): ?string
    {
        $path = (string) $this->signature_image_path;
        if ($path === '' || ! str_starts_with($path, self::SIGNATURE_IMAGE_DIR . '/')) {
            return null;
        }
        $disk = Storage::disk('local');

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    public function imapHost(): string
    {
        return (string) ($this->imap_host ?: $this->smtp_host);
    }

    /**
     * An UNSAVED CommunicationMailbox carrying this sender's SMTP settings, so the send goes through PerMailboxMailTransportBuilder —
     * the one place the OutboundMailGuard raw-socket gate lives (§42 hard rule) — instead of a second copy of that gate. Never saved.
     */
    public function toTransientMailbox(): CommunicationMailbox
    {
        $mailbox = new CommunicationMailbox();
        $mailbox->forceFill([
            'email_address' => $this->email,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
            'use_imap_credentials_for_smtp' => false,
            'smtp_username' => $this->username,
            'smtp_encrypted_password' => $this->encrypted_password,
            'smtp_from_name' => $this->from_name,
        ]);

        return $mailbox;
    }

    public function sendHealthLabel(): string
    {
        if ($this->last_send_error) {
            return 'failing';
        }

        return $this->last_sent_at ? 'healthy' : 'pending';
    }

    public function inboxHealthLabel(): string
    {
        if ($this->last_inbox_error) {
            return 'failing';
        }

        return $this->last_inbox_check_at ? 'healthy' : 'pending';
    }
}
