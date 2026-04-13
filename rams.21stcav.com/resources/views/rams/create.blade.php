@extends('layouts.app')

@section('title', $project ? 'Create RAMS — ' . $project->name : 'Create RAMS Document')

@section('content')

    <div class="page-header">
        <h1 class="page-title">{{ $project ? 'Create RAMS — ' . $project->name : 'Create RAMS Document' }}</h1>
        <a href="{{ route('rams.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
    </div>

    {{-- Session error (AI failure) --}}
    @if (session('error'))
        <div class="alert alert-error" role="alert">
            <strong>Generation failed:</strong> {{ session('error') }}
        </div>
    @endif

    {{-- Validation error summary --}}
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <strong>Please correct the following errors:</strong>
            <ul style="margin: .5rem 0 0 1.2rem; font-size:.875rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ route('rams.store') }}"
          id="rams-form"
          novalidate>
        @csrf
        <input type="hidden" name="project_id" value="{{ old('project_id', $project?->id) }}">

        {{-- ════════════════════════════════════════════════════════════
             SECTION A — Project Details
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">A — Project Details</h2>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="project_ref">Project Reference</label>
                    <input type="text"
                           id="project_ref"
                           name="project_ref"
                           class="form-control @error('project_ref') is-invalid @enderror"
                           value="{{ old('project_ref') ?? $project?->ref ?? '' }}"
                           placeholder="e.g. 21CQ-25001">
                    @error('project_ref')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="project_name">
                        Project Name <span class="req">*</span>
                    </label>
                    <input type="text"
                           id="project_name"
                           name="project_name"
                           class="form-control @error('project_name') is-invalid @enderror"
                           value="{{ old('project_name') ?? $project?->name ?? '' }}"
                           required>
                    @error('project_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="client_name">
                        Client Name <span class="req">*</span>
                    </label>
                    <input type="text"
                           id="client_name"
                           name="client_name"
                           class="form-control @error('client_name') is-invalid @enderror"
                           value="{{ old('client_name') ?? $project?->client_name ?? '' }}"
                           required>
                    @error('client_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="site_contact">Site Contact</label>
                    <input type="text"
                           id="site_contact"
                           name="site_contact"
                           class="form-control @error('site_contact') is-invalid @enderror"
                           value="{{ old('site_contact') }}"
                           placeholder="Contact name on site">
                    @error('site_contact')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="site_address">
                    Site Address <span class="req">*</span>
                </label>
                <textarea id="site_address"
                          name="site_address"
                          class="form-control @error('site_address') is-invalid @enderror"
                          rows="2"
                          required>{{ old('site_address') ?? $project?->site_address ?? '' }}</textarea>
                @error('site_address')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="start_date">Start Date</label>
                    <input type="text"
                           id="start_date"
                           name="start_date"
                           class="form-control @error('start_date') is-invalid @enderror"
                           value="{{ old('start_date') }}"
                           placeholder="e.g. March 2026">
                    @error('start_date')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="expected_duration">Expected Duration</label>
                    <input type="text"
                           id="expected_duration"
                           name="expected_duration"
                           class="form-control @error('expected_duration') is-invalid @enderror"
                           value="{{ old('expected_duration') }}"
                           placeholder="e.g. 2 weeks">
                    @error('expected_duration')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="works_description">
                    Works Description <span class="req">*</span>
                </label>
                <textarea id="works_description"
                          name="works_description"
                          class="form-control @error('works_description') is-invalid @enderror"
                          rows="4"
                          minlength="20"
                          required>{{ old('works_description') ?? $project?->works_description ?? '' }}</textarea>
                <div class="form-help">
                    Describe the AV works in detail — the AI uses this to tailor control measures.
                    Be specific: equipment types, mounting methods, cable routes, commissioning tasks.
                </div>
                @error('works_description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION B — Operations Info
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">B — Operations Info <span style="font-weight:400; font-size:.9rem; color:#666;">(optional)</span></h2>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="project_manager">Project Manager</label>
                    <input type="text"
                           id="project_manager"
                           name="project_manager"
                           class="form-control @error('project_manager') is-invalid @enderror"
                           value="{{ old('project_manager') }}"
                           placeholder="Name">
                    @error('project_manager')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="lead_engineer">Lead Engineer</label>
                    <input type="text"
                           id="lead_engineer"
                           name="lead_engineer"
                           class="form-control @error('lead_engineer') is-invalid @enderror"
                           value="{{ old('lead_engineer') }}"
                           placeholder="Name">
                    @error('lead_engineer')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label" for="additional_engineers">Additional Engineer(s)</label>
                    <input type="text"
                           id="additional_engineers"
                           name="additional_engineers"
                           class="form-control @error('additional_engineers') is-invalid @enderror"
                           value="{{ old('additional_engineers') }}"
                           placeholder="Comma-separated names">
                    @error('additional_engineers')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="programmer">Programmer</label>
                    <input type="text"
                           id="programmer"
                           name="programmer"
                           class="form-control @error('programmer') is-invalid @enderror"
                           value="{{ old('programmer') }}"
                           placeholder="Name">
                    @error('programmer')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION C — Hazards
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">C — Hazards <span class="req">*</span></h2>
            <p style="font-size:.875rem; color:#555; margin-bottom:.9rem;">
                Select all hazards applicable to this project (minimum 1).
            </p>

            @error('hazards')
                <div class="alert alert-error" style="margin-bottom:.9rem;">{{ $message }}</div>
            @enderror

            <div class="check-grid check-grid-3">
                @foreach ($hazardLibrary as $hazard)
                    <label class="check-item">
                        <input type="checkbox"
                               name="hazards[]"
                               value="{{ $hazard }}"
                               {{ in_array($hazard, old('hazards', [])) ? 'checked' : '' }}>
                        <span>{{ $hazard }}</span>
                    </label>
                @endforeach
            </div>

            {{-- Custom hazards (text list) --}}
            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label" for="custom_hazard_input">
                    Add Custom Hazard
                    <span style="font-weight:400; color:#666;">(optional)</span>
                </label>
                <div style="display:flex; gap:.5rem; align-items:center;flex-wrap:wrap;">
                    <input type="text"
                           id="custom_hazard_input"
                           class="form-control"
                           placeholder="Type a hazard and click Add"
                           style="max-width:360px;">
                    <button type="button"
                            class="btn btn-outline btn-sm"
                            onclick="addCustomHazard()">
                        Add
                    </button>
                    @if ($hazardTemplates->isNotEmpty())
                        <button type="button"
                                class="btn btn-outline btn-sm"
                                onclick="openLibraryModal()"
                                style="border-color:#6c757d;color:#6c757d;">
                            Load from Library
                        </button>
                    @endif
                </div>
                <div id="custom-hazards-list" style="margin-top:.6rem; display:flex; flex-wrap:wrap; gap:.4rem;"></div>
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION D — PPE Required
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">D — PPE Required <span class="req">*</span></h2>
            <p style="font-size:.875rem; color:#555; margin-bottom:.9rem;">
                Select all PPE required for this project (minimum 1).
            </p>

            @error('ppe')
                <div class="alert alert-error" style="margin-bottom:.9rem;">{{ $message }}</div>
            @enderror

            <div class="check-grid check-grid-2">
                @foreach ($ppeOptions as $ppe)
                    <label class="check-item">
                        <input type="checkbox"
                               name="ppe[]"
                               value="{{ $ppe }}"
                               {{ in_array($ppe, old('ppe', [])) ? 'checked' : '' }}>
                        <span>{{ $ppe }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION E — Persons at Risk
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">E — Persons at Risk <span class="req">*</span></h2>

            @error('persons_at_risk')
                <div class="alert alert-error" style="margin-bottom:.9rem;">{{ $message }}</div>
            @enderror

            <div class="check-grid check-grid-5">
                @foreach ($personsOptions as $person)
                    <label class="check-item">
                        <input type="checkbox"
                               name="persons_at_risk[]"
                               value="{{ $person }}"
                               {{ in_array($person, old('persons_at_risk', [])) ? 'checked' : '' }}>
                        <span>{{ $person }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION F — Engineering Team
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">F — Engineering Team <span style="font-weight:400; font-size:.9rem; color:#666;">(optional)</span></h2>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:.5rem; margin-bottom:.4rem; padding: 0 0 .3rem;">
                <span style="font-size:.8rem; font-weight:600; color:#555;">Name</span>
                <span style="font-size:.8rem; font-weight:600; color:#555;">Role</span>
                <span style="font-size:.8rem; font-weight:600; color:#555;">Mobile</span>
                <span></span>
            </div>

            <div id="team-rows">
                {{-- Repopulate on validation fail --}}
                @if (old('team'))
                    @foreach (old('team') as $i => $member)
                        <div class="team-row" data-row="{{ $i }}">
                            <input type="text"
                                   name="team[{{ $i }}][name]"
                                   class="form-control"
                                   value="{{ $member['name'] ?? '' }}"
                                   placeholder="Full name">
                            <select name="team[{{ $i }}][role]" class="form-control">
                                @foreach (['Project Manager','Lead Engineer','Engineer','Programmer'] as $role)
                                    <option value="{{ $role }}" {{ ($member['role'] ?? '') === $role ? 'selected' : '' }}>
                                        {{ $role }}
                                    </option>
                                @endforeach
                            </select>
                            <input type="text"
                                   name="team[{{ $i }}][mobile]"
                                   class="form-control"
                                   value="{{ $member['mobile'] ?? '' }}"
                                   placeholder="07xxx xxxxxx">
                            <button type="button"
                                    class="btn btn-danger-outline btn-sm"
                                    onclick="removeTeamRow(this)">✕</button>
                        </div>
                    @endforeach
                @endif
            </div>

            <button type="button"
                    class="btn btn-outline btn-sm"
                    onclick="addTeamRow()"
                    style="margin-top:.5rem;">
                ＋ Add team member
            </button>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION G — Emergency Contact
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">G — Emergency Contact <span style="font-weight:400; font-size:.9rem; color:#666;">(optional)</span></h2>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="emergency_contact">Contact Name</label>
                    <input type="text"
                           id="emergency_contact"
                           name="emergency_contact"
                           class="form-control"
                           value="{{ old('emergency_contact') }}"
                           placeholder="Emergency contact name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="emergency_tel">Telephone / Mobile</label>
                    <input type="text"
                           id="emergency_tel"
                           name="emergency_tel"
                           class="form-control"
                           value="{{ old('emergency_tel') }}"
                           placeholder="e.g. 0800 999 9999">
                </div>
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION H — Document Author
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <h2 class="section-heading">H — Document Author <span style="font-weight:400; font-size:.9rem; color:#666;">(optional)</span></h2>

            <div class="form-group" style="max-width:360px;">
                <label class="form-label" for="doc_author">Prepared by</label>
                <input type="text"
                       id="doc_author"
                       name="doc_author"
                       class="form-control"
                       value="{{ old('doc_author', auth()->user()->name ?? '') }}"
                       placeholder="Your name">
            </div>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             SECTION I — AI Provider (advanced, collapsible)
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block">
            <details class="secondary-section">
                <summary>Advanced: AI Provider Settings</summary>

                <div class="details-body">
                    <div class="form-group" style="max-width:300px;">
                        <label class="form-label" for="ai_provider">AI Provider</label>
                        <select id="ai_provider"
                                name="ai_provider"
                                class="form-control @error('ai_provider') is-invalid @enderror">
                            <option value="claude"
                                {{ old('ai_provider', $defaultProvider) === 'claude' ? 'selected' : '' }}>
                                Claude (Recommended)
                            </option>
                            <option value="openai"
                                {{ old('ai_provider', $defaultProvider) === 'openai' ? 'selected' : '' }}>
                                OpenAI GPT-4o
                            </option>
                            <option value="custom"
                                {{ old('ai_provider', $defaultProvider) === 'custom' ? 'selected' : '' }}>
                                Custom
                            </option>
                        </select>
                        @error('ai_provider')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <p class="form-help">
                        Default provider set by administrator. Change only if required.
                    </p>
                </div>
            </details>
        </div>

        {{-- ════════════════════════════════════════════════════════════
             Submit
        ══════════════════════════════════════════════════════════════ --}}
        <div class="section-block" style="text-align:center;">
            <p style="font-size:.875rem; color:#666; margin-bottom:1rem;">
                The AI will generate a full Risk Assessment &amp; Method Statement based on your inputs.
                A branded <strong>.docx</strong> file will download automatically.
            </p>
            <button type="submit"
                    id="submit-btn"
                    class="btn btn-teal btn-full">
                ⚡ Generate RAMS Document
            </button>
            <p id="loading-msg"
               style="display:none; margin-top:.75rem; color:#007B8A; font-size:.875rem; font-weight:500;">
                Generating… this may take 15–30 seconds. Please do not close this tab.
            </p>
        </div>

    </form>

    {{-- Hazard Library Modal --}}
    @if ($hazardTemplates->isNotEmpty())
    <div id="library-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:1.75rem 2rem;width:100%;max-width:520px;max-height:80vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.2);position:relative;">
            <button onclick="closeLibraryModal()" style="position:absolute;top:.75rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#888;">&times;</button>
            <h2 style="font-size:1.05rem;color:#007B8A;margin-bottom:1rem;">Hazard Library</h2>
            <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Click a template to add it as a custom hazard.</p>
            <div style="display:flex;flex-direction:column;gap:.4rem;">
                @foreach ($hazardTemplates as $tpl)
                    <button type="button"
                            onclick="addFromLibrary({{ json_encode($tpl->name) }})"
                            style="text-align:left;background:#fafafa;border:1.5px solid #e0e0e0;border-radius:4px;padding:.55rem .85rem;cursor:pointer;font-size:.875rem;transition:border-color .15s,background .15s;"
                            onmouseover="this.style.borderColor='#007B8A';this.style.background='#e9f6f7';"
                            onmouseout="this.style.borderColor='#e0e0e0';this.style.background='#fafafa';">
                        <strong>{{ $tpl->name }}</strong>
                        @if ($tpl->description)
                            <span style="color:#888;font-size:.8rem;"> — {{ Str::limit($tpl->description, 60) }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>
    @endif

@endsection

@push('scripts')
<script>
    // ── Team member rows ─────────────────────────────────────────────────────
    let teamIndex = {{ old('team') ? count(old('team')) : 0 }};
    const roleOptions = ['Project Manager', 'Lead Engineer', 'Engineer', 'Programmer'];

    function addTeamRow() {
        const container = document.getElementById('team-rows');
        const div = document.createElement('div');
        div.className = 'team-row';
        div.dataset.row = teamIndex;

        const rolesHtml = roleOptions
            .map(r => `<option value="${r}">${r}</option>`)
            .join('');

        div.innerHTML = `
            <input type="text"
                   name="team[${teamIndex}][name]"
                   class="form-control"
                   placeholder="Full name">
            <select name="team[${teamIndex}][role]" class="form-control">
                ${rolesHtml}
            </select>
            <input type="text"
                   name="team[${teamIndex}][mobile]"
                   class="form-control"
                   placeholder="07xxx xxxxxx">
            <button type="button"
                    class="btn btn-danger-outline btn-sm"
                    onclick="removeTeamRow(this)">✕</button>
        `;

        container.appendChild(div);
        teamIndex++;
        div.querySelector('input').focus();
    }

    function removeTeamRow(btn) {
        btn.closest('.team-row').remove();
    }

    // ── Custom hazards ───────────────────────────────────────────────────────
    let customHazardCount = 0;

    function addCustomHazard() {
        const input = document.getElementById('custom_hazard_input');
        const val   = input.value.trim();
        if (!val) return;

        const list = document.getElementById('custom-hazards-list');
        const id   = 'custom_hazard_' + customHazardCount++;

        const tag = document.createElement('div');
        tag.style.cssText = 'display:inline-flex;align-items:center;gap:.35rem;' +
                            'background:#e0f4f6;border:1.5px solid #007B8A;' +
                            'border-radius:3px;padding:.25rem .6rem;font-size:.8125rem;color:#007B8A;';
        tag.innerHTML = `
            <span>${val}</span>
            <input type="hidden" name="hazards[]" value="${val}">
            <button type="button"
                    style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:.85rem;padding:0;"
                    onclick="this.closest('div').remove()">✕</button>
        `;

        list.appendChild(tag);
        input.value = '';
        input.focus();
    }

    // Allow Enter key to add custom hazard
    document.getElementById('custom_hazard_input')
        .addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); addCustomHazard(); }
        });

    // ── Hazard library modal ─────────────────────────────────────────────────
    function openLibraryModal() {
        const m = document.getElementById('library-modal');
        if (m) { m.style.display = 'flex'; }
    }

    function closeLibraryModal() {
        const m = document.getElementById('library-modal');
        if (m) { m.style.display = 'none'; }
    }

    function addFromLibrary(name) {
        const input = document.getElementById('custom_hazard_input');
        const saved = input.value;
        input.value = name;
        addCustomHazard();
        input.value = saved;
        closeLibraryModal();
    }

    const libModal = document.getElementById('library-modal');
    if (libModal) {
        libModal.addEventListener('click', function (e) {
            if (e.target === this) closeLibraryModal();
        });
    }

    // ── Loading state on submit ──────────────────────────────────────────────
    document.getElementById('rams-form').addEventListener('submit', function () {
        const btn = document.getElementById('submit-btn');
        const msg = document.getElementById('loading-msg');
        btn.disabled    = true;
        btn.textContent = 'Generating… please wait';
        msg.style.display = 'block';
    });
</script>
@endpush
