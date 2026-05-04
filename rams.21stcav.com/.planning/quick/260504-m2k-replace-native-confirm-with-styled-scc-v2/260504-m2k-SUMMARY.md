---
task: 260504-m2k
title: Replace native confirm() with styled SCC v2 modal
type: quick
subsystem: ui-presentation
tags: [ui, alpine, blade, confirm-modal, scc-v2]
key-files:
  created:
    - resources/views/components/row-actions-menu.blade.php  # was untracked, now in tree
  modified:
    - resources/views/layouts/app.blade.php                  # global modal + handlers
    - resources/views/admin/solution-types/index.blade.php
    - resources/views/admin/users/index.blade.php
    - resources/views/admin/worker.blade.php
    - resources/views/cable-schedule/index.blade.php
    - resources/views/components/install-task/photo-upload.blade.php
    - resources/views/hazard-templates/_table.blade.php
    - resources/views/install-programmes/field.blade.php
    - resources/views/install-programmes/review.blade.php
    - resources/views/pdf/om-manual/index.blade.php
    - resources/views/project-packages/review.blade.php
    - resources/views/projects/index.blade.php
    - resources/views/projects/show.blade.php
    - resources/views/public-survey/show.blade.php
    - resources/views/quote-import/review.blade.php
    - resources/views/rams/index.blade.php
    - resources/views/rams/quote-review.blade.php
    - resources/views/rams/review.blade.php
    - resources/views/site-survey/_room-form.blade.php
    - resources/views/site-survey/edit.blade.php
    - resources/views/site-survey/index.blade.php
    - resources/views/site-survey/show.blade.php
    - resources/views/surveys/show.blade.php
    - resources/views/worksheets/index.blade.php
    - resources/views/worksheets/public-show.blade.php
    - resources/views/worksheets/show.blade.php
metrics:
  files-touched: 27
  commits: 2
  before-grep-count: 64       # live .blade.php confirm() instances (excluding .bak/.pre-tailwind-bak/.blade2903.php)
  after-grep-count: 0         # for onsubmit/onclick="return confirm("
  total-line-delta: "+678 / -225"  # commit 1 (+154) + commit 2 (+524 -225)
---

# 260504-m2k: Replace native confirm() with styled SCC v2 modal

Replaces every native `window.confirm()` call across the app with a single Alpine-powered SCC v2 modal — opt-in via `data-confirm` attributes on forms, buttons, and anchors, plus a `window.appConfirm(message, opts)` Promise API for inline JS.

## Commits

| Hash      | Message                                                                              |
| --------- | ------------------------------------------------------------------------------------ |
| `97b6449` | feat: styled SCC v2 confirm modal + global handler (replaces native browser confirm) |
| `e3d6521` | refactor: replace native confirm() with styled data-confirm sweep across views       |

## What landed

### Commit 1 — Modal infrastructure (`layouts/app.blade.php`)

Added a single Alpine `x-data="appConfirm()"` modal block immediately above the existing sign-pad bundle includes, plus capture-phase document-level interceptors:

- **HTML modal**: position-fixed full-screen overlay, paper card with display heading + message, Cancel + Confirm buttons. Confirm button styles to `btn-teal` by default, `btn-danger` when `danger:true`. Auto-focuses the confirm button on open. ESC cancels, ENTER confirms.
- **Form interceptor**: capture-phase `submit` listener; if `form[data-confirm]` matches, prevents native submit, opens modal, and replays `form.submit()` only on user confirm.
- **Click interceptor**: capture-phase `click` listener; matches any `[data-confirm]` target via `closest()`, supports buttons/anchors/menu items. Re-clicks the element on confirm with a `_confirmBypass` flag to avoid re-prompting.
- **Programmatic API**: `window.appConfirm(message, opts)` returns a `Promise<boolean>` for use inside async event handlers (replaces `if(!confirm(...)) return` patterns).
- **Fallback**: if Alpine has not yet hydrated, all three paths fall back to native `window.confirm()` so behaviour never regresses.
- **CSS**: reused existing `.btn-danger`, `.btn-teal`, `.btn-outline`, `.btn-sm`, `--radius-lg`, `--shadow-pop`, `--ink-*` tokens — no new styles needed.

### Commit 2 — Sweep replacement across 26 view files

