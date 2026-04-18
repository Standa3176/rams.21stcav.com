---
phase: quick
plan: 260414-jli
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/RamsController.php
  - resources/views/rams/review.blade.php
  - resources/views/pdf/rams.blade.php
autonomous: true
requirements: []
must_haves:
  truths:
    - "reviewer can enter scope traceability rows pre-filled from quote line_items"
    - "reviewer can toggle client responsibility checkboxes and add extras"
    - "reviewer can edit exclusions list with defaults pre-filled"
    - "reviewer can enable decommissioning section and fill fields/steps"
    - "reviewer can enter commissioning criteria rows"
    - "all five sections persist to reviewed_data on Save & Download"
    - "PDF renders all five sections in correct positions with correct structure"
  artifacts:
    - path: app/Http/Controllers/RamsController.php
      provides: validation and persistence for all five new reviewed_data sub-keys
    - path: resources/views/rams/review.blade.php
      provides: editable form sections for all five new fields
    - path: resources/views/pdf/rams.blade.php
      provides: PDF sections for scope traceability, exclusions, expanded client responsibilities, decommissioning, commissioning criteria
  key_links:
    - from: review.blade.php form inputs
      to: updateAndDownload() validation rules
      via: POST name attributes matching validation keys
    - from: updateAndDownload() $reviewedData array
      to: rams.blade.php $php block
      via: $rams->reviewed_data sub-keys
---

<objective>
Add five new structured sections to the RAMS review form and PDF output: Scope Traceability Table, Client Responsibilities (Expanded), Exclusions, Decommissioning Procedure, and Commissioning Criteria. All data stored in reviewed_data JSON — no migration required.

Purpose: Provides traceability between quoted scope and RAMS activities, captures structured client responsibilities and exclusions, and adds commissioning sign-off criteria to the generated PDF.
Output: Updated controller (validation + persistence), updated review form (editable sections), updated PDF template (five new rendered sections).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md

Key interfaces already in codebase (do not change these patterns):

In RamsController::review() — transient pre-fill pattern (NOT saved):
```php
$rd   = $rams->reviewed_data ?? [];
$prog = $rd['programme']     ?? [];
// ... assign to variables, then pass compact('rams')
return view('rams.review', compact('rams'));
```

In RamsController::updateAndDownload() — persist pattern:
```php
$reviewedData = $rams->reviewed_data ?? [];
$reviewedData['new_key'] = [...];
$rams->update(['reviewed_data' => $reviewedData]);
```

In review.blade.php — @php variable assignment before @foreach (CRITICAL constraint):
```blade
@php $someList = old('key', $rd['key'] ?? []); @endphp
@forelse($someList as $idx => $item) ... @endforelse
```

In review.blade.php — JS add-row pattern used for material_handling_items:
```js
var rowIndex = {{ count($someList) ?: 1 }};
function addRow() {
    var tbody = document.getElementById('tbody_id');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input name="key['+rowIndex+'][field]"></td>';
    tbody.appendChild(tr); rowIndex++;
}
```

PDF section numbering context:
- Section 4: Scope of Works (equipment table ends at line ~770)
- Section 5: Risk Assessment
- Section 6: Method Statement + subsections 6.1-6.5
  - 6.3 is "Pre-Installation Requirements (Client Responsibilities)"
- Permits & Authorisations (unnumbered heading)
- CDM 2015 Duty Holders (unnumbered heading)
- COSHH Assessment (unnumbered heading)
- Environmental Management (unnumbered heading)
- Welfare Arrangements (unnumbered heading)
- Section 7: Emergency Procedures
- Section 8: Document Sign-Off

Quote line_items shape (from $data['quote']['line_items'] in PDF / $rams->generated_data['quote']['line_items'] in controller):
```php
['description' => string, 'qty' => string|int, 'room' => string, 'part_number' => string]
```
</context>

<tasks>

<task type="auto">
  <name>Task 1: Controller — pre-fill review() and validate+persist updateAndDownload()</name>
  <files>app/Http/Controllers/RamsController.php</files>
  <action>
Two changes to RamsController, both below the existing $rd / $prog assignments.

**In review() — after the existing $prog and $rd reads (around line 388 before `return view()`):**

Add transient pre-fill for all five new sub-keys. Do NOT save these — assign as variables only passed via compact('rams') is already the pattern; the blade reads directly from $rams->reviewed_data, so we need to store them on the transient $reviewedData that's already mutated in $rd. Actually the correct pattern is: assign PHP variables from $rd and pass nothing extra — the blade reads $rams->reviewed_data ?? []. So add this block before the return:

