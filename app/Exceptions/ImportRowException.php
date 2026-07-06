<?php

namespace App\Exceptions;

/**
 * A CSV row failed strict parsing or the shared validation invariants.
 * Caught per-row by ImportServersCommand::handle(), which reports the
 * message and continues with the remaining rows.
 */
class ImportRowException extends \RuntimeException
{
}