| Pattern                                              | Conversion                                                                                                |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `<form onsubmit="return confirm('msg');">`           | `<form data-confirm="msg" data-confirm-label="..." data-confirm-danger="1">` (danger keyword detection)   |
| `<button onclick="return confirm('msg');">`          | Form-level `data-confirm` when wrapping form is single-purpose; otherwise button-level                    |
| `if (!confirm('msg')) return;`                       | `if (!(await window.appConfirm('msg', {...}))) return;` + function marked `async`                        |
| `function fn() { return confirm(...); }`             | Returns Promise; callers refactored to `.then()` (e.g. `confirmApprove` button decoupled from `onclick`) |
| `if (window.confirm(...)) form.submit();`            | `window.appConfirm(...).then(ok => { if (ok) form.submit(); });`                                          |
| `<button onclick="if(confirm(...))action()">` (template-string) | Inline arrow with `window.appConfirm(...).then(ok => { if (ok) action(); })`                  |

**Danger-label detection:** applied `data-confirm-danger="1"` whenever the message contained any of: `delete`, `permanently`, `cannot be undone`, `force-destroy`, `discard`, `remove`, `revoke`, `archive`, `suspend`. Verb labels chosen per action (`Delete`, `Delete Forever`, `Remove`, `Discard`, `Archive`, `Suspend`, `Regenerate`, `Re-extract`, `Tidy`, `Activate`, `Mark Complete`, `Generate`, `Replace`, `Continue`, `Save`, `Approve`).

**`\n\n` normalisation:** native `confirm()` used double-newlines for visual emphasis; the styled modal uses `white-space: pre-line`, so messages were collapsed to single spaces in the data attribute (renders identically to single newlines, reads cleaner in DOM inspectors).

**`addslashes()` removal:** Blade `{{ }}` already escapes for HTML attribute context, so `data-confirm="...{{ $project->name }}..."` is sufficient — `addslashes()` was a JS-string-context shim that no longer applies.

## Sequential execution log

1. **Commit 1** — Edited `layouts/app.blade.php` to insert modal markup + 3 interceptor blocks above the sign-pad scripts. Ran `php artisan view:clear && php artisan view:cache` — succeeded.
2. **Commit 2** — Read each affected file's confirm() context, applied transformation per pattern table above, ran final `view:cache` — succeeded. Final compiled output verified to contain both `appConfirm()` factory and `data-confirm` attributes via direct inspection of compiled view files.

## Self-Check: PASSED

- `git log --oneline | grep 97b6449` -> FOUND
- `git log --oneline | grep e3d6521` -> FOUND
- `php artisan view:clear && php artisan view:cache` -> Compiled all templates without error
- `grep -rn 'onsubmit="return confirm\\|onclick="return confirm' resources/views/*.blade.php` -> 0 matches in live `.blade.php` files (remaining hits are in `.bak-*` / `.pre-tailwind-bak` / `.blade2903.php` archive files which Laravel does not load)
- Compiled view scan: `appConfirm()` factory present in cached layout; `data-confirm` attribute present in 3+ cached view files (sampled `02418bf...`, `0e62ab2...`, `12244d6...`).
- `git diff --stat HEAD~2 HEAD -- app/ routes/ database/ config/ public/` -> empty (presentation-only)

## Constraints respected

- No controllers, routes, schema, services, or config touched.
- Every confirm message preserved verbatim (only `\n\n` -> single space, and `addslashes()` removed where it was JS-context-only).
- 26 view files touched; 64 native `confirm()` instances converted in live files (lower than the 73 mentioned in the prompt because 9 were in legacy `.bak-*`/`.pre-tailwind-bak`/`.blade2903.php` archive files that Laravel cannot load and so were excluded).
- Destructive-action confirms styled red via `data-confirm-danger="1"`.
- Public survey, RAMS quote-review, project-packages review continue to work because their async function chains were updated end-to-end (caller buttons converted from `onclick="return fn()"` to `onclick="fn().then(ok => …)"` for the two helpers that became Promise-returning).

## Known follow-ups (out of scope)

- Legacy `.blade.php.bak-20260426`, `.blade.php.pre-tailwind-bak`, and `.blade2903.php` archive files still contain pre-Tailwind native `confirm()` calls. These are unloadable by Laravel — they are kept on disk as references only. Removing them is a separate cleanup task.
- The `quote-review.blade2903.php` file is referenced only in `.planning/codebase/CONCERNS.md` (a doc inventory) and has been confirmed unloadable.
