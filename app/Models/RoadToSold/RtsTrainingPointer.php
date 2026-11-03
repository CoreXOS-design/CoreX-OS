<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 5 - the training an agency points at for one funnel ratio (road-to-sold.md section 9.3). One row per (agency, track,
 * ratio); archive and restore only - a re-save of an archived ratio restores the same row (BUILD_STANDARD 5a).
 */
class RtsTrainingPointer extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const KIND_COURSE  = 'course';
    public const KIND_DOC     = 'doc';
    public const KIND_LIBRARY = 'library';
    public const KIND_TOUR    = 'tour';

    public const KINDS = [
        self::KIND_COURSE  => 'Training course',
        self::KIND_DOC     => 'Help document',
        self::KIND_LIBRARY => 'Document library',
        self::KIND_TOUR    => 'Guided tour',
    ];

    protected $table = 'rts_training_pointers';

    protected $fillable = ['agency_id', 'track', 'ratio_key', 'kind', 'target_id', 'target_key', 'label', 'set_by_user_id'];
}