```php
// Pre-fill scope_traceability from quote line_items when not yet reviewed
if (empty($rd['scope_traceability'])) {
    $lineItems = $gd['quote']['line_items'] ?? [];
    if (is_array($lineItems) && count($lineItems) > 0) {
        $rd['scope_traceability'] = array_values(array_map(fn ($li) => [
            'quote_item'    => ($li['description'] ?? ''),
            'rams_activity' => '',
            'room'          => ($li['room'] ?? ''),
            'notes'         => '',
        ], $lineItems));
    }
}

// Default exclusions when not yet reviewed
if (! isset($rd['exclusions'])) {
    $rd['exclusions'] = [
        'No structural works',
        'No core drilling unless explicitly scoped',
        'No containment beyond surface trunking',
        'No decorative making good after cable routes',
        'No IT network provision unless scoped',
    ];
}

// Ensure other sub-keys exist with empty defaults so blade never gets null
$rd['client_responsibilities_expanded'] = $rd['client_responsibilities_expanded'] ?? [];
$rd['decommissioning']                  = $rd['decommissioning']                  ?? [];
$rd['commissioning_criteria']           = $rd['commissioning_criteria']           ?? [];
```

Then assign this back transientally: `$rams->reviewed_data = $rd; // transient — not saved` (same pattern as line 386 for generated_data).

**In updateAndDownload() — after the existing CDM block (after line ~497, before $rams->update([...])):**

Add validation rules to the existing $request->validate([...]) call for all five new sections:

```php
// Scope traceability
'scope_traceability'                 => ['nullable', 'array'],
'scope_traceability.*.quote_item'    => ['nullable', 'string', 'max:500'],
'scope_traceability.*.rams_activity' => ['nullable', 'string', 'max:500'],
'scope_traceability.*.room'          => ['nullable', 'string', 'max:200'],
'scope_traceability.*.notes'         => ['nullable', 'string', 'max:500'],

// Client responsibilities expanded
'client_resp_network_readiness_required'  => ['nullable', 'boolean'],
'client_resp_network_readiness_notes'     => ['nullable', 'string', 'max:500'],
'client_resp_licences_required'           => ['nullable', 'boolean'],
'client_resp_licences_notes'              => ['nullable', 'string', 'max:500'],
'client_resp_access_required'             => ['nullable', 'boolean'],
'client_resp_access_notes'                => ['nullable', 'string', 'max:500'],
'client_resp_power_validation_required'   => ['nullable', 'boolean'],
'client_resp_power_validation_notes'      => ['nullable', 'string', 'max:500'],
'client_resp_additional'                  => ['nullable', 'array'],
'client_resp_additional.*.item'           => ['nullable', 'string', 'max:300'],
'client_resp_additional.*.notes'          => ['nullable', 'string', 'max:500'],

// Exclusions
'exclusions'   => ['nullable', 'array'],
'exclusions.*' => ['nullable', 'string', 'max:500'],

// Decommissioning
'decommissioning_enabled'               => ['nullable', 'boolean'],
'decommissioning_labelling_procedure'   => ['nullable', 'string', 'max:1000'],
'decommissioning_storage_location'      => ['nullable', 'string', 'max:500'],
'decommissioning_client_sign_off'       => ['nullable', 'boolean'],
'decommissioning_disposal_method'       => ['nullable', 'string', 'max:500'],
'decommissioning_steps'                 => ['nullable', 'array'],
'decommissioning_steps.*'               => ['nullable', 'string', 'max:500'],

// Commissioning criteria
'commissioning_criteria'                         => ['nullable', 'array'],
'commissioning_criteria.*.system'                => ['nullable', 'string', 'max:200'],
'commissioning_criteria.*.criterion'             => ['nullable', 'string', 'max:500'],
'commissioning_criteria.*.verification_method'   => ['nullable', 'string', 'max:300'],
'commissioning_criteria.*.pass_condition'        => ['nullable', 'string', 'max:300'],
```

Then add persistence after CDM block and before $rams->update([...]):

```php
// Scope traceability
$stInput = $request->input('scope_traceability', []);
$reviewedData['scope_traceability'] = array_values(array_filter(
    is_array($stInput) ? $stInput : [],
    fn ($row) => ! empty($row['quote_item']) || ! empty($row['rams_activity'])
));

// Client responsibilities expanded
$crAdditional = $request->input('client_resp_additional', []);
$reviewedData['client_responsibilities_expanded'] = [
    'network_readiness'  => ['required' => $request->boolean('client_resp_network_readiness_required'),  'notes' => $validated['client_resp_network_readiness_notes']  ?? ''],
    'licences'           => ['required' => $request->boolean('client_resp_licences_required'),           'notes' => $validated['client_resp_licences_notes']           ?? ''],
    'access'             => ['required' => $request->boolean('client_resp_access_required'),             'notes' => $validated['client_resp_access_notes']             ?? ''],
    'power_validation'   => ['required' => $request->boolean('client_resp_power_validation_required'),   'notes' => $validated['client_resp_power_validation_notes']   ?? ''],
    'additional'         => array_values(array_filter(
        is_array($crAdditional) ? $crAdditional : [],
        fn ($r) => ! empty($r['item'])
    )),
];

// Exclusions — filter empty strings
$exclusionsInput = $request->input('exclusions', []);
$reviewedData['exclusions'] = array_values(array_filter(
    is_array($exclusionsInput) ? $exclusionsInput : [],
    fn ($e) => trim((string) $e) !== ''
));

// Decommissioning
$decomSteps = $request->input('decommissioning_steps', []);
$reviewedData['decommissioning'] = [
    'enabled'                 => $request->boolean('decommissioning_enabled'),
    'labelling_procedure'     => $validated['decommissioning_labelling_procedure'] ?? '',
    'storage_location'        => $validated['decommissioning_storage_location']    ?? '',
    'client_sign_off_required'=> $request->boolean('decommissioning_client_sign_off'),
    'disposal_method'         => $validated['decommissioning_disposal_method']     ?? '',
    'steps'                   => array_values(array_filter(
        is_array($decomSteps) ? $decomSteps : [],
        fn ($s) => trim((string) $s) !== ''
    )),
];

// Commissioning criteria
$ccInput = $request->input('commissioning_criteria', []);
$reviewedData['commissioning_criteria'] = array_values(array_filter(
    is_array($ccInput) ? $ccInput : [],
    fn ($row) => ! empty($row['system']) || ! empty($row['criterion'])
));
```

