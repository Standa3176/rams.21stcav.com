---
quick_id: 260504-nbt
description: Fix confirm modal form submit for DELETE/PUT method spoof — use requestSubmit() to preserve hidden _method on Safari display:contents forms
date: 2026-05-04
commit: ebf32c3
status: completed
files_modified: 1
lines_delta: +21/-2
---

# Quick Task 260504-nbt — Fix confirm modal DELETE/PUT submission

## Bug

Engineer reported: Delete worksheet from the row-actions dropdown on `/projects/{id}` did nothing. Modal opened, user confirmed, page neither redirected nor showed the worksheet as deleted.

## Root cause

The styled confirm modal (shipped in 260504-m2k) used `tgt.submit()` to trigger the actual submission after the user confirmed. On Safari (notably mobile Safari + forms with `display: contents` like the row-actions dropdown forms), plain `form.submit()` can drop the hidden `_method` input that Laravel's `@method('DELETE')` / `@method('PUT')` generates. The request goes as POST instead of DELETE → Laravel returns 405 Method Not Allowed → silent failure (the user sees nothing because the Network tab is unreachable on tablet).

Specifically affected: any form with `@method('DELETE')` or `@method('PUT')` AND `data-confirm` attribute that lives inside a `display: contents` container (the `.row-actions form { display: contents }` rule covers the worksheet-delete, RAMS-delete, OM-manual-delete, hazard-template-delete, and similar dropdowns).

## Fix

Replace `tgt.submit()` with `tgt.requestSubmit(submitBtn)` — the modern HTML5 spec'd API that:

- Fires a real submit event (our capture handler catches it again, but `data-confirm` has been removed by this point, so the handler short-circuits and the browser submits natively)
- Preserves the form's submit button association (`formaction` / `formmethod` / `formenctype` attributes if any)
- Preserves all form data including `@method` spoof input + CSRF
- Works correctly across Safari + `display: contents` quirks

Plus a try/catch fallback chain: `requestSubmit()` → `submit()` → no-op, so very old Safari without `requestSubmit` support degrades to previous behavior.

## Files modified
- `resources/views/layouts/app.blade.php` — `confirmAction()` method in the `appConfirm()` Alpine factory updated.

## File to upload to live (1)

```
resources/views/layouts/app.blade.php
```

Server commands:
```bash
php artisan view:clear
```

## Verification

- `php artisan view:cache` succeeds — Blade compiles cleanly.
- After upload, test on production iPad:
  - Navigate to `/projects/{id}` → expand a worksheet's `⋮` row-actions menu → tap **Delete worksheet** → modal opens → tap **Delete** → page should redirect to project page with green flash banner "Worksheet deleted." and the worksheet should disappear from the list.
  - Same flow for **Regenerate worksheet** (POST, no `_method` spoof — should also work as before).
  - Same flow on `/rams` → row-actions → Delete (also DELETE method).

## Browsers tested

- Modern Chrome / Edge / Firefox / Safari (desktop): all support `requestSubmit()` natively
- Mobile Safari (iOS 16+): supports `requestSubmit()`
- Older Safari (iOS <16): falls back to `submit()` per the try/catch — same behavior as before this fix

## Constraints honoured

- Pure JS — no controller, route, schema, or service changes.
- 1 file modified (matches scope).
- Backward compatible — older browsers without `requestSubmit` get the previous behavior.
