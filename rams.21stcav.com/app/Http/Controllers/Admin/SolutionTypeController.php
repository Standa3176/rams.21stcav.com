<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SolutionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SolutionTypeController extends Controller
{
    public function index(): View
    {
        $types = SolutionType::ordered()->get();
        return view('admin.solution-types.index', compact('types'));
    }

    public function create(): View
    {
        $type = new SolutionType();
        return view('admin.solution-types.edit', compact('type'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        SolutionType::create($data);
        return redirect()->route('admin.solution-types.index')
                         ->with('success', 'Solution type created.');
    }

    public function edit(SolutionType $solutionType): View
    {
        $type = $solutionType;
        return view('admin.solution-types.edit', compact('type'));
    }

    public function update(Request $request, SolutionType $solutionType): RedirectResponse
    {
        $data = $this->validated($request, $solutionType);
        $solutionType->update($data);
        return redirect()->route('admin.solution-types.index')
                         ->with('success', 'Solution type updated.');
    }

    public function destroy(SolutionType $solutionType): RedirectResponse
    {
        $solutionType->delete();
        return redirect()->route('admin.solution-types.index')
                         ->with('success', 'Solution type deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validated(Request $request, ?SolutionType $existing = null): array
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:500'],
            'survey_checklist' => ['nullable', 'string'],
            'install_method'   => ['nullable', 'string'],
            'sort_order'       => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'        => ['nullable', 'boolean'],
        ]);

        $data['slug']       = Str::slug($data['name']);
        $data['is_active']  = $request->boolean('is_active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
