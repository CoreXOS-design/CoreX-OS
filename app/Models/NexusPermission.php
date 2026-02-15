<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NexusPermission extends Model
{
    protected $fillable = ['key', 'label', 'section', 'sort_order'];
}
