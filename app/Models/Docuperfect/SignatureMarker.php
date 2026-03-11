<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignatureMarker extends Model
{
    use SoftDeletes;

    protected $table = 'signature_markers';

    protected $fillable = [
        'signature_template_id',
        'page_number',
        'x_position',
        'y_position',
        'width',
        'height',
        'type',
        'assigned_party',
        'assigned_email',
        'label',
        'sort_order',
        'required',
        'from_template_zone_id',
    ];

    protected $casts = [
        'x_position' => 'decimal:4',
        'y_position' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'required' => 'boolean',
    ];

    // Type constants
    const TYPE_SIGNATURE = 'signature';
    const TYPE_INITIAL = 'initial';
    const TYPE_DATE = 'date';
    const TYPE_TEXT = 'text';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    // --- Scopes ---

    public function scopeForPage($query, int $page)
    {
        return $query->where('page_number', $page);
    }

    public function scopeForParty($query, string $party)
    {
        return $query->where('assigned_party', $party);
    }

    public function scopeSignatures($query)
    {
        return $query->where('type', self::TYPE_SIGNATURE);
    }

    public function scopeInitials($query)
    {
        return $query->where('type', self::TYPE_INITIAL);
    }

    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    // --- Helpers ---

    public function isSigned(): bool
    {
        return $this->signatures()->exists();
    }

    public function isFromTemplate(): bool
    {
        return $this->from_template_zone_id !== null;
    }
}
