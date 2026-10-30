<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A prospect not yet signed (system-owner Revenue pipeline). */
class RevenueProspect extends Model
{
    use SoftDeletes;

    public const STAGES = ['talking' => 'Talking', 'agreement_sent' => 'Agreement sent', 'agreement_back' => 'Agreement back', 'signed' => 'Signed'];

    protected $fillable = ['name', 'contact', 'stage', 'users_min', 'users_max', 'monthly_override', 'chance_percent', 'expected_start_month', 'notes', 'agency_id', 'billing_record_id'];

    protected $casts = ['monthly_override' => 'float', 'expected_start_month' => 'date', 'chance_percent' => 'integer'];
}
