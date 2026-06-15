<?php

namespace App\Services\Communications;

/**
 * Thrown by the ImapMailboxPoller watchdog (AT-40) when a single mailbox poll
 * exceeds its time budget — i.e. a folder read went non-responsive. Distinct
 * type so the per-message "one bad message never blocks the rest" catch can
 * re-throw it and abort the whole poll instead of swallowing it.
 */
class ImapPollTimeoutException extends \RuntimeException
{
}
