<?php

namespace Tests\Feature\DocumentEdits;

use App\Models\SiteSurvey;
use App\Models\User;
use App\Services\DocumentEdits\Adapters\SurveyEditAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyEditAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeSurveyWithRoom(): array
    {
        $u = User::factory()->create();
        // Quick task 260816-t5c: `access_token` is guarded on SiteSurvey (Re-audit
        // S-03) — SiteSurvey::boot()'s creating hook auto-generates a UUID
        // regardless of anything passed here, so the mass-assign attempt was a
        // silent no-op. This test never reads access_token back, so the key is
        // simply dropped rather than force-filled.
        $survey = SiteSurvey::create([
            'user_id'      => $u->id,
            'project_name' => 'Survey Test',
            'status'       => 'draft',
        ]);
        $room = $survey->rooms()->create([
            'room_name'  => 'Boardroom',
            'sort_order' => 0,
        ]);
        return compact('survey', 'room');
    }

    public function test_update_room_dimensions_writes_values(): void
    {
        ['survey' => $s, 'room' => $r] = $this->makeSurveyWithRoom();
        $adapter = new SurveyEditAdapter();
        $payload = $adapter->loadPayload($s->id);

        $res = $adapter->applyOperation($payload, [
            'op' => 'update_room_dimensions', 'room_id' => $r->id,
            'room_width_m' => 6.0, 'room_depth_m' => 4.0, 'room_height_m' => 3.0,
        ]);
        $this->assertTrue($res['ok']);
        $this->assertSame(6.0, $res['payload']['rooms'][0]['room_width_m']);
    }

    public function test_update_room_dimensions_rejects_out_of_range(): void
    {
        ['survey' => $s, 'room' => $r] = $this->makeSurveyWithRoom();
        $adapter = new SurveyEditAdapter();
        $payload = $adapter->loadPayload($s->id);

        $res = $adapter->applyOperation($payload, [
            'op' => 'update_room_dimensions', 'room_id' => $r->id,
            'room_width_m' => 0,
        ]);
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_op', $res['code']);
    }

    public function test_set_room_power_true_with_outlets(): void
    {
        ['survey' => $s, 'room' => $r] = $this->makeSurveyWithRoom();
        $adapter = new SurveyEditAdapter();
        $payload = $adapter->loadPayload($s->id);

        $res = $adapter->applyOperation($payload, [
            'op' => 'set_room_power', 'room_id' => $r->id,
            'has_power' => true, 'power_outlet_count' => 4,
        ]);
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['payload']['rooms'][0]['has_power']);
        $this->assertSame(4, $res['payload']['rooms'][0]['power_outlet_count']);
    }

    public function test_unknown_room_id_returns_error(): void
    {
        ['survey' => $s] = $this->makeSurveyWithRoom();
        $adapter = new SurveyEditAdapter();
        $payload = $adapter->loadPayload($s->id);

        $res = $adapter->applyOperation($payload, [
            'op' => 'update_room_dimensions', 'room_id' => 999999,
            'room_width_m' => 3, 'room_depth_m' => 3, 'room_height_m' => 3,
        ]);
        $this->assertFalse($res['ok']);
        $this->assertSame('room_not_found', $res['code']);
    }

    public function test_commit_persists_to_db(): void
    {
        ['survey' => $s, 'room' => $r] = $this->makeSurveyWithRoom();
        $adapter = new SurveyEditAdapter();
        $payload = $adapter->loadPayload($s->id);

        $res = $adapter->applyOperation($payload, [
            'op' => 'update_av_requirements', 'room_id' => $r->id,
            'av_requirements' => 'Samsung 75" + Rally Bar',
        ]);
        $this->assertTrue($res['ok']);

        $adapter->commitChanges($s->id, $res['payload']);
        $this->assertSame('Samsung 75" + Rally Bar', $r->fresh()->av_requirements);
    }
}
