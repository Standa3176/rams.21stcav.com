<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeviceCableRuleRequest;
use App\Models\DeviceCableRule;
use Illuminate\Http\RedirectResponse;
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
     * Pull the validated payload minus the textarea shim
     * (`keywords_raw`); FormRequest already merged the split array as
     * `keywords`, so we hand the model a clean fillable set.
     */
    private function extractData(DeviceCableRuleRequest $request): array
    {
        $data = $request->validated();
        unset($data['keywords_raw']);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
