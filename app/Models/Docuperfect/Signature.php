<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    protected $table = 'signatures';

    protected $fillable = [
        'signature_template_id',
        'signature_marker_id',
        'signature_request_id',
        'signer_user_id',
        'signer_name',
        'signer_email',
        'signer_ip_address',
        'signer_user_agent',
        'signature_data',
        'signature_type',
        'signed_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    // Type constants
    const TYPE_DRAWN = 'drawn';
    const TYPE_TYPED = 'typed';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function marker()
    {
        return $this->belongsTo(SignatureMarker::class, 'signature_marker_id');
    }

    public function request()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function signerUser()
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }
}
