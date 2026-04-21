<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the POST /time-entries/{entry}/heartbeat body — empty body expected.
 *
 * Ownership and state checks live in TimeEntryService::recordHeartbeat(); this
 * request exists only so the controller signature stays consistent with the
 * other time-entry endpoints.
 */
class HeartbeatTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
