<?php

namespace App\Support;

/**
 * Thrown by a publishing service when ExternalPublishingGuard refuses a write and
 * the caller has no array-style "failed" envelope to return (Meta posts).
 */
class ExternalPublishingRefused extends \RuntimeException
{
}
