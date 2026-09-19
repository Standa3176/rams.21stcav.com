{{--
    Phase 44 Plan 03 Task 1 — PM-facing multi-select of active labour resources.

    "Client should only be given engineer name never phone or email — this is
    for PM only." — the user, verbatim, 2026-09-19 (44-CONTEXT.md D-04/D-05).

    Option label is `$resource->name` ONLY. Never interpolate email/phone
    into this template — the model's `active()` scope + this label rule are
    the two structural guarantees T-44-07/T-44-08 depend on.
--}}
<select multiple name="{{ $name }}[]" id="{{ $name }}">
    @foreach ($resources as $resource)
        <option
            value="{{ $resource->id }}"
            data-roles="{{ implode(',', $resource->roles ?? []) }}"
            @selected(in_array($resource->id, $selected))
        >{{ $resource->name }}</option>
    @endforeach
</select>
