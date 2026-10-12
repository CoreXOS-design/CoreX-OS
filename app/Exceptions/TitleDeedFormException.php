<?php

namespace App\Exceptions;

use App\Models\TitleDeeds\TitleDeedFormClaim;

/** A one-time form token that cannot be used: not ours / not valid, too old, or already used (then the existing claim rides along). */
class TitleDeedFormException extends \RuntimeException
{
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const ALREADY = 'already';

    public function __construct(public readonly string $kind, public readonly ?TitleDeedFormClaim $claim = null)
    {
        parent::__construct(match ($kind) {
            self::ALREADY => 'That form was already sent, so nothing was done a second time.',
            self::EXPIRED => 'That form has expired. Please open the screen again and resend it.',
            default => 'That form could not be checked. Please open the screen again and resend it.',
        });
    }
}
