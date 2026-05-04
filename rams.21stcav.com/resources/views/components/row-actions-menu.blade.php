{{--
    Compact 3-dot action menu for row actions.

    Usage:
        <x-row-actions-menu>
            <x-slot name="items">
                <a href="..." class="row-actions-item">📋 Duplicate</a>
                <form method="POST" action="..."
                      data-confirm="Delete?" data-confirm-label="Delete" data-confirm-danger="1">
                    @csrf @method('DELETE')
                    <button type="submit" class="row-actions-item row-actions-item--danger">
                        🗑 Delete
                    </button>
                </form>
            </x-slot>
        </x-row-actions-menu>

    Items go in the dropdown. Render visible primary buttons separately
    in the parent row before this component.

    Powered by Alpine.js — clicks outside / escape / re-click to close.
--}}

@props([
    'label' => 'More actions',
])

@once
@push('styles')
<style>
    .row-actions {
        position: relative;
        display: inline-block;
    }
    .row-actions-trigger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        background: var(--surface);
        color: var(--text-muted);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        cursor: pointer;
        transition: all var(--transition);
        line-height: 1;
        padding: 0;
        font-size: 1.05rem;
    }
    .row-actions-trigger:hover {
        background: var(--surface-soft);
        border-color: var(--text-muted);
        color: var(--text);
    }
    .row-actions-trigger:focus-visible {
        outline: 2px solid var(--teal);
        outline-offset: 2px;
    }
    .row-actions-trigger.is-open {
        background: var(--teal-light);
        border-color: var(--teal);
        color: var(--teal);
    }

    .row-actions-menu {
        /* Fixed-position so the menu escapes any parent overflow:hidden /
           overflow-x:auto. Coordinates are set on open via Alpine. */
        position: fixed;
        min-width: 220px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-md);
        padding: .35rem;
        z-index: 9999;
        animation: row-actions-pop 140ms cubic-bezier(.32,.72,.36,1);
    }
    .row-actions-menu::before { display: none; }
    @keyframes row-actions-pop {
        from { opacity: 0; transform: translateY(-6px) scale(.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .row-actions-item {
        display: flex;
        align-items: center;
        gap: .55rem;
        width: 100%;
        padding: .5rem .65rem;
        font-family: var(--font-sans);
        font-size: .8125rem;
        font-weight: 500;
        color: var(--text);
        background: transparent;
        border: none;
        border-radius: var(--radius-sm);
        cursor: pointer;
        text-align: left;
        text-decoration: none;
        transition: background var(--transition);
        line-height: 1.3;
    }
    .row-actions-item:hover {
        background: var(--surface-soft);
        text-decoration: none;
        color: var(--text);
    }
    .row-actions-item--danger {
        color: var(--danger);
    }
    .row-actions-item--danger:hover {
        background: var(--danger-light);
        color: var(--danger);
    }
    .row-actions-item__icon {
        font-size: .95rem;
        opacity: .85;
        flex-shrink: 0;
    }
    .row-actions-item form,
    .row-actions form { margin: 0; display: contents; }
    .row-actions-divider {
        height: 1px;
        background: var(--rule);
        margin: .25rem .45rem;
    }
</style>
@endpush
@endonce

<div class="row-actions"
     x-data="{
         open: false,
         menuTop: 0,
         menuLeft: 0,
         toggle() {
             if (this.open) { this.open = false; return; }
             this.$nextTick(() => {
                 const trigger = this.$refs.trigger;
                 const menu = this.$refs.menu;
                 const rect = trigger.getBoundingClientRect();
                 const menuW = menu ? menu.offsetWidth : 220;
                 const menuH = menu ? menu.offsetHeight : 200;
                 const vpW = window.innerWidth;
                 const vpH = window.innerHeight;
                 // Default: below + right-aligned to trigger
                 let top  = rect.bottom + 6;
                 let left = rect.right - menuW;
                 // Flip up if not enough room below
                 if (top + menuH > vpH - 8) {
                     top = rect.top - menuH - 6;
                 }
                 // Keep within viewport horizontally
                 if (left < 8) left = 8;
                 if (left + menuW > vpW - 8) left = vpW - menuW - 8;
                 this.menuTop = top;
                 this.menuLeft = left;
             });
             this.open = true;
         },
     }"
     @keydown.escape.window="open = false"
     @click.outside="open = false"
     @scroll.window="open = false"
     @resize.window="open = false">
    <button type="button"
            x-ref="trigger"
            class="row-actions-trigger"
            :class="open ? 'is-open' : ''"
            @click="toggle()"
            :aria-expanded="open"
            aria-haspopup="menu"
            aria-label="{{ $label }}">
        ⋮
    </button>
    <div class="row-actions-menu"
         x-ref="menu"
         x-show="open"
         x-cloak
         :style="`top: ${menuTop}px; left: ${menuLeft}px;`"
         role="menu">
        {{ $items ?? $slot }}
    </div>
</div>
