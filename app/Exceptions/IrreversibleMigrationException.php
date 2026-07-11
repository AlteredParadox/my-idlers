<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by a migration's down() on drivers that cannot express the
 * reversal — SQLite cannot drop foreign keys, and half-reverting (e.g.
 * restoring NOT NULL sentinel defaults while the constraints remain)
 * would leave a schema that rejects its own defaults. Restore from a
 * backup or use migrate:fresh instead.
 */
class IrreversibleMigrationException extends RuntimeException
{
}
