<?php

namespace App\Services\Communications;

use RuntimeException;

/**
 * AT-156 — thrown when the WAHA server cannot be reached (connection refused,
 * timeout, 5xx). Distinct from a logical "no session / wrong state" so the
 * caller can render a graceful "WhatsApp service unavailable, retry" instead
 * of leaking a 500.
 */
class WahaUnavailableException extends RuntimeException
{
}
