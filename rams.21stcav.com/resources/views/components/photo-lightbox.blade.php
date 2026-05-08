{{--
    Singleton photo lightbox — place ONCE per page (loaded by app.blade.php).

    Open from JS:
        window.openPhotoLightbox(photos, startIndex)

    Where `photos` is an array of objects:
        [
            { url: 'https://…/full.jpg', caption: 'Optional alt/caption' },
            …
        ]

    Triggers usually look like:
        <a href="{{ $url }}" target="_blank"
           onclick="event.preventDefault(); openPhotoLightbox(@js($photos), {{ $loop->index }});">
            <img src="{{ $thumb }}">
        </a>

    Keeping the <a href> + target="_blank" means middle-click / keyboard-open
    still gracefully opens the photo in a new tab when JS is disabled or when
    the openPhotoLightbox global hasn't loaded yet.

    Keyboard: Left/Right cycle, Esc closes (Esc is native <dialog> behaviour).
    Wraparound: cycles past the last photo back to the first.
--}}

@once
<dialog x-data="photoLightbox()" x-ref="lightbox" class="photo-lightbox" @close="onDialogClose()">
    <div class="photo-lightbox__stage">
        {{-- Close button (top-right) --}}
        <button type="button" class="photo-lightbox__close" @click="close()" aria-label="Close">✕</button>

        {{-- Prev / Next nav (only when more than one photo) --}}
        <button type="button" class="photo-lightbox__nav photo-lightbox__nav--prev"
                x-show="photos.length > 1" @click="prev()" aria-label="Previous photo">‹</button>
        <button type="button" class="photo-lightbox__nav photo-lightbox__nav--next"
                x-show="photos.length > 1" @click="next()" aria-label="Next photo">›</button>

        {{-- Image --}}
        <img class="photo-lightbox__img"
             x-show="photos.length > 0"
             :src="photos[index]?.url"
             :alt="photos[index]?.caption || ''">
    </div>

    {{-- Caption + counter strip --}}
    <div class="photo-lightbox__meta" x-show="photos.length > 0">
        <span class="photo-lightbox__caption" x-text="photos[index]?.caption || ''"></span>
        <span class="photo-lightbox__counter" x-show="photos.length > 1"
              x-text="(index + 1) + ' / ' + photos.length"></span>
    </div>
</dialog>

{{-- HTML5 allows <style> in body. Inlining avoids the @push('styles')
     ordering problem (head stack already rendered by the time this component
     reaches the body). --}}
<style>
    .photo-lightbox {
        padding: 0;
        border: 0;
        background: transparent;
        max-width: min(95vw, 1400px);
        max-height: 95vh;
        width: auto;
        height: auto;
        color: #fff;
    }
    .photo-lightbox::backdrop {
        background: rgba(0, 0, 0, .82);
    }
    .photo-lightbox__stage {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #111;
        border-radius: 8px;
        overflow: hidden;
        min-width: 280px;
        min-height: 280px;
    }
    .photo-lightbox__img {
        display: block;
        max-width: 95vw;
        max-height: 85vh;
        width: auto;
        height: auto;
        object-fit: contain;
    }
    .photo-lightbox__close,
    .photo-lightbox__nav {
        position: absolute;
        background: rgba(0, 0, 0, .55);
        color: #fff;
        border: 0;
        cursor: pointer;
        font-family: inherit;
        line-height: 1;
        transition: background .15s, transform .15s;
        z-index: 2;
    }
    .photo-lightbox__close {
        top: 12px;
        right: 12px;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        font-size: 16px;
        font-weight: 600;
    }
    .photo-lightbox__close:hover { background: rgba(0, 0, 0, .8); }
    .photo-lightbox__nav {
        top: 50%;
        transform: translateY(-50%);
        width: 48px;
        height: 64px;
        border-radius: 6px;
        font-size: 32px;
        font-weight: 300;
    }
    .photo-lightbox__nav--prev { left: 12px; }
    .photo-lightbox__nav--next { right: 12px; }
    .photo-lightbox__nav:hover {
        background: rgba(0, 0, 0, .85);
        transform: translateY(-50%) scale(1.05);
    }
    .photo-lightbox__meta {
        display: flex;
        gap: 1rem;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        background: rgba(0, 0, 0, .65);
        color: rgba(255, 255, 255, .9);
        font-size: 13px;
        margin-top: 6px;
        border-radius: 6px;
    }
    .photo-lightbox__caption {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .photo-lightbox__counter {
        flex-shrink: 0;
        font-variant-numeric: tabular-nums;
        opacity: .85;
    }
    @media (max-width: 600px) {
        .photo-lightbox__nav {
            width: 40px;
            height: 56px;
            font-size: 28px;
        }
        .photo-lightbox__nav--prev { left: 6px; }
        .photo-lightbox__nav--next { right: 6px; }
    }
</style>

<script>
    /**
     * Alpine factory for the singleton photo lightbox. Exposes a global
     * window.openPhotoLightbox(photos, startIndex) so any thumbnail across
     * the app can trigger it without extra wiring.
     */
    function photoLightbox() {
        return {
            photos: [],
            index: 0,

            init() {
                // Expose the global trigger. Defensive: don't overwrite if a
                // future component decides to register one before we mount.
                if (typeof window.openPhotoLightbox !== 'function') {
                    window.openPhotoLightbox = (photos, startIndex = 0) => {
                        if (! Array.isArray(photos) || photos.length === 0) return;
                        this.photos = photos;
                        this.index  = Math.max(0, Math.min(startIndex | 0, photos.length - 1));
                        this.$refs.lightbox.showModal();
                    };
                }

                // Keyboard nav — only when the dialog is open. Native <dialog>
                // already handles Escape, so we just bind arrows here.
                document.addEventListener('keydown', (e) => {
                    if (! this.$refs.lightbox.open) return;
                    if (e.key === 'ArrowLeft')  { e.preventDefault(); this.prev(); }
                    if (e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
                });

                // Click on backdrop closes (default <dialog> behaviour misses
                // taps outside the visible image because the stage is centred).
                this.$refs.lightbox.addEventListener('click', (e) => {
                    if (e.target === this.$refs.lightbox) this.close();
                });
            },

            prev() {
                if (this.photos.length < 2) return;
                this.index = (this.index - 1 + this.photos.length) % this.photos.length;
            },
            next() {
                if (this.photos.length < 2) return;
                this.index = (this.index + 1) % this.photos.length;
            },
            close() {
                this.$refs.lightbox.close();
            },
            onDialogClose() {
                // Reset state on close so a stale photo doesn't briefly flash
                // when the dialog is reopened with a different photo set.
                this.photos = [];
                this.index = 0;
            },
        };
    }
</script>
@endonce
