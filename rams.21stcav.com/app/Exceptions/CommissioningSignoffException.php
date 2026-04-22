<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when commissioning signoff pre-conditions fail.
 *
 * Mirrors the Phase 15 ClockInBlockedException pattern: domain exception
 * carrying a user-facing message, translated to an HTTP 422 JSON response by
 * the controller that catches it. Controllers:
 *
 *     try {
 *         $this->commissioningService->finalise(...);
 *     } catch (CommissioningSignoffException $e) {
 *         return response()->json(['message' => $e->getMessage()], 422);
 *     }
 *
 * Named factories (itemsImmutable, itemsStillPending, invalidStateTransition,
 * alreadySigned) centralise copy so the same wording appears across the HTTP
 * layer and the re-sync service.
 */
class CommissioningSignoffException extends RuntimeException
{
    /**
     * INST-05i — an item was edited after its programme was signed off.
     * The item id is included in the message so engineers can identify the
     * row they tried to mutate (surfaced in toast / flash banner).
     */
    public static function itemsImmutable(int $itemId): self
    {
        return new self(sprintf(
            'Commissioning item #%d cannot be edited — programme has been signed off.',
            $itemId,
        ));
    }

    /**
     * INST-05 — finalise was attempted while one or more items are still
     * STATUS_PENDING. Caller includes the count so the message is useful
     * without a round trip for a "how many are left?" query.
     */
    public static function itemsStillPending(int $count): self
    {
        return new self(sprintf(
            '%d commissioning item(s) are still pending. Mark every item pass/fail/n-a before signing off.',
            $count,
        ));
    }

    /**
     * Phase 16 finalise flow — the Project must be in STATUS_INSTALLING to
     * transition to STATUS_COMMISSIONING via CommissioningService. Any other
     * source state is refused with this exception to surface the underlying
     * lifecycle issue (e.g. a duplicate finalise after state already moved).
     */
    public static function invalidStateTransition(string $current, string $desired): self
    {
        return new self(sprintf(
            'Project cannot transition from "%s" to "%s". Expected source state "installing".',
            $current,
            $desired,
        ));
    }

    /**
     * Pitfall 7 — a second concurrent finalise attempt arrived after the
     * first inserted a CommissioningSignoff row. The DB UNIQUE constraint
     * catches the race; the service layer wraps the integrity exception in
     * this one so the caller sees a recognisable domain error.
     */
    public static function alreadySigned(int $programmeId): self
    {
        return new self(sprintf(
            'Install programme #%d has already been signed off.',
            $programmeId,
        ));
    }
}
