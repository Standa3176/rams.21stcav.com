<?php

namespace App\Http\Controllers;

use App\Core\Modules\KnowledgeLibrary\HazardLibraryService;
use App\Models\HazardTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HazardTemplateController extends Controller
{
    public function __construct(private readonly HazardLibraryService $library) {}

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $userId  = auth()->id();
        $isAdmin = auth()->user()->isAdmin();

        ['global' => $globalTemplates, 'personal' => $myTemplates] = $this->library->forUser($userId);

        return view('hazard-templates.index', compact('globalTemplates', 'myTemplates', 'isAdmin'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateTemplate($request);

        $isGlobal = auth()->user()->isAdmin() && $request->boolean('is_global');

        HazardTemplate::create([
            'user_id'         => $isGlobal ? null : auth()->id(),
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'pre_likelihood'  => $data['pre_likelihood'],
            'pre_severity'    => $data['pre_severity'],
            'post_likelihood' => $data['post_likelihood'],
            'post_severity'   => $data['post_severity'],
            'controls'        => array_values(array_filter($data['controls'] ?? [])),
            'is_global'       => $isGlobal,
        ]);

        return back()->with('success', 'Hazard template saved.');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function edit(HazardTemplate $hazardTemplate): View
    {
        $this->authorizeTemplate($hazardTemplate);

        return view('hazard-templates.edit', [
            'template' => $hazardTemplate,
            'isAdmin'  => auth()->user()->isAdmin(),
        ]);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function update(Request $request, HazardTemplate $hazardTemplate): RedirectResponse
    {
        $this->authorizeTemplate($hazardTemplate);

        $data = $this->validateTemplate($request);

        $isGlobal = auth()->user()->isAdmin()
            ? $request->boolean('is_global')
            : $hazardTemplate->is_global;

        $hazardTemplate->update([
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'pre_likelihood'  => $data['pre_likelihood'],
            'pre_severity'    => $data['pre_severity'],
            'post_likelihood' => $data['post_likelihood'],
            'post_severity'   => $data['post_severity'],
            'controls'        => array_values(array_filter($data['controls'] ?? [])),
            'is_global'       => $isGlobal,
        ]);

        return redirect()
            ->route('hazard-templates.index')
            ->with('success', 'Template "' . $hazardTemplate->name . '" updated.');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function destroy(HazardTemplate $hazardTemplate): RedirectResponse
    {
        $this->authorizeTemplate($hazardTemplate);

        $name = $hazardTemplate->name;
        $hazardTemplate->delete();

        return back()->with('success', "Template \"{$name}\" deleted.");
    }

    // ── JSON API ──────────────────────────────────────────────────────────────

    public function apiIndex(): JsonResponse
    {
        return response()->json([
            'templates' => $this->library->forUserJson(auth()->id()),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name'            => ['required', 'string', 'max:150'],
            'description'     => ['nullable', 'string', 'max:500'],
            'pre_likelihood'  => ['required', 'integer', 'min:1', 'max:5'],
            'pre_severity'    => ['required', 'integer', 'min:1', 'max:5'],
            'post_likelihood' => ['required', 'integer', 'min:1', 'max:5'],
            'post_severity'   => ['required', 'integer', 'min:1', 'max:5'],
            'controls'        => ['nullable', 'array'],
            'controls.*'      => ['string', 'max:500'],
            'is_global'       => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeTemplate(HazardTemplate $template): void
    {
        abort_unless(
            auth()->user()->isAdmin() || $template->user_id === auth()->id(),
            403
        );
    }
}