Follow the existing ASCII art comment divider style. Log::info not required for these fields (no state transition, just data edits).
  </action>
  <verify>
php artisan route:list | grep rams (no errors)
php -r "require 'vendor/autoload.php';" (no parse errors — run from project root)
  </verify>
  <done>review() pre-fills scope_traceability from quote line_items when empty and sets default exclusions; updateAndDownload() validates and persists all five reviewed_data sub-keys without breaking existing fields.</done>
</task>

<task type="auto">
  <name>Task 2: Review form — five new editable sections inside the form</name>
  <files>resources/views/rams/review.blade.php</files>
  <action>
Insert five new form sections inside the existing `<form method="POST" ...>` tag, AFTER the CDM/Welfare block (after the welfare_notes textarea, before the submit buttons div at line ~316). Each section uses the established pattern: @php variable assignment first, then @forelse loops.

Update the @php block at the top of the view (lines 7-20) to add:
```php
$scopeTraceability  = $rd['scope_traceability']                  ?? [];
$clientRespExp      = $rd['client_responsibilities_expanded']     ?? [];
$exclusionsList     = $rd['exclusions']                           ?? [];
$decommData         = $rd['decommissioning']                      ?? [];
$commCriteria       = $rd['commissioning_criteria']               ?? [];
```

Then insert the five sections in this order before the submit buttons div:

---

**Section A — Scope Traceability**
```blade
{{-- ── Scope Traceability ──────────────────────────────────────────────── --}}
<h3 class="section-heading" style="margin-top:1rem;">Scope Traceability</h3>
<p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Map each quoted item to its RAMS installation activity. Pre-filled from quote where available.</p>
<table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
    <thead>
        <tr>
            <th>Quote Item / Description</th>
            <th>RAMS Activity</th>
            <th style="width:130px;">Room / Area</th>
            <th>Notes</th>
            <th style="width:40px;"></th>
        </tr>
    </thead>
    <tbody id="st_tbody">
    @php $stList = old('scope_traceability', $scopeTraceability); @endphp
    @forelse($stList as $stIdx => $stRow)
        @php $stRow = is_array($stRow) ? $stRow : []; @endphp
        <tr class="st-row">
            <td><input type="text" name="scope_traceability[{{ $stIdx }}][quote_item]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['quote_item'] ?? '' }}"></td>
            <td><input type="text" name="scope_traceability[{{ $stIdx }}][rams_activity]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['rams_activity'] ?? '' }}"></td>
            <td><input type="text" name="scope_traceability[{{ $stIdx }}][room]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['room'] ?? '' }}"></td>
            <td><input type="text" name="scope_traceability[{{ $stIdx }}][notes]" class="form-control" style="font-size:.85rem;" value="{{ $stRow['notes'] ?? '' }}"></td>
            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
        </tr>
    @empty
        <tr class="st-row">
            <td><input type="text" name="scope_traceability[0][quote_item]" class="form-control" style="font-size:.85rem;" placeholder="e.g. 100&quot; Display"></td>
            <td><input type="text" name="scope_traceability[0][rams_activity]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Wall mount and cable"></td>
            <td><input type="text" name="scope_traceability[0][room]" class="form-control" style="font-size:.85rem;" placeholder="Room"></td>
            <td><input type="text" name="scope_traceability[0][notes]" class="form-control" style="font-size:.85rem;"></td>
            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
        </tr>
    @endforelse
    </tbody>
</table>
<button type="button" onclick="addStRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add row</button>
<script>
var stRowIndex = {{ count(old('scope_traceability', $scopeTraceability)) ?: 1 }};
function addStRow() {
    var tbody = document.getElementById('st_tbody');
    var tr = document.createElement('tr');
    tr.className = 'st-row';
    tr.innerHTML = '<td><input type="text" name="scope_traceability['+stRowIndex+'][quote_item]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="scope_traceability['+stRowIndex+'][rams_activity]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="scope_traceability['+stRowIndex+'][room]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="scope_traceability['+stRowIndex+'][notes]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
    tbody.appendChild(tr); stRowIndex++;
}
</script>
```

