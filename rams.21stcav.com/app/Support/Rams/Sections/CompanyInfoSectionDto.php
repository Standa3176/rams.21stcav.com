<?php

namespace App\Support\Rams\Sections;

/**
 * Section 2 — Company Information (name, address, phone, email, website).
 *
 * Populated by RamsDocumentComposer (Plan 02) from `config('rams.*')`
 * (RAMS_COMPANY_* env vars). Consumed by both renderers.
 */
final readonly class CompanyInfoSectionDto
{
    public function __construct(
        public string $name    = '',
        public string $address = '',
        public string $phone   = '',
        public string $email   = '',
        public string $website = '',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name:    (string) ($data['name']    ?? ''),
            address: (string) ($data['address'] ?? ''),
            phone:   (string) ($data['phone']   ?? ''),
            email:   (string) ($data['email']   ?? ''),
            website: (string) ($data['website'] ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return $this->name === ''
            && $this->address === ''
            && $this->phone === ''
            && $this->email === ''
            && $this->website === '';
    }
}
