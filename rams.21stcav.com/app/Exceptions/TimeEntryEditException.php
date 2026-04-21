<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when retro-editing or heartbeating a time_entry violates the domain
 * invariants (entry closed, entry still open, disallowed field, invalid value).
 *
 * The controller translates each of these to an HTTP 422 JSON response — the
 * exception message is the payload shown to the user (see TimeEntryController
 * update() / heartbeat()).
 *
 * Mirrors the ClockInBlockedException → 422 pattern established in Phase 14.
 *
 * @see \App\Exceptions\ClockInBlockedException
 * @see \App\Services\TimeEntryService::editEntry
 * @see \App\Services\TimeEntryService::recordHeartbeat
 */
class TimeEntryEditException extends RuntimeException
{
    public static function alreadyClosed(int $entryId): self
    {
        return new self(sprintf(
            'Cannot heartbeat a closed entry #%d.',
            $entryId,
        ));
    }

    public static function entryStillOpen(int $entryId): self
    {
        return new self(sprintf(
            'Cannot edit an open entry #%d. Clock out first.',
            $entryId,
        ));
    }

    public static function invalidField(string $field): self
    {
        return new self(sprintf(
            "Field '%s' is not retro-editable.",
            $field,
        ));
    }

    public static function invalidCategory(string $value): self
    {
        return new self(sprintf(
            "Category '%s' is not a valid time-entry category.",
            $value,
        ));
    }

    public static function noteTooLong(int $length): self
    {
        return new self(sprintf(
            'Note is too long (%d/500).',
            $length,
        ));
    }
}
