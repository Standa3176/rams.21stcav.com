<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\SiteSurvey;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;

class SurveyEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'survey';
    }

    public function loadPayload(int $documentId): ?array
    {
        $survey = SiteSurvey::query()->with('rooms.photos', 'rooms.questions')->find($documentId);
        if ($survey === null) return null;
        return [
            'project_name' => $survey->project_name,
            'client_name'  => $survey->client_name,
            'site_address' => $survey->site_address,
            'status'       => $survey->status,
            'rooms'        => $survey->rooms->map(fn ($r) => $r->only([
                'id', 'room_name', 'room_width_m', 'room_depth_m', 'room_height_m',
                'av_requirements', 'has_power', 'power_outlet_count',
                'has_network', 'network_port_count',
            ]))->all(),
        ];
    }

    public function allowedOperations(): array
    {
        return [];
    }

    public function applyOperation(array $payload, array $op): array
    {
        return [
            'ok'    => false,
            'code'  => 'not_implemented_in_pass_a',
            'error' => "Survey operation '{$op['op']}' is not implemented yet — available from the next pass.",
        ];
    }
}
