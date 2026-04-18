<?php

namespace App\Services\Worksheet;

/**
 * Idempotent pre-install answer → worksheet blocker promoter.
 *
 * Rebuilds the blocker list from source pre-install answers on every call,
 * so flipping an answer from "No" to "Yes" cleanly removes the blocker and
 * regenerating twice without any input change produces byte-identical output.
 *
 * Blocker shape: {type, message, action, room, source: 'pre_install_q_<hash>'}
 *
 * Question pattern recognition is deterministic. Patterns are ordered most-
 * specific first so a "power outlet" question wins over a generic "power"
 * question when both are present.
 */
class BlockerPromoter
{
    /**
     * Canonical answer values that count as a failure for blocker promotion.
     * Case-insensitive comparison against trimmed answer text.
     */
    private const FAILURE_ANSWERS = ['no', 'unknown', 'unsure', 'not sure', 'n/a'];

    /**
     * Question-pattern → typed blocker mapping. Each entry describes:
     *   patterns:    needle substrings (lower-cased haystack match)
     *   type:        short category key for DOCX colour-coding
     *   issue_tpl:   human issue string ({room} placeholder for context)
     *   action_tpl:  remediation string ({room} placeholder)
     *
     * First pattern that matches wins. Order top-specific → generic.
     */
    private const QUESTION_RULES = [
        [
            'patterns'   => ['power outlet', 'power socket', 'power available', 'ring main', 'mains power'],
            'type'       => 'power',
            'issue_tpl'  => '{room}: Power provision unconfirmed at the install position.',
            'action_tpl' => 'Confirm power sockets/ring main are in place and on the correct circuit before first fix.',
        ],
        [
            'patterns'   => ['additional power'],
            'type'       => 'power',
            'issue_tpl'  => '{room}: Additional power outlets flagged as required.',
            'action_tpl' => 'Confirm extra power provision with the client electrician before install.',
        ],
        [
            'patterns'   => ['network port', 'lan port', 'data port', 'ethernet port', 'rj45'],
            'type'       => 'network',
            'issue_tpl'  => '{room}: Network port availability unconfirmed.',
            'action_tpl' => 'Confirm network drop is live and patched at the panel before install.',
        ],
        [
            'patterns'   => ['cable route', 'cable containment', 'trunking', 'conduit', 'containment'],
            'type'       => 'cable_route',
            'issue_tpl'  => '{room}: Cable route/containment path unconfirmed.',
            'action_tpl' => 'Walk the route with the client and confirm containment before first fix.',
        ],
        [
            'patterns'   => ['ceiling void', 'ceiling access', 'suspended ceiling'],
            'type'       => 'structural',
            'issue_tpl'  => '{room}: Ceiling void access unconfirmed.',
            'action_tpl' => 'Confirm ceiling tile access and void clearance before booking install.',
        ],
        [
            'patterns'   => ['wall build', 'wall type', 'wall material', 'structural', 'stud wall'],
            'type'       => 'structural',
            'issue_tpl'  => '{room}: Wall build / fixing substrate unconfirmed.',
            'action_tpl' => 'Confirm wall substrate and order correct fixings before install.',
        ],
        [
            'patterns'   => ['working at height', 'mewp', 'scaffold', 'access equipment'],
            'type'       => 'access',
            'issue_tpl'  => '{room}: Working-at-height access equipment requirement unclear.',
            'action_tpl' => 'Confirm MEWP/scaffold/tower requirement and schedule before install.',
        ],
        [
            'patterns'   => ['asbestos'],
            'type'       => 'hs',
            'issue_tpl'  => '{room}: Asbestos register not confirmed.',
            'action_tpl' => 'Obtain current asbestos register from client FM before starting.',
        ],
        [
            'patterns'   => ['permit', 'ptw', 'permit to work'],
            'type'       => 'permit',
            'issue_tpl'  => '{room}: Permit-to-work requirement unconfirmed.',
            'action_tpl' => 'Confirm PTW process with client site manager before install.',
        ],
    ];

    /**
     * Build a fresh list of blockers from per-room pre-install answers.
     * Output is deterministic: same input → same blocker list, same order.
     *
     * @param array<string, list<array{question:string,answer:string,other_text?:string}>> $preInstallAnswersByRoom
     *        keyed by lowercase room name (as produced by WorksheetGeneratorService)
     * @return list<array{type:string,message:string,action:string,room:string,source:string}>
     */
    public function promoteFromAnswers(array $preInstallAnswersByRoom): array
    {
        $blockers = [];

        foreach ($preInstallAnswersByRoom as $roomKey => $answers) {
            if (! is_array($answers)) continue;
            $roomLabel = $this->labelForRoomKey($roomKey);

            foreach ($answers as $qIdx => $qa) {
                if (! is_array($qa)) continue;
                $question = (string) ($qa['question'] ?? '');
                $answer   = strtolower(trim((string) ($qa['answer'] ?? '')));
                if ($question === '' || ! $this->isFailure($answer, $qa)) continue;

                $rule = $this->ruleFor($question);
                if ($rule === null) {
                    // Generic blocker so the unanswered/failed item is still visible.
                    $blockers[] = [
                        'type'    => 'pre_install',
                        'message' => $roomLabel . ': ' . $question . ' — flagged on pre-install check.',
                        'action'  => 'Resolve before install starts.',
                        'room'    => $roomLabel,
                        'source'  => $this->sourceKey($roomKey, $qIdx, $question),
                    ];
                    continue;
                }

                $blockers[] = [
                    'type'    => $rule['type'],
                    'message' => strtr($rule['issue_tpl'],  ['{room}' => $roomLabel]),
                    'action'  => strtr($rule['action_tpl'], ['{room}' => $roomLabel]),
                    'room'    => $roomLabel,
                    'source'  => $this->sourceKey($roomKey, $qIdx, $question),
                ];
            }
        }

        // Deduplicate by (type, message) — a handful of survey tools ask
        // similar questions worded differently; we don't need two entries.
        $seen = [];
        $unique = [];
        foreach ($blockers as $b) {
            $fp = $b['type'] . '|' . $b['message'];
            if (isset($seen[$fp])) continue;
            $seen[$fp] = true;
            $unique[] = $b;
        }

        return $unique;
    }

    /** True if the answer counts as a failure that should raise a blocker. */
    private function isFailure(string $answer, array $qa): bool
    {
        if ($answer === '') {
            // Empty required answer — only a failure if the survey tool flagged
            // the question as required; we don't have that meta, so ignore.
            return false;
        }
        if (in_array($answer, self::FAILURE_ANSWERS, true)) {
            return true;
        }
        // "Other" with no other_text is effectively unanswered — raise it.
        if ($answer === 'other' && trim((string) ($qa['other_text'] ?? '')) === '') {
            return true;
        }
        return false;
    }

    private function ruleFor(string $question): ?array
    {
        $q = strtolower($question);
        foreach (self::QUESTION_RULES as $rule) {
            foreach ($rule['patterns'] as $p) {
                if (str_contains($q, $p)) {
                    return $rule;
                }
            }
        }
        return null;
    }

    private function sourceKey(string $roomKey, int|string $qIdx, string $question): string
    {
        return 'pre_install_' . substr(md5($roomKey . '|' . $qIdx . '|' . $question), 0, 12);
    }

    private function labelForRoomKey(string $roomKey): string
    {
        $roomKey = trim($roomKey);
        if ($roomKey === '') return 'Unknown Room';
        return ucwords($roomKey);
    }
}
