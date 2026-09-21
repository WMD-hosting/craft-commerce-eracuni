<?php

declare(strict_types=1);

namespace wmd\commerceeracuni\jobs;

/** Thrown by {@see SendInvoiceJob} when it cannot acquire the send mutex; retryable. */
final class MutexBusyException extends \RuntimeException
{
}