---

**Section B — Client Responsibilities (Expanded)**
```blade
{{-- ── Client Responsibilities (Expanded) ─────────────────────────────── --}}
<h3 class="section-heading" style="margin-top:1rem;">Client Responsibilities (Expanded)</h3>
<p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Check items the client is required to provide. Add notes where relevant.</p>
@php
    $crItems = [
        'network_readiness' => 'Network / LAN readiness (active drops at device locations)',
        'licences'          => 'Software licences / subscriptions (Teams Rooms, Zoom, etc.)',
        'access'            => 'Site access and room availability on installation day(s)',
        'power_validation'  => 'Mains power validation (sockets live and tested)',
    ];
@endphp
@foreach($crItems as $crKey => $crLabel)
@php
    $crItem = $clientRespExp[$crKey] ?? [];
    $crReq  = old("client_resp_{$crKey}_required", $crItem['required'] ?? false);
    $crNote = old("client_resp_{$crKey}_notes",    $crItem['notes']    ?? '');
@endphp
<div style="display:flex; align-items:flex-start; gap:.75rem; margin-bottom:.5rem; border-bottom:1px solid #f0f0f0; padding-bottom:.5rem;">
    <label style="display:flex; align-items:center; gap:.4rem; min-width:320px; font-size:.875rem; cursor:pointer; padding-top:.15rem;">
        <input type="checkbox" name="client_resp_{{ $crKey }}_required" value="1" {{ $crReq ? 'checked' : '' }}>
        {{ $crLabel }}
    </label>
    <input type="text" name="client_resp_{{ $crKey }}_notes" class="form-control" style="font-size:.875rem; padding:.3rem .5rem;"
           placeholder="Notes (optional)" value="{{ $crNote }}">
</div>
@endforeach

<p style="font-size:.85rem; color:#666; margin-top:.5rem; margin-bottom:.35rem;">Additional client responsibilities:</p>
<table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
    <thead><tr><th>Item</th><th>Notes</th><th style="width:40px;"></th></tr></thead>
    <tbody id="cr_tbody">
    @php $crAdditional = old('client_resp_additional', $clientRespExp['additional'] ?? []); @endphp
    @forelse($crAdditional as $craIdx => $craRow)
        @php $craRow = is_array($craRow) ? $craRow : []; @endphp
        <tr>
            <td><input type="text" name="client_resp_additional[{{ $craIdx }}][item]" class="form-control" style="font-size:.85rem;" value="{{ $craRow['item'] ?? '' }}"></td>
            <td><input type="text" name="client_resp_additional[{{ $craIdx }}][notes]" class="form-control" style="font-size:.85rem;" value="{{ $craRow['notes'] ?? '' }}"></td>
            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
        </tr>
    @empty
        <tr>
            <td><input type="text" name="client_resp_additional[0][item]" class="form-control" style="font-size:.85rem;" placeholder="Additional item"></td>
            <td><input type="text" name="client_resp_additional[0][notes]" class="form-control" style="font-size:.85rem;"></td>
            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
        </tr>
    @endforelse
    </tbody>
</table>
<button type="button" onclick="addCrRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add item</button>
<script>
var crRowIndex = {{ count(old('client_resp_additional', $clientRespExp['additional'] ?? [])) ?: 1 }};
function addCrRow() {
    var tbody = document.getElementById('cr_tbody');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="client_resp_additional['+crRowIndex+'][item]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="client_resp_additional['+crRowIndex+'][notes]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
    tbody.appendChild(tr); crRowIndex++;
}
</script>
```

---

**Section C — Exclusions**
```blade
{{-- ── Exclusions ──────────────────────────────────────────────────────── --}}
<h3 class="section-heading" style="margin-top:1rem;">Exclusions</h3>
<p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Items explicitly excluded from scope. Remove or add as appropriate.</p>
<div id="excl_list">
@php $exclList = old('exclusions', $exclusionsList); @endphp
@forelse($exclList as $exIdx => $exItem)
<div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="excl-row">
    <input type="text" name="exclusions[{{ $exIdx }}]" class="form-control" style="font-size:.875rem;"
           value="{{ is_string($exItem) ? $exItem : '' }}">
    <button type="button" onclick="this.closest('.excl-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
</div>
@empty
<div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="excl-row">
    <input type="text" name="exclusions[0]" class="form-control" style="font-size:.875rem;" placeholder="Exclusion item">
    <button type="button" onclick="this.closest('.excl-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
</div>
@endforelse
</div>
<button type="button" onclick="addExclRow()" class="btn btn-outline btn-sm" style="font-size:.8rem; margin-top:.35rem;">+ Add exclusion</button>
<script>
var exclIndex = {{ count(old('exclusions', $exclusionsList)) ?: 1 }};
function addExclRow() {
    var container = document.getElementById('excl_list');
    var div = document.createElement('div');
    div.className = 'excl-row';
    div.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;';
    div.innerHTML = '<input type="text" name="exclusions['+exclIndex+']" class="form-control" style="font-size:.875rem;">'
        + '<button type="button" onclick="this.closest(\'.excl-row\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>';
    container.appendChild(div); exclIndex++;
}
</script>
```

