# Phase 16 DPI Integration Spike Notes

**Investigated:** 2026-04-22
**File:** `public/vendor/sign-pad/sign-pad.min.js` (25,074 bytes)
**creagia version:** 3.0.0 (`composer show creagia/laravel-sign-pad`)
**Bundled signature_pad version:** v4.0.0 (szimek/signature_pad, per license comment)

## Finding

Chosen integration option: **C** (CDN fallback).

The creagia-published bundle is a webpack IIFE — the embedded `SignaturePad`
class is closure-scoped inside `(()=>{"use strict" …})()` and is never
assigned to `window`. There is also no `__signaturePad` data property attached
to canvases; the bundle instantiates a `SignaturePad` inside its own `.forEach`
loop over `.e-signpad` containers and keeps the reference in a local
`signaturePad` variable. Plan 05's Alpine factory cannot read either of those
expressions at `initCanvas` time.

Therefore: Plan 05 will load `szimek/signature_pad@5.1.3` UMD from CDN in
parallel to creagia's bundle, giving us a guaranteed `window.SignaturePad`
global for `new window.SignaturePad(canvas)` wiring, while still keeping
creagia's server-side storage + Blade component machinery.

## Evidence

### Grep results

```
$ grep -o 'window\.SignaturePad' public/vendor/sign-pad/sign-pad.min.js | head -3
(no matches)

$ grep -o '__signaturePad' public/vendor/sign-pad/sign-pad.min.js | head -3
(no matches)

$ grep -oE '(window\.SignaturePad|SignaturePad)' public/vendor/sign-pad/sign-pad.min.js | sort -u
SignaturePad
```

The sole `SignaturePad` token is the class identifier in the closure — never
a property access on `window`.

### Bundle entry-point snippet (tail of the file, de-minified)

```js
// ./resources/assets/sign-pad.js (entry point inside webpack IIFE)
const eSignpads = document.querySelectorAll('.e-signpad');

eSignpads.forEach(function (eSignpad) {
    let signaturePad = new signature_pad__WEBPACK_IMPORTED_MODULE_0__["default"](
        eSignpad.querySelector('canvas')
    );
    // ... wires buttons .sign-pad-button-submit / .sign-pad-button-clear
});

// Plus a DOMContentLoaded-bound resizeCanvas() that rebuilds the
// signaturePad internally when canvas.width > window.innerWidth.
```

Key observation: the `signaturePad` variable is declared with `let` inside a
`forEach` callback. Its lifetime ends when the callback returns; there is no
stashing onto the DOM, no module-level export, and no global assignment. This
is why Option A (`window.SignaturePad`) and Option B (`canvas.__signaturePad`)
both fail — there is nothing to read.

The bundled resize handler also recreates the instance locally, so any DPI
customisation Plan 05 needs (Retina iPad ratio scaling before canvas writes)
must run against our own instance, not creagia's.

## Implication for Plan 05

Plan 05 Task 1 will:

1. Load `signature_pad@5.1.3` UMD from CDN in `layouts/app.blade.php`
   **in addition to** the creagia bundle (next to it, one line above
   `@stack('scripts')`).
2. The DPI `resizeCanvas` snippet will use:

```javascript
// Option C wiring (CDN UMD exposes window.SignaturePad)
this.signaturePad = new window.SignaturePad(canvas, {
    backgroundColor: 'rgba(255,255,255,0)',
    penColor: 'black',
});
```

3. Plan 05 Alpine component will emit the base64 via
   `this.signaturePad.toDataURL('image/png')` on the submit handler and
   `this.signaturePad.isEmpty()` for the empty-guard.

## Step 1d layout load

The creagia bundle remains loaded globally per the plan's Step 1d — it still
drives server-side signed-document storage through the `SignatureDocumentTemplate`
abstraction even though we bypass its JS DPI path. The CDN UMD is additive.

## Fallback of the fallback

If the CDN is unreachable (corporate firewall, air-gapped install), Plan 05
should copy `signature_pad.umd.min.js` into `public/vendor/signature-pad/`
and serve it via `asset()`. This is tracked as a Plan 05 risk, not here.

## Package assets audit (T-16-PKG-02 disposition)

`php artisan vendor:publish --provider="Creagia\LaravelSignPad\LaravelSignPadServiceProvider" --force`
published:

- `config/sign-pad.php`
- `public/vendor/sign-pad/sign-pad.min.js` + `.LICENSE.txt`
- `app/View/Components/vendor/sign-pad/` (Blade component)
- `resources/views/vendor/laravel-sign-pad/`
- `database/migrations/2026_04_22_095035_create_signatures_table.php`

The `signatures` table migration is retained unmodified per T-16-PKG-02
disposition (accept): Phase 16 does not insert into it, so it is a zero-row
dormant table. Removing the migration would put us in a fork-maintenance trap
relative to future creagia versions. No mitigation required beyond "do not
expose the package's bundled web routes" — we only use the Blade component
and the JS, not the `signature.show` controller.
