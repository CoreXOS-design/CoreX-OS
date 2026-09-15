<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-392 — Johan, 2026-09-07. The ONLY fact this row carries is "this agency
 * has saved this employment type's checklist" — its mere existence is the
 * signal that distinguishes "configured, deliberately empty" from "never
 * configured, V8 defaults apply." See RentalApplicationDocumentRequirement::
 * checklistFor() for how it's consulted, and the migration's own docblock
 * for the full incident this fixes.
 */
class RentalApplicationChecklistConfig extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'employment_type'];
}
