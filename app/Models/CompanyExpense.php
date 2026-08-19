<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyExpense extends Model
{
    use SoftDeletes;
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'period',
        'monthly_expenses',
    ];
}
