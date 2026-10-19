<?php

declare(strict_types=1);

namespace App\Exceptions\Articles;

use RuntimeException;

/**
 * The AI answered, but what it wrote cannot be used (wrong length, empty, unsafe markup).
 * That is a verdict on the ITEM, not a hiccup — the Article Library remembers it so the same item
 * is not paid for again on every run. Network/API trouble is a different failure and is never this.
 */
final class BriefRejectedException extends RuntimeException
{
}
