<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

/** AT-479 — the uploaded file is not the reOS report it should be. The message is written for the person who uploaded it. */
class ReosLayoutException extends \RuntimeException
{
}
