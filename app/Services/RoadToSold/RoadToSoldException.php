<?php

namespace App\Services\RoadToSold;

use RuntimeException;

/** AT-454 — a refusal with a message a non-technical agent can act on (never a stack trace). */
class RoadToSoldException extends RuntimeException
{
}
