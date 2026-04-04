<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the AI provider fails to return a valid response
 * after all retry attempts are exhausted.
 *
 * Used by App\Core\AI\AIManager::run().
 */
class AIGenerationException extends RuntimeException {}
