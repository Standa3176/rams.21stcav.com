{{--
    Standardised public-link copy button.

    <x-copy-link-button :url="$survey->publicUrl()" />
    <x-copy-link-button :url="$ws->publicUrl()" label="Engineer Link" />

    Props:
      url   — full public URL to copy (required)
      label — button text (default "Copy Link")
--}}

@props([
    'url',
    'label' => 'Copy Link',
])

@once
@push('styles')
<style>
    .copy-link-btn {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .4rem .8rem;
        background: var(--surface);
        color: var(--text);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius-sm);
        font-family: var(--font-sans);
        font-size: .8125rem;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        transition: all var(--transition);
        text-decoration: none;
        line-height: 1.25;
    }
    .copy-link-btn:hover {
        background: var(--surface-soft);
        border-color: var(--text-muted);
        color: var(--text);
        text-decoration: none;
    }
    .copy-link-btn.is-copied {
        background: var(--success-light);
        border-color: var(--success);
        color: #166534;
    }
    .copy-link-btn__icon {
        font-size: .9rem;
        line-height: 1;
    }
</style>
@endpush

@push('scripts')
<script>
    if (typeof window.copyEngineerLink !== 'function') {
        window.copyEngineerLink = function(url, btn) {
            const labelEl = btn.querySelector('.copy-link-btn__label');
            const iconEl  = btn.querySelector('.copy-link-btn__icon');
            const origLabel = labelEl ? labelEl.textContent : '';
            const origIcon  = iconEl  ? iconEl.textContent  : '';

            const finish = () => {
                btn.classList.add('is-copied');
                if (iconEl)  iconEl.textContent  = '✓';
                if (labelEl) labelEl.textContent = 'Copied';
                setTimeout(() => {
                    btn.classList.remove('is-copied');
                    if (iconEl)  iconEl.textContent  = origIcon;
                    if (labelEl) labelEl.textContent = origLabel;
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(finish).catch(() => {
                    /* fallback below */
                    fallback();
                });
            } else {
                fallback();
            }

            function fallback() {
                const ta = document.createElement('textarea');
                ta.value = url;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); finish(); } catch (e) { /* noop */ }
                document.body.removeChild(ta);
            }
        };
    }
</script>
@endpush
@endonce

<button type="button"
        class="copy-link-btn"
        onclick="copyEngineerLink('{{ $url }}', this)"
        title="{{ $url }}"
        aria-label="Copy public link to clipboard">
    <span class="copy-link-btn__icon" aria-hidden="true">⎘</span>
    <span class="copy-link-btn__label">{{ $label }}</span>
</button>