---

**Section D — Decommissioning Procedure**
```blade
{{-- ── Decommissioning Procedure ───────────────────────────────────────── --}}
<h3 class="section-heading" style="margin-top:1rem;">Decommissioning Procedure</h3>
@php
    $decomEnabled  = old('decommissioning_enabled',             $decommData['enabled']                  ?? false);
    $decomLabel    = old('decommissioning_labelling_procedure', $decommData['labelling_procedure']       ?? '');
    $decomStorage  = old('decommissioning_storage_location',    $decommData['storage_location']          ?? '');
    $decomSignOff  = old('decommissioning_client_sign_off',     $decommData['client_sign_off_required']  ?? false);
    $decomDisposal = old('decommissioning_disposal_method',     $decommData['disposal_method']           ?? '');
    $decomSteps    = old('decommissioning_steps',               $decommData['steps']                     ?? []);
@endphp
<div class="form-group" style="margin-bottom:.5rem;">
    <label style="display:flex; align-items:center; gap:.5rem; font-size:.9rem; cursor:pointer;">
        <input type="checkbox" name="decommissioning_enabled" value="1" id="decomm_toggle"
               {{ $decomEnabled ? 'checked' : '' }}>
        This project includes decommissioning / removal of existing equipment
    </label>
</div>
<div id="decomm_section" style="{{ $decomEnabled ? '' : 'display:none;' }}">
    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label">Labelling Procedure</label>
            <input type="text" name="decommissioning_labelling_procedure" class="form-control"
                   style="font-size:.875rem;" value="{{ $decomLabel }}" placeholder="e.g. Label all cables before disconnection">
        </div>
        <div class="form-group">
            <label class="form-label">Storage Location</label>
            <input type="text" name="decommissioning_storage_location" class="form-control"
                   style="font-size:.875rem;" value="{{ $decomStorage }}" placeholder="e.g. Client stores in Plant Room B">
        </div>
        <div class="form-group">
            <label class="form-label">Disposal Method</label>
            <input type="text" name="decommissioning_disposal_method" class="form-control"
                   style="font-size:.875rem;" value="{{ $decomDisposal }}" placeholder="e.g. WEEE registered carrier, 21CAV to arrange">
        </div>
        <div class="form-group" style="display:flex; align-items:center; padding-top:1.5rem;">
            <label style="display:flex; align-items:center; gap:.5rem; font-size:.875rem; cursor:pointer;">
                <input type="checkbox" name="decommissioning_client_sign_off" value="1"
                       {{ $decomSignOff ? 'checked' : '' }}>
                Client sign-off required before removal
            </label>
        </div>
    </div>
    <label class="form-label" style="margin-top:.5rem;">Decommissioning Steps (ordered)</label>
    <div id="decomm_steps_list">
    @forelse($decomSteps as $dsIdx => $dsStep)
        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="decomm-step-row">
            <span style="font-size:.8rem; color:#888; min-width:22px;">{{ $dsIdx + 1 }}.</span>
            <input type="text" name="decommissioning_steps[{{ $dsIdx }}]" class="form-control"
                   style="font-size:.875rem;" value="{{ is_string($dsStep) ? $dsStep : '' }}">
            <button type="button" onclick="this.closest('.decomm-step-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
        </div>
    @empty
        <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.35rem;" class="decomm-step-row">
            <span style="font-size:.8rem; color:#888; min-width:22px;">1.</span>
            <input type="text" name="decommissioning_steps[0]" class="form-control"
                   style="font-size:.875rem;" placeholder="e.g. Power down and isolate existing equipment">
            <button type="button" onclick="this.closest('.decomm-step-row').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>
        </div>
    @endforelse
    </div>
    <button type="button" onclick="addDecommStep()" class="btn btn-outline btn-sm" style="font-size:.8rem; margin-top:.35rem;">+ Add step</button>
</div>
<script>
    document.getElementById('decomm_toggle').addEventListener('change', function() {
        document.getElementById('decomm_section').style.display = this.checked ? '' : 'none';
    });
    var decommStepIndex = {{ count($decomSteps) ?: 1 }};
    function addDecommStep() {
        var container = document.getElementById('decomm_steps_list');
        var rowNum = container.querySelectorAll('.decomm-step-row').length + 1;
        var div = document.createElement('div');
        div.className = 'decomm-step-row';
        div.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin-bottom:.35rem;';
        div.innerHTML = '<span style="font-size:.8rem;color:#888;min-width:22px;">'+rowNum+'.</span>'
            + '<input type="text" name="decommissioning_steps['+decommStepIndex+']" class="form-control" style="font-size:.875rem;">'
            + '<button type="button" onclick="this.closest(\'.decomm-step-row\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button>';
        container.appendChild(div); decommStepIndex++;
    }
</script>
```

