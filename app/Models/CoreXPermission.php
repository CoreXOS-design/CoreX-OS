<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoreXPermission extends Model
{
    use SoftDeletes;

    protected $table = 'nexus_permissions';

    protected $fillable = ['key', 'label', 'section', 'type', 'module', 'sort_order'];
}
