<?php

namespace App\Models\PlatformEsign;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A CoreX contract sent for signature. `agency_id` = the agency it is ABOUT, never a tenant scope. */
class Document extends Model
{
    use SoftDeletes;

    protected $table = 'platform_esign_documents';

    public const STATUSES = [
        'draft' => 'Draft', 'sent' => 'Sent', 'in_progress' => 'Partly signed', 'completed' => 'Signed',
        'declined' => 'Declined', 'voided' => 'Voided', 'expired' => 'Expired',
    ];
    public const OPEN = ['sent', 'in_progress'];

    protected $fillable = [
        'template_id', 'template_version', 'agency_id', 'title', 'status', 'source', 'body_html_snapshot', 'pdf_path',
        'page_count', 'fields_json', 'sequential', 'expires_at', 'sent_at', 'completed_at', 'declined_at', 'voided_at',
        'voided_by', 'void_reason', 'decline_reason', 'sealed_pdf_path', 'document_hash', 'created_by',
    ];

    protected $casts = [
        'fields_json' => 'array', 'sequential' => 'boolean', 'expires_at' => 'datetime', 'sent_at' => 'datetime',
        'completed_at' => 'datetime', 'declined_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function template() { return $this->belongsTo(Template::class, 'template_id')->withTrashed(); }
    public function agency() { return $this->belongsTo(Agency::class, 'agency_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function signers(): HasMany { return $this->hasMany(Signer::class, 'document_id')->orderBy('sign_order')->orderBy('id'); }
    public function events(): HasMany { return $this->hasMany(Event::class, 'document_id')->orderBy('id'); }
    public function attachments(): HasMany { return $this->hasMany(Attachment::class, 'document_id'); }
    public function values(): HasMany { return $this->hasMany(FieldValue::class, 'document_id'); }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isPdf(): bool
    {
        return $this->source === 'pdf';
    }
}
