<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoreXPermission extends Model
{
    protected $table = 'nexus_permissions';

    protected $fillable = ['key', 'label', 'section', 'sort_order'];
}
