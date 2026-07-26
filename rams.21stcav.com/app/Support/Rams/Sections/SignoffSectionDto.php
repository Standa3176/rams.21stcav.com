<?php

namespace App\Support\Rams\Sections;

/**
 * Section 8 — Sign-Off table (21CAV signatory | Client acceptance).
 *
 * Both sides use the same shape:
 *   [ 'name' => 'Alex Bloggs', 'position' => 'Lead Engineer',
 *     'date' => 'dd/mm/yyyy', 'sig' => '' ]
 *
 * The `client` side ships mostly-empty on generation (blanks for the
 * client to write into on print / e-sign). Populated by
 * RamsDocumentComposer (Plan 02).
 */
final readonly class SignoffSectionDto
{
    /**
     * @param  array<string, string>  $company
     * @param  array<string, string>  $client
     */
    public function __construct(
        public array $company = [],
        public array $client  = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $normalise = static function (mixed $side): array {
            $side = (array) $side;
            return [
                'name'     => (string) ($side['name']     ?? ''),
                'position' => (string) ($side['position'] ?? ''),
                'date'     => (string) ($side['date']     ?? ''),
                'sig'      => (string) ($side['sig']      ?? ''),
            ];
        };

        return new self(
            company: $normalise($data['company'] ?? []),
            client:  $normalise($data['client']  ?? []),
        );
    }

    /**
     * Empty when neither side carries any populated fields. A default-
     * constructed instance ships four empty strings per side; those are
     * treated as "blank" for the client-accept row so isEmpty() still
     * returns true.
     */
    public function isEmpty(): bool
    {
        $sideEmpty = static function (array $s): bool {
            foreach ($s as $v) {
                if ((string) $v !== '') {
                    return false;
                }
            }
            return true;
        };

        return $sideEmpty($this->company) && $sideEmpty($this->client);
    }
}
