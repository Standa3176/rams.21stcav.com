<?php

namespace App\Support\Rams\Sections;

/**
 * Section 6 — Method Statement (the largest section by far).
 *
 * Bundles every subsection that the current DocxBuilderService renders
 * inline after the primary Method of Works block (§6.1-6.10 in the
 * hand-crafted reference doc):
 *
 * team                    — [ ['role' => 'Lead Engineer', 'qty' => 1,
 *                              'requirements' => '18th Edition qualified'] ]
 * tools                   — [ 'Cordless drill 18V', ... ]
 * ppe                     — map<task_name, ppe_list[]>
 * access_equipment        — [ 'Class 1 stepladder', 'PODIUM steps', ... ]
 * access_requirements     — [ 'Client provides swipe access', ... ]
 * client_responsibilities — [ 'Client to isolate power', ... ]
 * steps                   — the ordered execution steps.
 *                           Each: [ 'title' => 'Cable Pull', 'bullets' => [...],
 *                                   'associated_risks' => ['RA01', 'RA04'] ]
 * material_handling       — §6.7 bullets
 * permits                 — §6.8 permit-to-work bullets
 * fixings_controls        — §6.9 bullets
 * supervision             — §6.10 bullets
 * coordination            — Cross-trade coordination bullets
 * it_safety               — IT/network safety bullets
 *
 * Populated by RamsDocumentComposer (Plan 02).
 */
final readonly class MethodStatementSectionDto
{
    /**
     * @param  array<int, array<string, mixed>>        $team
     * @param  array<int, string>                      $tools
     * @param  array<string, array<int, string>>       $ppe
     * @param  array<int, string>                      $accessEquipment
     * @param  array<int, string>                      $accessRequirements
     * @param  array<int, string>                      $clientResponsibilities
     * @param  array<int, array<string, mixed>>        $steps
     * @param  array<int, string>                      $materialHandling
     * @param  array<int, string>                      $permits
     * @param  array<int, string>                      $fixingsControls
     * @param  array<int, string>                      $supervision
     * @param  array<int, string>                      $coordination
     * @param  array<int, string>                      $itSafety
     */
    public function __construct(
        public array $team                   = [],
        public array $tools                  = [],
        public array $ppe                    = [],
        public array $accessEquipment        = [],
        public array $accessRequirements     = [],
        public array $clientResponsibilities = [],
        public array $steps                  = [],
        public array $materialHandling       = [],
        public array $permits                = [],
        public array $fixingsControls        = [],
        public array $supervision            = [],
        public array $coordination           = [],
        public array $itSafety               = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $stringList = static fn (mixed $v): array => array_values(array_map('strval', (array) $v));

        $team = [];
        foreach ((array) ($data['team'] ?? []) as $m) {
            $m = (array) $m;
            $team[] = [
                'role'         => (string) ($m['role']         ?? ''),
                'qty'          => (int)    ($m['qty']          ?? 0),
                'requirements' => (string) ($m['requirements'] ?? ''),
            ];
        }

        $ppe = [];
        foreach ((array) ($data['ppe'] ?? []) as $task => $items) {
            $ppe[(string) $task] = $stringList($items);
        }

        $steps = [];
        foreach ((array) ($data['steps'] ?? []) as $step) {
            $step = (array) $step;
            $steps[] = [
                'title'            => (string) ($step['title'] ?? ''),
                'bullets'          => $stringList($step['bullets']          ?? []),
                'associated_risks' => $stringList($step['associated_risks'] ?? []),
            ];
        }

        return new self(
            team:                   $team,
            tools:                  $stringList($data['tools']                   ?? []),
            ppe:                    $ppe,
            accessEquipment:        $stringList($data['access_equipment']        ?? []),
            accessRequirements:     $stringList($data['access_requirements']     ?? []),
            clientResponsibilities: $stringList($data['client_responsibilities'] ?? []),
            steps:                  $steps,
            materialHandling:       $stringList($data['material_handling']       ?? []),
            permits:                $stringList($data['permits']                 ?? []),
            fixingsControls:        $stringList($data['fixings_controls']        ?? []),
            supervision:            $stringList($data['supervision']             ?? []),
            coordination:           $stringList($data['coordination']            ?? []),
            itSafety:               $stringList($data['it_safety']               ?? []),
        );
    }

    public function isEmpty(): bool
    {
        return $this->team === []
            && $this->tools === []
            && $this->ppe === []
            && $this->accessEquipment === []
            && $this->accessRequirements === []
            && $this->clientResponsibilities === []
            && $this->steps === []
            && $this->materialHandling === []
            && $this->permits === []
            && $this->fixingsControls === []
            && $this->supervision === []
            && $this->coordination === []
            && $this->itSafety === [];
    }
}
