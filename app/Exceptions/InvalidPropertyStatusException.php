<?php

namespace App\Exceptions;

/**
 * AT-307 — thrown by the PropertyObserver saving-guard when a write would persist
 * a status outside Property::ALLOWED_STATUSES. The last-resort backstop for paths
 * that skip request validation (jobs, console, imports, direct saves).
 */
class InvalidPropertyStatusException extends \DomainException
{
}
