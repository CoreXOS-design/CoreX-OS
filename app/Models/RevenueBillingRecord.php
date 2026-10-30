<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A signed agency's commercial terms with CoreX (system-owner Revenue). Platform-level: no agency scope, owner-only screens. */
class RevenueBillingRecord extends Model
{
    use SoftDeletes;

    protected $fillable = ['agency_id', 'name', 'users', 'branches', 'override_monthly', 'override_note', 'start_date', 'term_months', 'notes'];

    protected $casts = ['override_monthly' => 'float', 'start_date' => 'date'];
}