---

**Section E — Commissioning Criteria**
```blade
{{-- ── Commissioning Criteria ──────────────────────────────────────────── --}}
<h3 class="section-heading" style="margin-top:1rem;">Commissioning Criteria</h3>
<p style="font-size:.85rem; color:#555; margin-bottom:.65rem;">Define pass criteria for each system or installation activity. Rendered as a sign-off table in the PDF.</p>
<table class="data-table" style="font-size:.85rem; margin-bottom:.5rem;">
    <thead>
        <tr>
            <th style="width:18%;">System</th>
            <th>Criterion</th>
            <th style="width:22%;">Verification Method</th>
            <th style="width:20%;">Pass Condition</th>
            <th style="width:40px;"></th>
        </tr>
    </thead>
    <tbody id="cc_tbody">
    @php $ccList = old('commissioning_criteria', $commCriteria); @endphp
    @forelse($ccList as $ccIdx => $ccRow)
        @php $ccRow = is_array($ccRow) ? $ccRow : []; @endphp
        <tr class="cc-row">
            <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][system]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['system'] ?? '' }}"></td>
            <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][criterion]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['criterion'] ?? '' }}"></td>
            <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][verification_method]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['verification_method'] ?? '' }}"></td>
            <td><input type="text" name="commissioning_criteria[{{ $ccIdx }}][pass_condition]" class="form-control" style="font-size:.85rem;" value="{{ $ccRow['pass_condition'] ?? '' }}"></td>
            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
        </tr>
    @empty
        <tr class="cc-row">
            <td><input type="text" name="commissioning_criteria[0][system]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Display"></td>
            <td><input type="text" name="commissioning_criteria[0][criterion]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Image displayed on all inputs"></td>
            <td><input type="text" name="commissioning_criteria[0][verification_method]" class="form-control" style="font-size:.85rem;" placeholder="e.g. Test each source"></td>
            <td><input type="text" name="commissioning_criteria[0][pass_condition]" class="form-control" style="font-size:.85rem;" placeholder="e.g. No artefacts, full screen"></td>
            <td><button type="button" onclick="this.closest('tr').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>
        </tr>
    @endforelse
    </tbody>
</table>
<button type="button" onclick="addCcRow()" class="btn btn-outline btn-sm" style="font-size:.8rem;">+ Add criterion</button>
<script>
var ccRowIndex = {{ count(old('commissioning_criteria', $commCriteria)) ?: 1 }};
function addCcRow() {
    var tbody = document.getElementById('cc_tbody');
    var tr = document.createElement('tr');
    tr.className = 'cc-row';
    tr.innerHTML = '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][system]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][criterion]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][verification_method]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><input type="text" name="commissioning_criteria['+ccRowIndex+'][pass_condition]" class="form-control" style="font-size:.85rem;"></td>'
        + '<td><button type="button" onclick="this.closest(\'tr\').remove()" style="color:#c00;background:none;border:none;cursor:pointer;font-size:1rem;">&#x2715;</button></td>';
    tbody.appendChild(tr); ccRowIndex++;
}
</script>
```
  </action>
  <verify>
php artisan view:clear
Visit /rams/{id}/review — five new sections visible in form, no Blade compile errors in storage/logs/laravel.log
  </verify>
  <done>All five sections appear in the review form inside the main POST form, with pre-filled data where applicable, add/remove row JS working, and no function calls directly inside @foreach directives.</done>
</task>

<task type="auto">
  <name>Task 3: PDF template — five new rendered sections</name>
  <files>resources/views/pdf/rams.blade.php</files>
  <action>
Two changes to the PDF template:

**Change 1 — @php variable block (around line 311–438):** Add new variable reads after the existing `$cdmRows` and `$welfareNotes` lines:

```php
// New sections from reviewed_data
$scopeTraceability  = $rams->reviewed_data['scope_traceability']              ?? [];
$clientRespExp      = $rams->reviewed_data['client_responsibilities_expanded'] ?? [];
$exclusionsList     = $rams->reviewed_data['exclusions']                       ?? [];
$decommData         = $rams->reviewed_data['decommissioning']                  ?? [];
$commCriteria       = $rams->reviewed_data['commissioning_criteria']           ?? [];

$scopeTraceability  = is_array($scopeTraceability)  ? $scopeTraceability  : [];
$exclusionsList     = is_array($exclusionsList)      ? $exclusionsList     : [];
$commCriteria       = is_array($commCriteria)        ? $commCriteria       : [];

// Decommissioning enabled when flag set OR scope has decommission items
$decommEnabled = ! empty($decommData['enabled']) || $hasDecomm;
```

**Change 2 — Five section insertions in the body:**

