<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a user attempts to clock in while they already have an open
 * time_entry on the same project (INST-04g — one-open-entry guard).
 *
 * The controller translates this to an HTTP 422 JSON response with the
 * message as the error payload.
 */
class ClockInBlockedException extends RuntimeException
{
    public static function alreadyOpen(int $projectId, int $userId): self
    {
        return new self(sprintf(
            'You already have an open clock-in on project #%d (user #%d). Clock out first.',
            $projectId,
            $userId,
        ));
    }
}
