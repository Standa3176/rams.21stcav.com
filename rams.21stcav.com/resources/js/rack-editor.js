/*
 * Phase 18 Plan 03 — rack editor.
 *
 * Alpine x-data factory exposed at window.rackEditor. Loaded ONLY on
 * /projects/{p}/drawings/{r}/edit via a dedicated Vite entry — keeps
 * Sortable.js out of the global Alpine bundle.
 *
 * The cursor-walk algorithm in onRackReorder() preserves per-item U-position
 * locks across drag operations: locked rack items keep their u_position
 * regardless of DOM order; unlocked items get u_position assigned by walking
 * bottom-up over the reordered DOM and jumping the cursor over locked ranges.
 *
 * Server-side, the locked-item-holds-position contract is asserted by
 * RackEditorEndpointsTest::test_save_rack_canvas_locked_item_holds_position_when_others_reflow_around_it
 * (Warning 7 fix from checker iteration 2).
 */
import Sortable from 'sortablejs';
import axios from 'axios';

window.rackEditor = function (initialState) {
    return {
        rackHeightU: initialState.rack_meta.rack_height_u || 42,
        rackLabel: initialState.rack_meta.rack_label || 'Rack 1',
        nominalVoltageV: initialState.rack_meta.nominal_voltage_v || 230,
        floor: initialState.rack_meta.floor || '',
        rackItems: initialState.rack_items || [],
        paletteRackMounted: initialState.palette_rack_mounted || [],
        paletteOther: initialState.palette_other || [],
        saveUrl: initialState.save_url,
        flipUrl: initialState.flip_url,
        saving: false,
        savedAt: null,
        error: null,

        init() {
            // Sortable for the rack column — DnD reorder + drop from palette.
            Sortable.create(this.$refs.rackColumn, {
                group: 'rack',
                animation: 150,
                handle: '.rack-item-handle',
                filter: '.rack-item-locked', // locked items don't drag
                preventOnFilter: false,
                onEnd: (evt) => this.onRackReorder(evt),
            });
            // Sortable for both palette columns — items drag INTO rackColumn.
            Sortable.create(this.$refs.paletteRackMounted, {
                group: { name: 'rack', pull: 'clone', put: false },
                sort: false,
                animation: 150,
                onClone: (evt) => {
                    evt.clone.dataset.fromPalette = 'true';
                },
            });
            Sortable.create(this.$refs.paletteOther, {
                group: { name: 'rack', pull: 'clone', put: false },
                sort: false,
                animation: 150,
                onClone: (evt) => {
                    evt.clone.dataset.fromPalette = 'true';
                },
            });
        },

        // Cursor walk: bottom-up over reordered DOM, assign u_position to
        // unlocked items, preserve locked items' positions verbatim.
        onRackReorder() {
            const rows = Array.from(
                this.$refs.rackColumn.querySelectorAll('[data-equipment-id]')
            );
            // Pre-collect locked item ranges so the cursor can jump over them.
            const lockedRanges = this.rackItems
                .filter((it) => it.locked)
                .map((it) => ({
                    from: it.u_position,
                    to: it.u_position + (it.u_height || 1) - 1,
                }));
            const cursorOverlapsLocked = (pos, height) =>
                lockedRanges.some(
                    (r) => !(pos + height - 1 < r.from || pos > r.to)
                );

            const newItems = [];
            let cursor = 1; // bottom-up

            // Bottom-up: U-1 is the LAST row visually because the rack
            // column flexes flex-direction: column-reverse.
            for (let i = rows.length - 1; i >= 0; i--) {
                const row = rows[i];
                const eqId = row.dataset.equipmentId;
                const existing = this.rackItems.find(
                    (it) => String(it.equipment_id) === String(eqId)
                );
                if (existing && existing.locked) {
                    // Honour locks — preserve u_position; do NOT advance the
                    // cursor (jump-over logic below skips locked ranges).
                    newItems.push(existing);
                    continue;
                }
                const uHeight = parseFloat(row.dataset.uHeight || '1') || 1;
                // Advance cursor past any locked range it would collide with.
                while (cursorOverlapsLocked(cursor, uHeight)) {
                    const blocking = lockedRanges.find(
                        (r) => !(cursor + uHeight - 1 < r.from || cursor > r.to)
                    );
                    cursor = blocking.to + 1;
                }
                if (existing) {
                    newItems.push({ ...existing, u_position: cursor });
                } else {
                    // Drag-from-palette case
                    newItems.push({
                        equipment_id: eqId,
                        name: row.dataset.name || '',
                        part_no: row.dataset.partNo || '',
                        u_position: cursor,
                        u_height: uHeight,
                        locked: false,
                        weight_kg: parseFloat(row.dataset.weightKg) || null,
                        current_draw_a:
                            parseFloat(row.dataset.currentDrawA) || null,
                        btu_per_hour:
                            parseInt(row.dataset.btuPerHour, 10) || null,
                    });
                }
                cursor += uHeight;
            }
            this.rackItems = newItems;
        },

        toggleLock(equipmentId) {
            this.rackItems = this.rackItems.map((it) =>
                String(it.equipment_id) === String(equipmentId)
                    ? { ...it, locked: !it.locked }
                    : it
            );
        },

        removeItem(equipmentId) {
            this.rackItems = this.rackItems.filter(
                (it) => String(it.equipment_id) !== String(equipmentId)
            );
        },

        async save() {
            this.saving = true;
            this.error = null;
            try {
                const payload = {
                    rack_meta: {
                        rack_label: this.rackLabel,
                        rack_height_u: parseInt(this.rackHeightU, 10) || 42,
                        nominal_voltage_v:
                            parseInt(this.nominalVoltageV, 10) || 230,
                        floor: this.floor || null,
                    },
                    rack_items: this.rackItems,
                };
                const csrfToken = document.querySelector(
                    'meta[name="csrf-token"]'
                )?.content;
                const res = await axios.post(this.saveUrl, payload, {
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                });
                this.savedAt = new Date().toLocaleTimeString();
                if (res.data?.status === 'ready') {
                    // Reload page so the rendered SVG (status=ready,
                    // generated_svg) is visible via show.blade.php's
                    // existing kind-agnostic SVG render branch.
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (e) {
                const errs = e.response?.data?.errors;
                this.error =
                    e.response?.data?.error ||
                    (errs ? Object.values(errs).flat().join(', ') : null) ||
                    e.message;
            } finally {
                this.saving = false;
            }
        },

        async flipRackMounted(partNo, isRackMounted) {
            try {
                const csrfToken = document.querySelector(
                    'meta[name="csrf-token"]'
                )?.content;
                await axios.post(
                    this.flipUrl,
                    { part_no: partNo, is_rack_mounted: isRackMounted },
                    { headers: { 'X-CSRF-TOKEN': csrfToken } }
                );
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('flipRackMounted failed', e);
            }
        },
    };
};