**A — Scope Traceability:** Insert AFTER the equipment schedule table (after `</table>` ending the equipment schedule, before Section 5 Risk Assessment). Use a `page-break` class like other sections.

```blade
{{-- ════════════════════════════════════════════════════════════════════════
     SCOPE TRACEABILITY
     ════════════════════════════════════════════════════════════════════════ --}}
@if(! empty($scopeTraceability))
<div class="sec-heading">Scope Traceability</div>
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr>
            <th style="width:26%;">Quote Ref / Item Description</th>
            <th style="width:28%;">RAMS Activity</th>
            <th style="width:18%;">Room / Area</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
    @foreach($scopeTraceability as $stRow)
    @php $stRow = is_array($stRow) ? $stRow : []; @endphp
    <tr>
        <td>{{ $stRow['quote_item']    ?? '' }}</td>
        <td>{{ $stRow['rams_activity'] ?? '' }}</td>
        <td>{{ $stRow['room']          ?? '' }}</td>
        <td>{{ $stRow['notes']         ?? '' }}</td>
    </tr>
    @endforeach
    </tbody>
</table>
@endif
```

**B — Exclusions:** Insert AFTER the Scope Traceability block (or immediately after the equipment schedule if scope traceability empty), still before Section 5:

```blade
{{-- ════════════════════════════════════════════════════════════════════════
     EXCLUSIONS
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="sec-heading">Exclusions</div>
@if(! empty($exclusionsList))
<ul class="blist">
@foreach($exclusionsList as $exItem)
    @if(trim((string)$exItem) !== '')
    <li>{{ $exItem }}</li>
    @endif
@endforeach
</ul>
@else
<ul class="blist">
    <li>No structural works.</li>
    <li>No core drilling unless explicitly scoped.</li>
    <li>No containment beyond surface trunking.</li>
    <li>No decorative making good after cable routes.</li>
    <li>No IT network provision unless scoped.</li>
</ul>
@endif
```

**C — Expanded Client Responsibilities:** Replace (supplement) the existing 6.3 block. The existing 6.3 block renders `$clientResp` (from generated_data). Keep that existing rendering but append the expanded section beneath it. Find the closing `@endif` of the 6.3 block (around line 959) and insert after it:

```blade
{{-- 6.3 expanded: structured client responsibilities --}}
@php
    $crExpLabels = [
        'network_readiness' => 'Network / LAN readiness (active drops at device locations)',
        'licences'          => 'Software licences / subscriptions (Teams Rooms, Zoom, etc.)',
        'access'            => 'Site access and room availability on installation day(s)',
        'power_validation'  => 'Mains power validation (sockets live and tested)',
    ];
    $crExpChecked = array_filter(
        is_array($clientRespExp) ? $clientRespExp : [],
        fn ($v, $k) => is_array($v) && ! empty($v['required']) && $k !== 'additional',
        ARRAY_FILTER_USE_BOTH
    );
    $crExpAdditional = is_array($clientRespExp['additional'] ?? null) ? $clientRespExp['additional'] : [];
@endphp
@if(! empty($crExpChecked) || ! empty($crExpAdditional))
<ul class="blist" style="margin-top:4pt;">
@foreach($crExpChecked as $crk => $crv)
    <li><strong>{{ $crExpLabels[$crk] ?? $crk }}:</strong>{{ ! empty($crv['notes']) ? ' ' . $crv['notes'] : ' Required prior to works commencing.' }}</li>
@endforeach
@foreach($crExpAdditional as $cra)
    @php $cra = is_array($cra) ? $cra : []; @endphp
    @if(! empty($cra['item']))
    <li><strong>{{ $cra['item'] }}:</strong>{{ ! empty($cra['notes']) ? ' ' . $cra['notes'] : '' }}</li>
    @endif
@endforeach
</ul>
@endif
```

**D — Decommissioning Procedure:** Insert after the Material Handling section (after line ~1012, before the Permits & Authorisations section):

```blade
{{-- ════════════════════════════════════════════════════════════════════════
     DECOMMISSIONING PROCEDURE
     ════════════════════════════════════════════════════════════════════════ --}}
@if($decommEnabled)
<div class="sec-heading">Decommissioning Procedure</div>
@php
    $decomLabel    = $decommData['labelling_procedure']    ?? '';
    $decomStorage  = $decommData['storage_location']       ?? '';
    $decomDisposal = $decommData['disposal_method']        ?? '';
    $decomSignOff  = ! empty($decommData['client_sign_off_required']);
    $decomStepsPdf = is_array($decommData['steps'] ?? null) ? $decommData['steps'] : [];
@endphp
<div class="kv-block">
    @if($decomLabel)    <p><strong>Labelling Procedure:</strong> {{ $decomLabel }}</p>@endif
    @if($decomStorage)  <p><strong>Storage Location:</strong> {{ $decomStorage }}</p>@endif
    @if($decomDisposal) <p><strong>Disposal Method:</strong> {{ $decomDisposal }}</p>@endif
    <p><strong>Client Sign-Off Required:</strong> {{ $decomSignOff ? 'Yes — client must sign before removal of any equipment' : 'No' }}</p>
</div>
@if(! empty($decomStepsPdf))
<ol style="margin: 0 0 8pt 18pt; font-size:9.5pt; line-height:1.5;">
@foreach($decomStepsPdf as $dStep)
    @if(trim((string)$dStep) !== '')
    <li>{{ $dStep }}</li>
    @endif
@endforeach
</ol>
@endif
@endif
```

