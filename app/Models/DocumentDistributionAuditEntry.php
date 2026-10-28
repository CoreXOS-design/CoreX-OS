<?php

namespace App\Models;

use App\Models\Concerns\StampsOnBehalfOf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One save of the Deal Document Distribution matrix: who, when, and the cells added / removed. Append-only. */
class DocumentDistributionAuditEntry extends Model
{
    use StampsOnBehalfOf;

    public const UPDATED_AT = null;

    protected $table = 'document_distribution_audit_entries';

    protected $fillable = ['agency_id', 'user_id', 'summary', 'changes'];

    protected $casts = ['changes' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The distribution audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The distribution audit log is append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }
}
