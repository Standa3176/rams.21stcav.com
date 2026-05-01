<?php

namespace App\Services\DocumentEdits;

use App\Services\DocumentEdits\Adapters\CableEditAdapter;
use App\Services\DocumentEdits\Adapters\DrawingEditAdapter;
use App\Services\DocumentEdits\Adapters\OmEditAdapter;
use App\Services\DocumentEdits\Adapters\RamsEditAdapter;
use App\Services\DocumentEdits\Adapters\SurveyEditAdapter;
use App\Services\DocumentEdits\Adapters\WorksheetEditAdapter;
use InvalidArgumentException;

/**
 * Central lookup of document-type → adapter. Resolved as a singleton in the
 * container so every caller shares the same (stateless) adapter instances.
 */
class DocumentEditAdapterRegistry
{
    /** @var array<string, class-string<DocumentEditAdapterInterface>> */
    private const DEFAULT_MAP = [
        'rams' => RamsEditAdapter::class,
        'survey' => SurveyEditAdapter::class,
        'worksheet' => WorksheetEditAdapter::class,
        'om' => OmEditAdapter::class,
        'cable' => CableEditAdapter::class,
        'drawing' => DrawingEditAdapter::class,
    ];

    /** @var array<string, DocumentEditAdapterInterface> */
    private array $adapters = [];

    /**
     * Register / override an adapter. Useful in tests.
     */
    public function register(DocumentEditAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->documentType()] = $adapter;
    }

    /**
     * Resolve the adapter for the given type or throw InvalidArgumentException
     * when unknown. Controller converts the exception into a 404.
     */
    public function for(string $type): DocumentEditAdapterInterface
    {
        if (isset($this->adapters[$type])) {
            return $this->adapters[$type];
        }
        if (isset(self::DEFAULT_MAP[$type])) {
            $instance = app(self::DEFAULT_MAP[$type]);
            $this->adapters[$type] = $instance;

            return $instance;
        }
        throw new InvalidArgumentException("Unknown document type: {$type}");
    }

    /** @return array<int, string> */
    public function supportedTypes(): array
    {
        return array_keys(self::DEFAULT_MAP);
    }
}
