<?php

namespace App\Core\Modules\Survey;

use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SurveyService
{
    public function __construct(private readonly ProjectService $projects) {}

    // ─── Survey CRUD ─────────────────────────────────────────────────────────

    /**
     * Create a new survey with rooms (no photos at creation time).
     */
    public function create(User $user, array $data): SiteSurvey
    {
        return DB::transaction(function () use ($user, $data) {
            $survey = SiteSurvey::create([
                'user_id'       => $user->id,
                'project_id'    => $data['project_id']    ?? null,
                'project_name'  => $data['project_name'],
                'project_ref'   => $data['project_ref']   ?? null,
                'client_name'   => $data['client_name']   ?? null,
                'site_address'  => $data['site_address']  ?? null,
                'survey_date'   => $data['survey_date']   ?? null,
                'surveyor_name' => $data['surveyor_name'] ?? null,
                'general_notes' => $data['general_notes'] ?? null,
                'status'        => 'draft',
            ]);

            foreach ($data['rooms'] ?? [] as $i => $room) {
                $this->createRoom($survey, $room, $i);
            }

            if ($survey->project_id) {
                /** @var Project $project */
                $project = Project::find($survey->project_id);
                $this->projects->logDocument($project, $user, 'site-survey', $survey->id);
            }

            return $survey;
        });
    }

    /**
     * Update survey header + all rooms (full replace of rooms list).
     */
    public function update(SiteSurvey $survey, User $user, array $data): SiteSurvey
    {
        return DB::transaction(function () use ($survey, $user, $data) {
            $survey->update([
                'project_id'    => $data['project_id']    ?? $survey->project_id,
                'project_name'  => $data['project_name']  ?? $survey->project_name,
                'project_ref'   => $data['project_ref']   ?? null,
                'client_name'   => $data['client_name']   ?? null,
                'site_address'  => $data['site_address']  ?? null,
                'survey_date'   => $data['survey_date']   ?? null,
                'surveyor_name' => $data['surveyor_name'] ?? null,
                'general_notes' => $data['general_notes'] ?? null,
            ]);

            // Rebuild rooms: keep existing IDs where supplied to preserve photos
            $incomingIds = collect($data['rooms'] ?? [])->pluck('id')->filter()->all();
            $survey->rooms()->whereNotIn('id', $incomingIds)->each(function (SiteSurveyRoom $r) {
                $this->deleteRoomPhotos($r);
                $r->delete();
            });

            foreach ($data['rooms'] ?? [] as $i => $roomData) {
                if (!empty($roomData['id'])) {
                    $room = SiteSurveyRoom::find($roomData['id']);
                    if ($room && $room->site_survey_id === $survey->id) {
                        $room->update($this->roomAttributes($roomData, $i));
                        continue;
                    }
                }
                $this->createRoom($survey, $roomData, $i);
            }

            if ($survey->project_id) {
                $project = Project::find($survey->project_id);
                if ($project) {
                    $this->projects->logDocument($project, $user, 'site-survey', $survey->id, 'updated');
                }
            }

            return $survey->fresh();
        });
    }

    /**
     * Mark survey as completed and (optionally) link to a project.
     */
    public function complete(SiteSurvey $survey, User $user, ?int $projectId = null): SiteSurvey
    {
        $survey->update([
            'status'     => 'completed',
            'project_id' => $projectId ?? $survey->project_id,
        ]);

        if ($survey->project_id) {
            $project = Project::find($survey->project_id);
            if ($project) {
                $this->projects->logDocument($project, $user, 'site-survey', $survey->id, 'completed');
            }
        }

        return $survey;
    }

    // ─── Photo management ────────────────────────────────────────────────────

    /**
     * Upload a photo for a specific room and persist to storage.
     */
    public function addPhoto(SiteSurveyRoom $room, UploadedFile $file, ?string $caption = null): SiteSurveyPhoto
    {
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename  = Str::uuid() . '.' . $extension;

        Storage::disk('local')->putFileAs('survey-photos', $file, $filename);

        $sortOrder = $room->photos()->max('sort_order') + 1;

        return $room->photos()->create([
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType() ?? 'image/jpeg',
            'caption'       => $caption,
            'sort_order'    => $sortOrder,
        ]);
    }

    /**
     * Delete a photo record and its file from disk.
     */
    public function deletePhoto(SiteSurveyPhoto $photo): void
    {
        Storage::disk('local')->delete('survey-photos/' . $photo->filename);
        $photo->delete();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createRoom(SiteSurvey $survey, array $data, int $index): SiteSurveyRoom
    {
        return $survey->rooms()->create($this->roomAttributes($data, $index));
    }

    private function roomAttributes(array $data, int $index): array
    {
        return [
            'room_name'                 => $data['room_name'],
            'room_ref'                  => $data['room_ref']                  ?? null,
            'floor'                     => $data['floor']                     ?? null,
            'room_width_m'              => $data['room_width_m']              ?? null,
            'room_depth_m'              => $data['room_depth_m']              ?? null,
            'room_height_m'             => $data['room_height_m']             ?? null,
            'ceiling_type'              => $data['ceiling_type']              ?? null,
            'ceiling_height_m'          => $data['ceiling_height_m']          ?? null,
            'wall_material'             => $data['wall_material']             ?? null,
            'floor_type'                => $data['floor_type']                ?? null,
            'av_requirements'           => $data['av_requirements']           ?? null,
            'av_equipment_list'         => $data['av_equipment_list']         ?? null,
            'has_power'                 => ! empty($data['has_power']),
            'has_network'               => ! empty($data['has_network']),
            'power_outlet_count'        => (int) ($data['power_outlet_count'] ?? 0),
            'network_port_count'        => (int) ($data['network_port_count'] ?? 0),
            'existing_cabling'          => $data['existing_cabling']          ?? null,
            'requires_additional_power' => ! empty($data['requires_additional_power']),
            'access_notes'              => $data['access_notes']              ?? null,
            'notes'                     => $data['notes']                     ?? null,
            'sort_order'                => $index,
        ];
    }

    private function deleteRoomPhotos(SiteSurveyRoom $room): void
    {
        foreach ($room->photos as $photo) {
            Storage::disk('local')->delete('survey-photos/' . $photo->filename);
        }
        $room->photos()->delete();
    }
}
