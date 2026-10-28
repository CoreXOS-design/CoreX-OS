<?php

namespace App\Services\Desk;

/** A take-over request, answer or report that the current state does not allow. $status is the HTTP status to answer with. */
class DeskControlException extends \RuntimeException
{
    public function __construct(string $message, public int $status = 409)
    {
        parent::__construct($message);
    }
}
