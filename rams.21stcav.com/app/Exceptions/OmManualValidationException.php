<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when O&M Manual input data fails Tier 1 validation —
 * a required field is missing, a room has no equipment, a room has no
 * narrative, etc.
 *
 * The generator pipeline aborts before any AI call or DOCX/PDF render
 * when this is raised, so weak documents never leave the system.
 *
 * Phase 1 of the Tier 1 upgrade — NO TBC POLICY.
 */
class OmManualValidationException extends RuntimeException
{
    /** @var string[] */
    private array $missingFields;

    /**
     * @param string   $message        Human-readable error including the field list.
     * @param string[] $missingFields  Machine-readable list for UI / logs.
     */
    public function __construct(string $message, array $missingFields = [])
    {
        parent::__construct($message);
        $this->missingFields = $missingFields;
    }

    /** @return string[] */
    public function getMissingFields(): array
    {
        return $this->missingFields;
    }
}
