<?php

namespace App\DTO;

/**
 * Value object returned by ProjectHealthService::assess().
 *
 * Immutable per-project health summary used by the enterprise dashboard
 * to render green/amber/red badges and overdue indicators.
 *
 * @see \App\Services\ProjectHealthService  Producer of this value object.
 *
 * @property string $status  One of 'green' | 'amber' | 'red'.
 * @property string $reason  Human-readable explanation for the health status.
 * @property bool   $overdue True when the current stage has been active for > 14 days.
 */
readonly class ProjectHealth
{
    public function __construct(
        public string $status,
        public string $reason,
        public bool   $overdue,
    ) {
    }
}
