<?php

declare(strict_types=1);

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One uploaded wet-ink signed copy of a PPRA employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md §20
 *
 * The ONLY place a signed scan lives — reached through PpraEmploymentLetter::files()/currentFile(), so the admin
 * register and My Portal show the very same row. The newest row is "current"; earlier rows are history
 * ("superseded"), never overwritten and never hard-deleted.
 */
class PpraEmploymentLetterFile extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'ppra_employment_letter_files';

    public const VIA_ADMIN  = 'admin';
    public const VIA_PORTAL = 'portal';

    protected $fillable = [
        'agency_id',
        'letter_id',
        'path',
        'original_name',
        'size',
        'mime',
        'uploaded_by_user_id',
        'uploaded_via',
    ];

    protected $casts = ['size' => 'integer'];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(PpraEmploymentLetter::class, 'letter_id')->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
