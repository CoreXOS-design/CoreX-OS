<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CoreX's own contract template (Subscription Agreement, debit order form…).
 * Platform-owned — outside every agency. Spec §6.2.
 */
class PlatformContractTemplate extends Model
{
    use SoftDeletes;

    public const KINDS = [
        'subscription_agreement' => 'Subscription agreement',
        'debit_order_form'       => 'Debit order form',
        'other'                  => 'Other',
    ];

    protected $table = 'platform_contract_templates';

    protected $fillable = ['name', 'kind', 'body', 'version', 'is_active', 'created_by'];

    protected $casts = ['version' => 'integer', 'is_active' => 'boolean'];
}
