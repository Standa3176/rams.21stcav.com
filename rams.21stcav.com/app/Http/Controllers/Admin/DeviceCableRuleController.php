<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeviceCableRuleRequest;
use App\Models\DeviceCableRule;
use App\Services\CableScheduleGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Quick task 260711-q7q — Tier 4 admin CRUD for cable inference rules.
 *
 * Consumers: CableScheduleGeneratorService::inferCableRun() walks
 * DeviceCableRule::forInference() priority ASC. Rule saved / deleted
 * events flush the 1h Cache::remember so writes propagate instantly.
 *
 * The 13 canonical rules (15 rows after mic + amp splits) are seeded
 * by DeviceCableRulesSeeder — admin can add, edit, delete arbitrary
 * additional rules from here without a deploy.
 */
class DeviceCableRuleController extends Controller
{
    public function index(): View
    {
        $rules = DeviceCableRule::orderBy('priority')->paginate(15);

        return view('admin.device-cable-rules.index', compact('rules'));
    }

    public function create(): View
    {
        $rule = new DeviceCableRule([
            'priority'  => 500,
            'is_active' => true,
        ]);

        return view('admin.device-cable-rules.edit', compact('rule'));
    }

    public function store(DeviceCableRuleRequest $request): RedirectResponse
    {
        $data = $this->extractData($request);

        $rule = DeviceCableRule::create($data);

        Log::info('Admin: device cable rule created', [
            'rule_id'  => $rule->id,
            'priority' => $rule->priority,
            'admin_id' => auth()->id(),
        ]);

        return redirect()
            ->route('admin.device-cable-rules.index')
            ->with('success', "Rule #{$rule->id} created (priority {$rule->priority}).");
    }

    public function edit(DeviceCableRule $deviceCableRule): View
    {
        $rule = $deviceCableRule;

        return view('admin.device-cable-rules.edit', compact('rule'));
    }

    public function update(DeviceCableRuleRequest $request, DeviceCableRule $deviceCableRule): RedirectResponse
    {
        $data = $this->extractData($request);

        $deviceCableRule->update($data);

        Log::info('Admin: device cable rule updated', [
            'rule_id'  => $deviceCableRule->id,
            'priority' => $deviceCableRule->priority,
            'admin_id' => auth()->id(),
        ]);

        return redirect()
            ->route('admin.device-cable-rules.index')
            ->with('success', "Rule #{$deviceCableRule->id} updated.");
    }

    public function destroy(DeviceCableRule $deviceCableRule): RedirectResponse
    {
        $id = $deviceCableRule->id;
        $deviceCableRule->delete();

        Log::info('Admin: device cable rule deleted', [
            'rule_id'  => $id,
            'admin_id' => auth()->id(),
        ]);

        return redirect()
            ->route('admin.device-cable-rules.index')
            ->with('success', "Rule #{$id} deleted.");
    }

    /**
     * 260712-ip3 — JSON preview endpoint. Given an equipment name +
     * optional cable length, returns the matched rule + full walker
     * trace so admins can eyeball rule behaviour without SSH-ing to the
     * box. Read-only; persists nothing. Route is registered BEFORE the
     * resource route so the string `preview` isn't caught by the
     * `{deviceCableRule}` param.
     *
     * @see \App\Services\CableScheduleGeneratorService::previewInference
     */
    public function preview(Request $request, CableScheduleGeneratorService $generator): JsonResponse
    {
        $data = $request->validate([
            'equipment' => ['required', 'string', 'min:1', 'max:255'],
            'length_m'  => ['nullable', 'numeric', 'gt:0', 'max:100000'],
        ]);

        Log::info('Admin: device cable rule preview requested', [
            'admin_id'  => auth()->id(),
            'equipment' => $data['equipment'],
            'length_m'  => $data['length_m'] ?? null,
        ]);

        return response()->json(
            $generator->previewInference($data['equipment'], isset($data['length_m']) ? (float) $data['length_m'] : null)
        );
    }

    /**
     * Pull the validated payload minus the textarea shims
     * (`keywords_raw`, `negative_keywords_raw`); FormRequest already
     * merged the split arrays as `keywords` and `negative_keywords`,
     * so we hand the model a clean fillable set.
     */
    private function extractData(DeviceCableRuleRequest $request): array
    {
        $data = $request->validated();
        unset($data['keywords_raw'], $data['negative_keywords_raw']);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
