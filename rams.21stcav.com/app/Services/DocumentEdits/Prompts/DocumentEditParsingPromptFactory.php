<?php

namespace App\Services\DocumentEdits\Prompts;

use App\Services\DocumentEdits\DocumentEditAdapterInterface;

/**
 * Builds DocumentEditParsingPrompt instances with a minimal "safe subset"
 * payload snapshot — never includes raw body text, cross-document data, or
 * anything the adapter itself wouldn't normally expose.
 */
class DocumentEditParsingPromptFactory
{
    /**
     * @param list<array{code: string, message: string}> $priorErrors
     */
    public function make(
        DocumentEditAdapterInterface $adapter,
        string  $userMessage,
        ?array  $documentPayload,
        array   $priorErrors    = [],
        ?string $priorRawOutput = null,
    ): DocumentEditParsingPrompt {
        return new DocumentEditParsingPrompt(
            documentType:      $adapter->documentType(),
            userMessage:       trim($userMessage),
            allowedOperations: $adapter->allowedOperations(),
            payloadSnapshot:   $this->safeSnapshot($adapter->documentType(), $documentPayload),
            priorErrors:       $priorErrors,
            priorRawOutput:    $priorRawOutput,
        );
    }

    /**
     * Strip document payload down to the minimum the model needs to target
     * operations. No full text content, no IDs outside the adapter's scope,
     * no secrets. This is deliberately conservative — safer to undersharea
     * than to leak.
     */
    private function safeSnapshot(string $documentType, ?array $payload): array
    {
        if ($payload === null) return [];

        return match ($documentType) {
            'worksheet' => $this->worksheetSnapshot($payload),
            'rams'      => $this->ramsSnapshot($payload),
            'survey'    => $this->surveySnapshot($payload),
            'om'        => $this->omSnapshot($payload),
            'cable'     => $this->cableSnapshot($payload),
            default     => [],
        };
    }

    private function worksheetSnapshot(array $p): array
    {
        $rooms = [];
        foreach ((array) ($p['rooms'] ?? []) as $r) {
            $rooms[] = [
                'name'             => (string) ($r['name'] ?? ''),
                'tools_count'      => count((array) ($r['tools'] ?? [])),
                'install_steps_count' => is_array($r['install_steps'] ?? null) ? count($r['install_steps']) : 0,
                'categories'       => array_values(array_keys((array) ($r['subsystems'] ?? []))),
            ];
        }
        return [
            'project_name'     => (string) ($p['project']['name'] ?? ''),
            'rooms'            => $rooms,
            'blockers_count'   => count((array) ($p['blockers'] ?? [])),
        ];
    }

    private function ramsSnapshot(array $p): array
    {
        return [
            'project_name'       => (string) ($p['generated_data']['project']['name'] ?? ''),
            'project_ref'        => (string) ($p['generated_data']['project']['ref']  ?? ''),
            'exclusions_count'   => count((array) ($p['reviewed_data']['exclusions'] ?? [])),
            'client_responsibilities_count' => count((array) ($p['reviewed_data']['client_responsibilities_expanded'] ?? [])),
        ];
    }

    private function surveySnapshot(array $p): array
    {
        $rooms = [];
        foreach ((array) ($p['rooms'] ?? []) as $r) {
            $rooms[] = [
                'id'        => $r['id'] ?? null,
                'room_name' => (string) ($r['room_name'] ?? ''),
            ];
        }
        return [
            'project_name' => (string) ($p['survey']['project_name'] ?? ''),
            'rooms'        => $rooms,
        ];
    }

    private function omSnapshot(array $p): array
    {
        return [
            'project_name'            => (string) ($p['generated_data']['project']['name'] ?? ''),
            'contacts_count'          => count((array) ($p['generated_data']['contacts'] ?? [])),
            'maintenance_items_count' => count((array) ($p['generated_data']['maintenance_schedule'] ?? [])),
        ];
    }

    private function cableSnapshot(array $p): array
    {
        $items = [];
        foreach ((array) ($p['items'] ?? []) as $i) {
            $items[] = ['cable_id' => (string) ($i['cable_id'] ?? '')];
        }
        return [
            'project_ref' => (string) ($p['project_ref'] ?? ''),
            'items'       => $items,
        ];
    }
}