**E — Commissioning Criteria:** Insert BEFORE Section 8 Document Sign-Off (before `<div class="sec-heading page-break">8.`):

```blade
{{-- ════════════════════════════════════════════════════════════════════════
     COMMISSIONING CRITERIA
     ════════════════════════════════════════════════════════════════════════ --}}
@if(! empty($commCriteria))
<div class="sec-heading page-break">Commissioning Criteria</div>
<p class="body-para">The following criteria must be verified and signed off before the installation is considered complete and handed over to the client.</p>
<table class="std-table" style="margin-bottom: 8pt;">
    <thead>
        <tr style="background-color:#1B7A7A; color:#ffffff;">
            <th style="width:18%; color:#fff;">System</th>
            <th style="color:#fff;">Criterion</th>
            <th style="width:22%; color:#fff;">Verification Method</th>
            <th style="width:20%; color:#fff;">Pass Condition</th>
            <th style="width:60pt; color:#fff; text-align:center;">Result</th>
        </tr>
    </thead>
    <tbody>
    @foreach($commCriteria as $ccRow)
    @php $ccRow = is_array($ccRow) ? $ccRow : []; @endphp
    <tr>
        <td><strong>{{ $ccRow['system'] ?? '' }}</strong></td>
        <td>{{ $ccRow['criterion'] ?? '' }}</td>
        <td>{{ $ccRow['verification_method'] ?? '' }}</td>
        <td>{{ $ccRow['pass_condition'] ?? '' }}</td>
        <td style="text-align:center; font-size:8.5pt;">Pass &#9744;&nbsp; Fail &#9744;</td>
    </tr>
    @endforeach
    </tbody>
</table>
@endif
```

Verify insertion points by searching for exact surrounding comment blocks in the file before inserting. Do NOT rewrite sections that are not being changed.
  </action>
  <verify>
php artisan view:clear
curl -s http://localhost/rams/{valid-id}/download-pdf -b "laravel_session=..." | file - (should return PDF data)
Or: visit /rams/{id}/download-pdf in browser — PDF downloads without 500 error and new sections visible
  </verify>
  <done>PDF downloads without error. Scope Traceability table appears after equipment schedule. Exclusions section appears before Section 5. Expanded client responsibilities appear beneath existing 6.3 bullet list. Decommissioning section renders only when enabled. Commissioning Criteria table with Pass/Fail checkboxes appears before Section 8 Sign-Off.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| form POST → controller | Reviewer submits new array fields; all must be validated before persisting to reviewed_data JSON |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-jli-01 | Tampering | scope_traceability / commissioning_criteria array inputs | mitigate | Laravel array validation rules with nullable string + max:500 per cell; array_filter removes empty rows before persist |
| T-jli-02 | Information Disclosure | exclusions / decommissioning data in reviewed_data | accept | Already behind auth middleware + RamsDocumentPolicy; no new exposure surface |
| T-jli-03 | Denial of Service | large array submissions (many rows) | accept | Max:500 per string field limits payload; no per-row count limit needed at this scale |
</threat_model>

<verification>
After all three tasks:
1. `php artisan view:clear && php artisan config:clear` — no errors
2. Visit /rams/{id}/review — all five new sections visible in the form with no Blade errors
3. Fill one row in scope traceability, check a client responsibility, remove one exclusion, enable decommissioning with one step, add one commissioning criterion
4. Click "Save & Download .docx" — DOCX downloads, no 500 error
5. Visit /rams/{id}/review again — all entered values persist (read back from reviewed_data)
6. Click "Download PDF" — PDF renders without error; spot-check: Scope Traceability table visible, Exclusions section present, Commissioning Criteria table before Sign-Off
</verification>

<success_criteria>
- All five reviewed_data sub-keys (scope_traceability, client_responsibilities_expanded, exclusions, decommissioning, commissioning_criteria) saved and re-loaded correctly
- scope_traceability pre-fills from generated_data['quote']['line_items'] when empty
- exclusions pre-fills with five default strings when reviewed_data has no exclusions key
- PDF renders without 500 errors and includes all five new sections in correct positions
- No Blade function-call-in-@foreach violations introduced
- Existing RAMS fields (programme, permits, CDM, material handling) unaffected
</success_criteria>

<output>
After completion, create `.planning/quick/260414-jli-add-scope-traceability-client-responsibi/260414-jli-SUMMARY.md`
</output>
