<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** A file (e.g. the debit order form PDF) that rides with a contract. Spec §6.2. */
class PlatformContractAttachment extends Model
{
    protected $table = 'platform_contract_attachments';

    protected $fillable = ['envelope_id', 'original_name', 'stored_path', 'sha256'];
}
