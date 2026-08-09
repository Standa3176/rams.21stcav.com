{{--
    Shared engineer offline-capture module (quick-260809-so1).

    Seed of a future shared 21stcav/engineer-capture package. Ported from the
    Worksheet's proven offline machinery (task 260603-eha OfflineQueue + task
    260504-ktt convertToJpegBlob) and GENERALISED so each queued record carries
    its own upload `endpoint` + generic `fields` object — so the same module can
    serve the Site Survey today and the Worksheet later (deferred dedupe step).

    Exposes on window:
      - convertToJpegBlob(file, maxSide=2400, quality=0.92) -> Promise<Blob>
      - convertToJpegBlobSafe(file)                          -> Promise<Blob|File>
      - OfflineQueue { enqueue, list, count, remove, drain, subscribe, ... }
      - mountOfflineChip(target?)                            -> render the chip

    IndexedDB DB name is 'engineer-capture' (v1, store 'pending_uploads') — kept
    DISTINCT from the Worksheet's own IndexedDB database so neither queue ever
    surfaces the other's pending rows.

    Pure JS — no Blade variables, no @vite, no build step. Include with:
      @include('partials._engineer-offline-capture')
--}}
<script>
    (function () {
        'use strict';

        // ── HEIC-safe JPEG re-encode (copied faithfully from the Worksheet) ──
        // iOS shoots HEIC; the server image validator + Claude vision OCR both
        // reject it. Draw to a canvas and re-encode as JPEG client-side,
        // downscaling to maxSide=2400 @ q0.92. Falls back to the raw file if
        // anything fails so the change never makes uploads worse than before.
        async function convertToJpegBlob(file, maxSide = 2400, quality = 0.92) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onerror = () => reject(new Error('FileReader failed'));
                reader.onload = () => {
                    const img = new Image();
                    img.onerror = () => reject(new Error('Image decode failed'));
                    img.onload = () => {
                        const w0 = img.naturalWidth, h0 = img.naturalHeight;
                        if (!w0 || !h0) return reject(new Error('Empty image'));
                        const scale = Math.min(1, maxSide / Math.max(w0, h0));
                        const w = Math.round(w0 * scale), h = Math.round(h0 * scale);
                        const canvas = document.createElement('canvas');
                        canvas.width = w; canvas.height = h;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, w, h);
                        canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('toBlob returned null')), 'image/jpeg', quality);
                    };
                    img.src = reader.result;
                };
                reader.readAsDataURL(file);
            });
        }

        // Never-throw wrapper: returns the JPEG blob, or the original file if
        // conversion fails (very old browser, CORS, OOM).
        async function convertToJpegBlobSafe(file) {
            try {
                return await convertToJpegBlob(file);
            } catch (_) {
                return file;
            }
        }

        // ── OfflineQueue module ──────────────────────────────────────────────
        // Self-contained vanilla JS over native IndexedDB. Generalised from the
        // Worksheet's queue: records store their own `endpoint` + `fields`.
        const DB_NAME    = 'engineer-capture';
        const DB_VERSION = 1;
        const STORE      = 'pending_uploads';

        const OfflineQueue = {
            unavailable:   false,
            _draining:     false,
            _db:           null,
            _warned:       false,
            _uploadingIds: new Set(),
        };

        OfflineQueue.db = function () {
            if (!('indexedDB' in window)) {
                OfflineQueue.unavailable = true;
                return Promise.reject(new Error('IndexedDB unavailable'));
            }
            if (OfflineQueue._db) return Promise.resolve(OfflineQueue._db);
            return new Promise(function (resolve, reject) {
                const req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = function (e) {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(STORE)) {
                        const store = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
                        store.createIndex('capturedAt', 'capturedAt', { unique: false });
                    }
                };
                req.onsuccess = function (e) { OfflineQueue._db = e.target.result; resolve(OfflineQueue._db); };
                req.onerror   = function (e) { OfflineQueue.unavailable = true; reject(e.target.error || new Error('IndexedDB open failed')); };
            });
        };

        function tx(mode, fn) {
            return OfflineQueue.db().then(function (db) {
                return new Promise(function (resolve, reject) {
                    const transaction = db.transaction([STORE], mode);
                    const store = transaction.objectStore(STORE);
                    let result;
                    try { result = fn(store); } catch (e) { reject(e); return; }
                    transaction.oncomplete = function () { resolve(result); };
                    transaction.onerror    = function () { reject(transaction.error); };
                    transaction.onabort    = function () { reject(transaction.error); };
                });
            });
        }

        function _optionalToast(msg, variant, ttl) {
            if (typeof window.showToast === 'function') {
                try { window.showToast(msg, variant, ttl); } catch (_) {}
            }
        }

        // ── Public API ───────────────────────────────────────────────────────

        // row = { token, endpoint, blob, originalName?, mime?, fields? }
        OfflineQueue.enqueue = function (row) {
            if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                if (!OfflineQueue._warned) {
                    OfflineQueue._warned = true;
                    _optionalToast('⚠ Offline queue unsupported on this browser — uploads still work when online.', 'warning', 6000);
                }
                return Promise.resolve(null);
            }
            const record = {
                token:        row.token || '',
                endpoint:     row.endpoint || '',
                blob:         row.blob,
                originalName: row.originalName || 'photo.jpg',
                mime:         row.mime || 'image/jpeg',
                fields:       row.fields || {},
                attemptCount: 0,
                lastError:    null,
                capturedAt:   Date.now(),
            };
            return tx('readwrite', function (store) {
                const req = store.add(record);
                return new Promise(function (resolve, reject) {
                    req.onsuccess = function () { resolve(req.result); };
                    req.onerror   = function () { reject(req.error); };
                });
            }).then(function (id) {
                OfflineQueue._notifyChange();
                return id;
            }).catch(function (e) {
                if (!OfflineQueue._warned) {
                    OfflineQueue._warned = true;
                    _optionalToast("⚠ Couldn't save offline (storage error) — try again when online.", 'error', 6000);
                }
                throw e;
            });
        };

        OfflineQueue.list = function () {
            if (OfflineQueue.unavailable || !('indexedDB' in window)) return Promise.resolve([]);
            return tx('readonly', function (store) {
                const req = store.getAll();
                return new Promise(function (resolve, reject) {
                    req.onsuccess = function () {
                        const rows = (req.result || []).map(function (r) {
                            return {
                                id:           r.id,
                                endpoint:     r.endpoint,
                                capturedAt:   r.capturedAt,
                                attemptCount: r.attemptCount || 0,
                                lastError:    r.lastError || null,
                            };
                        });
                        rows.sort(function (a, b) { return a.capturedAt - b.capturedAt; });
                        resolve(rows);
                    };
                    req.onerror = function () { reject(req.error); };
                });
            }).catch(function () { return []; });
        };

        OfflineQueue.count = function () {
            if (OfflineQueue.unavailable || !('indexedDB' in window)) return Promise.resolve(0);
            return tx('readonly', function (store) {
                const req = store.count();
                return new Promise(function (resolve, reject) {
                    req.onsuccess = function () { resolve(req.result || 0); };
                    req.onerror   = function () { reject(req.error); };
                });
            }).catch(function () { return 0; });
        };

        OfflineQueue.remove = function (id) {
            if (OfflineQueue.unavailable || !('indexedDB' in window)) return Promise.resolve();
            return tx('readwrite', function (store) { store.delete(id); }).then(function () {
                OfflineQueue._uploadingIds.delete(id);
                OfflineQueue._notifyChange();
            });
        };

        function _getAllRaw() {
            return tx('readonly', function (store) {
                const req = store.getAll();
                return new Promise(function (resolve, reject) {
                    req.onsuccess = function () { resolve(req.result || []); };
                    req.onerror   = function () { reject(req.error); };
                });
            });
        }

        function _updateRow(row) { return tx('readwrite', function (store) { store.put(row); }); }
        function _sleep(ms)      { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }

        // Drain: POST each record to its OWN endpoint. Classify:
        //   2xx                -> delete (success)
        //   413 / 415 / 422    -> terminal, discard (server will never accept it)
        //   network / 5xx / off -> keep + bump attemptCount (max-retry marker >=3)
        OfflineQueue.drain = function (opts) {
            opts = opts || {};
            if (OfflineQueue.unavailable || !('indexedDB' in window)) {
                return Promise.resolve({ successCount: 0, failureCount: 0 });
            }
            if (OfflineQueue._draining) {
                return Promise.resolve({ successCount: 0, failureCount: 0, skipped: true });
            }
            OfflineQueue._draining = true;

            const onSuccess  = opts.onSuccess  || function () {};
            const onFailure  = opts.onFailure  || function () {};
            const onProgress = opts.onProgress || function () {};

            let successCount = 0;
            let failureCount = 0;
            let hitMaxRetry  = 0;

            const csrfEl = document.querySelector('meta[name=csrf-token]');
            const csrf   = csrfEl ? csrfEl.content : '';

            return _getAllRaw().then(function (rows) {
                rows.sort(function (a, b) { return a.capturedAt - b.capturedAt; });

                return rows.reduce(function (chain, row) {
                    return chain.then(function () {
                        OfflineQueue._uploadingIds.add(row.id);
                        OfflineQueue._notifyChange();
                        onProgress(row);

                        const fd = new FormData();
                        try {
                            fd.append('photo', row.blob, row.originalName || 'photo.jpg');
                        } catch (e) {
                            row.attemptCount = (row.attemptCount || 0) + 1;
                            row.lastError = 'Local blob unreadable';
                            failureCount++;
                            if (row.attemptCount >= 3) hitMaxRetry++;
                            OfflineQueue._uploadingIds.delete(row.id);
                            return _updateRow(row).then(function () { onFailure(row, e); });
                        }
                        const fields = row.fields || {};
                        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });

                        return fetch(row.endpoint, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body: fd,
                        }).then(function (resp) {
                            if (resp.ok) {
                                successCount++;
                                OfflineQueue._uploadingIds.delete(row.id);
                                return tx('readwrite', function (store) { store.delete(row.id); })
                                    .then(function () { return resp.json().catch(function () { return {}; }); })
                                    .then(function (json) { onSuccess(row, json); });
                            }
                            // Terminal validation — the server will never accept this
                            // payload, so discard it instead of retrying forever.
                            if (resp.status === 413 || resp.status === 415 || resp.status === 422) {
                                failureCount++;
                                OfflineQueue._uploadingIds.delete(row.id);
                                return tx('readwrite', function (store) { store.delete(row.id); })
                                    .then(function () { onFailure(row, new Error('HTTP ' + resp.status + ' (discarded)')); });
                            }
                            // Retryable — keep and bump.
                            row.attemptCount = (row.attemptCount || 0) + 1;
                            row.lastError = resp.statusText || ('HTTP ' + resp.status);
                            failureCount++;
                            if (row.attemptCount >= 3) hitMaxRetry++;
                            OfflineQueue._uploadingIds.delete(row.id);
                            return _updateRow(row).then(function () { onFailure(row, new Error(row.lastError)); });
                        }).catch(function (err) {
                            row.attemptCount = (row.attemptCount || 0) + 1;
                            row.lastError = (err && err.message) || 'Network error';
                            failureCount++;
                            if (row.attemptCount >= 3) hitMaxRetry++;
                            OfflineQueue._uploadingIds.delete(row.id);
                            return _updateRow(row).then(function () { onFailure(row, err); });
                        }).then(function () {
                            return _sleep(200); // throttle: 5/sec << server limits
                        });
                    });
                }, Promise.resolve());
            }).then(function () {
                OfflineQueue._draining = false;
                OfflineQueue._notifyChange();
                return { successCount: successCount, failureCount: failureCount, hitMaxRetry: hitMaxRetry };
            }).catch(function (e) {
                OfflineQueue._draining = false;
                OfflineQueue._notifyChange();
                return { successCount: successCount, failureCount: failureCount, hitMaxRetry: hitMaxRetry, error: e };
            });
        };

        OfflineQueue._notifyChange = function () {
            try { window.dispatchEvent(new CustomEvent('offline-queue-change')); } catch (e) {}
        };
        OfflineQueue.subscribe = function (handler) {
            window.addEventListener('offline-queue-change', handler);
        };

        // ── Pending chip ─────────────────────────────────────────────────────
        // A small, always-visible fixed chip (bottom-right) that surfaces the
        // pending-photo count + a Retry button. Chosen over an inline step-3
        // container because the survey wizard hides inactive steps — an inline
        // chip would vanish when the engineer advances past the photo step.
        // All DOM built via textContent (no innerHTML on any dynamic string).
        function mountOfflineChip(target) {
            let host = null;
            if (typeof target === 'string') host = document.getElementById(target);
            else if (target instanceof HTMLElement) host = target;

            let created = false;
            if (!host) {
                host = document.createElement('div');
                host.id = 'engineerOfflineChipFixed';
                host.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;';
                document.body.appendChild(host);
                created = true;
            }

            const chip = document.createElement('div');
            chip.style.cssText = 'display:none;align-items:center;gap:10px;background:#1f2937;color:#fff;padding:8px 12px;border-radius:9999px;box-shadow:0 4px 14px rgba(0,0,0,.25);font:600 13px/1.2 system-ui,-apple-system,sans-serif;';
            const label = document.createElement('span');
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.textContent = 'Retry';
            retry.style.cssText = 'background:#3b82f6;color:#fff;border:0;border-radius:9999px;padding:4px 10px;font:600 12px system-ui;cursor:pointer;';
            retry.addEventListener('click', function () { OfflineQueue.drain(); });
            chip.appendChild(label);
            chip.appendChild(retry);
            host.appendChild(chip);

            const refresh = function () {
                OfflineQueue.count().then(function (n) {
                    if (n > 0) {
                        label.textContent = n + ' photo' + (n === 1 ? '' : 's') + ' saved on this device';
                        chip.style.display = 'inline-flex';
                    } else {
                        chip.style.display = 'none';
                    }
                });
            };
            OfflineQueue.subscribe(refresh);
            refresh();
            return { host: host, created: created, refresh: refresh };
        }

        // ── Auto-behaviours ──────────────────────────────────────────────────
        function _autoDrain() { if (navigator.onLine) OfflineQueue.drain(); }
        window.addEventListener('online', _autoDrain);
        setInterval(_autoDrain, 60000);

        function _boot() {
            // Prefer an explicit container if the page provided one, else the
            // fixed chip mounts itself.
            mountOfflineChip(document.getElementById('engineerOfflineChip') || undefined);
            _autoDrain();
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', _boot);
        } else {
            _boot();
        }

        // ── Exports ──────────────────────────────────────────────────────────
        window.convertToJpegBlob     = convertToJpegBlob;
        window.convertToJpegBlobSafe = convertToJpegBlobSafe;
        window.OfflineQueue          = OfflineQueue;
        window.mountOfflineChip      = mountOfflineChip;
    })();
</script>
