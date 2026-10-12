<?php

namespace App\Exceptions;

use App\Models\TitleDeeds\TitleDeed;

/**
 * Logging a deed for a property (or deal) that already has an OPEN deed (not archived, not collected). Not a hard block - a
 * property can legitimately get a later deed after a re-sale - so the caller asks the user to confirm explicitly.
 */
class TitleDeedDuplicateException extends \RuntimeException
{
    public function __construct(public readonly TitleDeed $existing)
    {
        parent::__construct('A title deed for this property is already logged and not yet collected.');
    }
}
