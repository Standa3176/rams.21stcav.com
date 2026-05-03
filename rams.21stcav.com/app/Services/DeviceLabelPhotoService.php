<?php

namespace App\Services;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\LabelExtractionPrompt;
use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\Worksheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Handles engineer-captured equipment label photos:
 *   1. Persist the photo to disk under projects/{id}/labels/.
 *   2. Send the image to Claude vision via LabelExtractionPrompt.
 *   3. Store the extracted JSON on a DeviceLabelPhoto row.
 *   4. (On confirm) write the values into the Device record.
 *
 * Failures in the AI step DO NOT fail the upload — the photo is still saved
 * and the engineer can fill the fields manually.
 */
class DeviceLabelPhotoService
{
    public function capture(
        Project $project,
        UploadedFile $file,
        ?Device $device = null,
        ?Worksheet $worksheet = null,
        ?string $roomName = null,
        ?string $capturedBy = null,
    ): DeviceLabelPhoto {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/heic',
            'image/heif' => 'heic',
            default      => 'jpg',
        };

        $basename  = (string) Str::uuid() . '.' . $extension;
        $directory = "projects/{$project->id}/labels";
        $storedPath = "{$directory}/{$basename}";
        Storage::disk('public')->putFileAs($directory, $file, $basename);

        $aiExtracted = $this->extractWithAI($file);

        $photo = DeviceLabelPhoto::create([
            'project_id'   => $project->id,
            'device_id'    => $device?->id,
            'worksheet_id' => $worksheet?->id,
            'room_name'    => $roomName ?? $device?->room_name,
            'photo_path'   => $storedPath,
            'ai_extracted' => $aiExtracted,
            'confirmed'    => false,
            'captured_at'  => now(),
            'captured_by'  => $capturedBy,
        ]);

        Log::info('DeviceLabelPhotoService: photo captured', [
            'photo_id'   => $photo->id,
            'device_id'  => $device?->id,
            'project_id' => $project->id,
            'ai_ok'      => $aiExtracted !== null,
            'confidence' => $aiExtracted['confidence'] ?? null,
        ]);

        return $photo;
    }

    /**
     * Persist the engineer's edits and write them onto the linked Device.
     *
     * @param  array  $fields  Sanitised values: part_number, serial_number,
     *                         mac_address, model, manufacturer.
     */
    public function confirm(DeviceLabelPhoto $photo, array $fields): DeviceLabelPhoto
    {
        $photo->update([
            'ai_extracted' => array_merge((array) $photo->ai_extracted, [
                'confirmed_values' => $fields,
            ]),
            'confirmed' => true,
        ]);

        if ($photo->device) {
            $deviceUpdate = array_filter([
                'part_no'       => $fields['part_number']   ?? null,
                'serial_number' => $fields['serial_number'] ?? null,
                'mac_address'   => $fields['mac_address']   ?? null,
                'model'         => $fields['model']         ?? null,
                'manufacturer'  => $fields['manufacturer']  ?? null,
            ], fn ($v) => $v !== null && $v !== '' && strtoupper((string) $v) !== 'UNKNOWN');

            if (! empty($deviceUpdate)) {
                $photo->device->update($deviceUpdate);
            }
        }

        return $photo->fresh();
    }

    public function delete(DeviceLabelPhoto $photo): void
    {
        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function extractWithAI(UploadedFile $file): ?array
    {
        try {
            $base64    = base64_encode(file_get_contents($file->getRealPath()));
            $mediaType = $file->getMimeType() ?? 'image/jpeg';

            $prompt = (new LabelExtractionPrompt())->setImage($base64, $mediaType);
            $result = AIManager::run($prompt, []);

            return is_array($result) ? $result : null;
        } catch (\Throwable $e) {
            Log::warning('DeviceLabelPhotoService: AI extraction failed (engineer can still fill manually)', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
