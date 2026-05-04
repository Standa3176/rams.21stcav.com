@props([
    'formId'      => null,
    'cancelUrl'   => null,
    'saveLabel'   => 'Save Changes',
    'cancelLabel' => 'Cancel',
])

{{--
    Sticky Save/Cancel bar. Sits flush below the fixed .app-header on every
    editable form page.

    The Save button uses the HTML5 `form="..."` attribute so it can submit a
    form by ID even though it lives OUTSIDE the form element. No JavaScript
    needed. If $formId is omitted, no Save button renders (defensive).

    The optional named slot {{ $title }} renders inside .edit-action-bar__title
    and is hidden on screens narrower than 768px to save room for the buttons.
--}}
<div class="edit-action-bar" role="region" aria-label="Edit actions">
    @isset($title)
        <div class="edit-action-bar__title">{{ $title }}</div>
    @endisset
    <div class="edit-action-bar__actions">
        <a href="{{ $cancelUrl ?? url()->previous() }}" class="btn btn-outline btn-sm">{{ $cancelLabel }}</a>
        @if($formId)
            <button type="submit" form="{{ $formId }}" class="btn btn-teal btn-sm">{{ $saveLabel }}</button>
        @endif
    </div>
</div>
