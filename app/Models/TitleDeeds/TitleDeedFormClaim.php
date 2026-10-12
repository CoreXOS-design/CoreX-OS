<?php

namespace App\Models\TitleDeeds;

use Illuminate\Database\Eloquent\Model;

/**
 * One SUBMITTED one-time form token (see TitleDeedFormGuard). The unique key on `token_hash` is the whole point. Written by the
 * system as a side effect of a submit; rows are never edited or removed by anyone, so there is no soft delete to speak of.
 */
class TitleDeedFormClaim extends Model
{
    protected $table = 'title_deed_form_claims';

    public $timestamps = false;

    protected $fillable = ['agency_id', 'token_hash', 'purpose', 'user_id', 'title_deed_id', 'result_deed_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
