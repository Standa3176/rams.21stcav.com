{{-- _resync-diff.blade.php (Phase 16 Plan 05 Task 2 — D-04)

     Modal-style diff summary rendered inside the commissioningPage Alpine
     factory's `resync` state. Opens when the engineer taps "Re-sync from
     programme"; fires the POST commissioning.resync request; shows the
     returned counters (added / removed / unchanged / restored); lets the
     engineer reload the checklist or close.

     Reload is only offered when the diff is non-trivial (added + removed +
     restored > 0). When nothing changed we show "Already in sync" and a
     Close-only action — no point reloading when there's nothing new to see.

     Ownership + signoff guarding is in CommissioningResyncController; the
     Re-sync button in show.blade.php is also hidden when $signoff exists
     (INST-05i + T-16-01 defence-in-depth).
--}}
<div x-show="resync.open"
     x-cloak
     @keydown.escape.window="resync.open = false"
     class="resync-diff">

    <div @click="resync.open = false" class="resync-diff__backdrop"></div>

    <div class="resync-diff__panel">
        <h3 class="resync-diff__title">Re-sync from programme</h3>

        <template x-if="resync.loading">
            <p class="resync-diff__hint">Computing diff…</p>
        </template>

        <template x-if="! resync.loading && resync.error">
            <p class="resync-diff__error" x-text="resync.error"></p>
        </template>

        <template x-if="! resync.loading && ! resync.error && resync.counters">
            <div class="resync-diff__body">
                <div class="resync-diff__grid">
                    <div class="resync-diff__row">
                        <span class="resync-diff__label">Unchanged</span>
                        <span class="resync-diff__value" x-text="resync.counters.unchanged"></span>
                    </div>
                    <div class="resync-diff__row resync-diff__row--added">
                        <span class="resync-diff__label">Added</span>
                        <span class="resync-diff__value" x-text="resync.counters.added"></span>
                    </div>
                    <div class="resync-diff__row resync-diff__row--removed">
                        <span class="resync-diff__label">Removed</span>
                        <span class="resync-diff__value" x-text="resync.counters.removed"></span>
                    </div>
                    <div class="resync-diff__row resync-diff__row--restored">
                        <span class="resync-diff__label">Restored</span>
                        <span class="resync-diff__value" x-text="resync.counters.restored"></span>
                    </div>
                </div>
                <p x-show="resync.counters.added + resync.counters.removed + resync.counters.restored === 0"
                   class="resync-diff__already-synced">Already in sync.</p>
            </div>
        </template>

        <div class="resync-diff__actions">
            <button type="button" @click="resync.open = false" class="btn btn-outline">Close</button>
            <button type="button"
                    @click="window.location.reload()"
                    x-show="! resync.loading && resync.counters && (resync.counters.added + resync.counters.removed + resync.counters.restored > 0)"
                    class="btn btn-primary">Reload checklist</button>
        </div>
    </div>
</div>

@once
<style>
    /* Re-sync diff modal — lightweight centred card, not a bottom-sheet. */
    .resync-diff                 { position: fixed; inset: 0; z-index: 40; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .resync-diff__backdrop       { position: absolute; inset: 0; background: rgba(0,0,0,.4); }
    .resync-diff__panel          { position: relative; width: 100%; max-width: 26rem; background: #fff; border-radius: 10px; box-shadow: var(--shadow-md); padding: 1.25rem; display: flex; flex-direction: column; gap: .9rem; }
    .resync-diff__title          { font-weight: 600; font-size: 1.05rem; }
    .resync-diff__hint           { font-size: .85rem; color: var(--text-muted); }
    .resync-diff__error          { font-size: .85rem; color: var(--danger); margin: 0; }
    .resync-diff__body           { display: flex; flex-direction: column; gap: .5rem; }
    .resync-diff__grid           { display: grid; grid-template-columns: repeat(2, 1fr); gap: .5rem .75rem; }
    .resync-diff__row            { display: flex; justify-content: space-between; font-size: .875rem; padding: .35rem .5rem; border-radius: 6px; background: #F8FAFC; }
    .resync-diff__row--added     { background: #F0FDF4; color: #15803D; }
    .resync-diff__row--removed   { background: #FEF2F2; color: #B91C1C; }
    .resync-diff__row--restored  { background: #EFF6FF; color: #1D4ED8; }
    .resync-diff__label          { color: var(--text-muted); }
    .resync-diff__row--added    .resync-diff__label,
    .resync-diff__row--removed  .resync-diff__label,
    .resync-diff__row--restored .resync-diff__label { color: inherit; }
    .resync-diff__value          { font-weight: 600; }
    .resync-diff__already-synced { font-size: .75rem; color: var(--text-faint); font-style: italic; padding-top: .25rem; }
    .resync-diff__actions        { display: flex; justify-content: flex-end; gap: .5rem; padding-top: .25rem; }
</style>
@endonce
