<?php $__env->startSection('title', 'Review Extracted Data — ' . ($rams->project_name ?: 'New RAMS')); ?>

<?php $__env->startPush('styles'); ?>
<style>
/* ── Review page specifics ─────────────────────────────────────────── */
.review-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}
.review-section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.review-section-header h2 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}
.review-section-body {
    padding: 1.25rem;
}
.review-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 640px) {
    .review-grid-2 { grid-template-columns: 1fr; }
}

/* ── Repeater tables ───────────────────────────────────────────────── */
.repeater-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .875rem;
}
.repeater-table th {
    background: var(--teal-light);
    color: var(--teal);
    font-weight: 600;
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    padding: .5rem .75rem;
    text-align: left;
    border-bottom: 1px solid var(--teal-mid);
}
.repeater-table td {
    padding: .45rem .5rem;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
}
.repeater-table tr:last-child td { border-bottom: none; }
.repeater-table input[type="text"],
.repeater-table input[type="number"],
.repeater-table select,
.repeater-table textarea {
    width: 100%;
    padding: .35rem .5rem;
    border: 1px solid #d1d5db;
    border-radius: 5px;
    font-size: .875rem;
    font-family: inherit;
    background: #fff;
    transition: border-color var(--transition);
}
.repeater-table input:focus,
.repeater-table select:focus,
.repeater-table textarea:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 2px rgba(23,138,149,.12);
}
.repeater-table textarea { resize: vertical; min-height: 60px; }
.col-qty   { width: 70px; }
.col-area  { width: 150px; }
.col-risk  { width: 110px; }
.col-act   { width: 140px; }
.col-del   { width: 40px; text-align: center; }

/* ── PPE checkboxes ────────────────────────────────────────────────── */
.ppe-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: .5rem;
}
.ppe-check {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .875rem;
    padding: .35rem .5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition), border-color var(--transition);
}
.ppe-check:hover { background: var(--teal-light); border-color: var(--teal-mid); }
.ppe-check input[type="checkbox"] { cursor: pointer; }

/* ── Access checkboxes ─────────────────────────────────────────────── */
.access-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: .5rem;
}

/* ── Confidence badge ──────────────────────────────────────────────── */
.confidence-alert {
    background: #fffbeb;
    border: 1px solid #f59e0b;
    border-radius: var(--radius-sm);
    padding: .75rem 1rem;
    font-size: .875rem;
    color: #92400e;
    display: flex;
    gap: .625rem;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}
.confidence-icon { font-size: 1.1rem; flex-shrink: 0; }

/* ── Action bar ────────────────────────────────────────────────────── */
.action-bar {
    position: sticky;
    bottom: 0;
    background: var(--surface);
    border-top: 1px solid var(--border);
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    justify-content: flex-end;
    z-index: 50;
    box-shadow: 0 -2px 8px rgba(0,0,0,.07);
}

/* ── Remove button ─────────────────────────────────────────────────── */
.btn-remove {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: .25rem;
    border-radius: 3px;
    transition: background var(--transition);
}
.btn-remove:hover { background: #fee2e2; }

/* ── Status badge overrides for pipeline statuses ──────────────────── */
.badge-warning { background: #fffbeb; color: #92400e; border: 1px solid #f59e0b; }
.badge-green   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.badge-red     { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>


<div class="page-header">
    <div>
        <h1 class="page-title">Review Extracted Data</h1>
        <p style="color:var(--text-muted);font-size:.875rem;margin-top:.25rem;">
            Review and correct the data extracted from your quote PDF before generating the RAMS document.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <span class="badge <?php echo e($rams->statusBadgeClass()); ?>"><?php echo e($rams->statusLabel()); ?></span>
        <a href="<?php echo e(route('rams.index')); ?>" class="btn btn-outline btn-sm">← Back to RAMS</a>
    </div>
</div>


<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>


<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <strong>Please fix the following errors:</strong>
        <ul style="margin:.5rem 0 0 1.25rem;padding:0;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>


<?php
    $confidence = $reviewPayload['meta']['parser_confidence'] ?? null;
    $isLowConfidence = $confidence !== null && $confidence < 0.5;
?>
<?php if($isLowConfidence): ?>
    <div class="confidence-alert">
        <span class="confidence-icon">⚠️</span>
        <div>
            <strong>Low parser confidence (<?php echo e(round($confidence * 100)); ?>%)</strong> — the system had difficulty
            reading this PDF. Please review all extracted data carefully, correct any errors, and ensure
            all equipment, activities, and hazards are accurate before approving.
        </div>
    </div>
<?php elseif($confidence !== null): ?>
    <div style="background:var(--teal-light);border:1px solid var(--teal-mid);border-radius:var(--radius-sm);padding:.6rem 1rem;font-size:.8125rem;color:#0f5460;margin-bottom:1.25rem;display:flex;gap:.5rem;align-items:center;">
        <span>✓</span>
        <span>Parser confidence: <strong><?php echo e(round($confidence * 100)); ?>%</strong> — data looks good. Review below and approve when ready.</span>
    </div>
<?php endif; ?>










<form id="review-form" method="POST" action="<?php echo e(route('rams.approve', $rams)); ?>" novalidate>
    <?php echo csrf_field(); ?>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>1. Project Details</h2>
        </div>
        <div class="review-section-body">
            <div class="review-grid-2">
                <div class="form-group">
                    <label class="form-label">
                        Project Name <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="text"
                           name="project[project_name]"
                           class="form-control <?php $__errorArgs = ['project.project_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                           value="<?php echo e(old('project.project_name', $reviewPayload['project']['project_name'])); ?>"
                           maxlength="255"
                           required>
                    <?php $__errorArgs = ['project.project_name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="form-error"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Quote / Project Ref</label>
                    <input type="text"
                           name="project[quote_ref]"
                           class="form-control"
                           value="<?php echo e(old('project.quote_ref', $reviewPayload['project']['quote_ref'])); ?>"
                           maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Client Name</label>
                    <input type="text"
                           name="project[client_name]"
                           class="form-control"
                           value="<?php echo e(old('project.client_name', $reviewPayload['project']['client_name'])); ?>"
                           maxlength="255">
                </div>
                <div class="form-group">
                    <label class="form-label">Site Name</label>
                    <input type="text"
                           name="project[site_name]"
                           class="form-control"
                           value="<?php echo e(old('project.site_name', $reviewPayload['project']['site_name'])); ?>"
                           maxlength="255">
                </div>
                <div class="form-group" style="grid-column:span 2;">
                    <label class="form-label">Site Address</label>
                    <input type="text"
                           name="project[site_address]"
                           class="form-control"
                           value="<?php echo e(old('project.site_address', $reviewPayload['project']['site_address'])); ?>"
                           maxlength="500">
                </div>
                <div class="form-group">
                    <label class="form-label">Prepared By</label>
                    <input type="text"
                           name="project[prepared_by]"
                           class="form-control"
                           value="<?php echo e(old('project.prepared_by', $reviewPayload['project']['prepared_by'])); ?>"
                           maxlength="255">
                </div>
            </div>
        </div>
    </div>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>2. Equipment</h2>
            <span style="font-size:.78rem;color:var(--text-muted);">
                Categorised lists — only Hardware feeds RAMS &amp; O&amp;M.
            </span>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Categorise each line item. Only items marked <strong>Hardware</strong> will appear in RAMS &amp; O&amp;M lists.
            </p>
            <?php $__errorArgs = ['equipment'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="form-error" style="padding:.75rem 1.25rem;"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            <?php
                $categoryOptions = [
                    'hardware'    => 'Hardware',
                    'cables'      => 'Cables',
                    'consumables' => 'Consumables',
                    'services'    => 'Services / Professional',
                    'option'      => 'Option (Optional Items)',
                ];
                $rawEquipment = session()->hasOldInput()
                    ? (old('equipment', []) ?? [])
                    : ($reviewPayload['equipment'] ?? []);

                $equipmentRows = [];
                foreach ($rawEquipment as $i => $item) {
                    $equipmentRows[] = [
                        'idx'  => $i,
                        'item' => $item,
                    ];
                }

                $equipmentByCategory = [
                    'hardware'    => [],
                    'cables'      => [],
                    'consumables' => [],
                    'services'    => [],
                    'option'      => [],
                ];

                foreach ($equipmentRows as $row) {
                    $item = $row['item'];
                    $cat  = strtolower((string) ($item['category'] ?? ''));
                    if ($cat === '' || ! array_key_exists($cat, $equipmentByCategory)) {
                        // Auto-detect category from description/part number for records
                        // that pre-date category extraction in the draft builder.
                        $desc = strtolower(($item['name'] ?? '') . ' ' . ($item['part_number'] ?? ''));
                        if (str_contains($desc, 'optional') || str_contains($desc, 'option')) {
                            $cat = 'option';
                        } elseif (preg_match('/\b(?:cable|cat6a?|cat5|hdmi|sdi|utp|ftp|stp|patch\s+lead|usb|fibre|fiber|rg6|rg59)\b/', $desc)) {
                            $cat = 'cables';
                        } elseif (preg_match('/\b(?:install(?:ation)?|commission|configuration|programming|labour|support|survey|management|training)\b/', $desc)) {
                            $cat = 'services';
                        } elseif (preg_match('/\b(?:consumable|fixing|fastener|rawlplug|anchor|screw|bolt|tape|label|cleat|tie|strap)\b/', $desc)) {
                            $cat = 'consumables';
                        } else {
                            $cat = 'hardware';
                        }
                    }
                    $equipmentByCategory[$cat][] = $row;
                }
            ?>

            <?php $__currentLoopData = $categoryOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $catKey => $catLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border); background:#fbfcfd; display:flex; align-items:center; justify-content:space-between;">
                    <strong style="color:#0f5460;"><?php echo e($catLabel); ?></strong>
                    <button type="button" class="btn btn-outline btn-sm"
                            onclick="addRow('equipment-tbody-<?php echo e($catKey); ?>', equipmentRowTemplate, '<?php echo e($catKey); ?>')">
                        + Add <?php echo e($catLabel); ?>

                    </button>
                </div>
                <table class="repeater-table">
                    <thead>
                        <tr>
                            <th class="col-qty">Qty</th>
                            <th style="width:140px;">Part Number</th>
                            <th>Equipment / Item Description</th>
                            <th style="width:150px;">Category</th>
                            <th class="col-area">Title / Section</th>
                            <th class="col-del"></th>
                        </tr>
                    </thead>
                    <tbody id="equipment-tbody-<?php echo e($catKey); ?>">
                        <?php
                            $rowsForCat = $equipmentByCategory[$catKey] ?? [];
                            $rowsByRoom = [];
                            foreach ($rowsForCat as $row) {
                                $item = $row['item'];
                                $room = trim((string) ($item['area'] ?? ''));
                                if ($room === '') { $room = 'General'; }
                                $rowsByRoom[$room][] = $row;
                            }
                            ksort($rowsByRoom, SORT_NATURAL | SORT_FLAG_CASE);
                        ?>
                        <?php $__empty_1 = true; $__currentLoopData = $rowsByRoom; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $roomName => $roomRows): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr data-room-row="1" style="background:#f7fafb;">
                                <td colspan="6" style="font-weight:600;color:#0f5460;padding:.5rem .75rem;border-bottom:1px solid var(--border);">
                                    <?php echo e($roomName); ?>

                                </td>
                            </tr>
                            <?php $__currentLoopData = $roomRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php
                                    $i    = $row['idx'];
                                    $item = $row['item'];
                                    $selectedCategory = old("equipment.{$i}.category", $item['category'] ?? $catKey);
                                ?>
                                <tr data-equip-row="1">
                                <td class="col-qty">
                                    <input type="number"
                                           name="equipment[<?php echo e($i); ?>][quantity]"
                                           value="<?php echo e(old("equipment.{$i}.quantity", $item['quantity'] ?? 1)); ?>"
                                           min="1" max="999">
                                </td>
                                <td style="width:140px;">
                                    <input type="text"
                                           name="equipment[<?php echo e($i); ?>][part_number]"
                                           value="<?php echo e(old("equipment.{$i}.part_number", $item['part_number'] ?? '')); ?>"
                                           placeholder="e.g. YEA-MVC-S90"
                                           maxlength="60"
                                           style="font-family:monospace;font-size:.82rem;text-transform:uppercase;"
                                           oninput="this.value=this.value.toUpperCase()">
                                </td>
                                <td>
                                    <input type="text"
                                           name="equipment[<?php echo e($i); ?>][name]"
                                           value="<?php echo e(old("equipment.{$i}.name", $item['name'] ?? '')); ?>"
                                           placeholder="e.g. 55&quot; Samsung Display"
                                           maxlength="500">
                                    <?php $__errorArgs = ["equipment.{$i}.name"];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <p class="form-error" style="font-size:.75rem;"><?php echo e($message); ?></p>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                                </td>
                                <td style="width:150px;">
                                <select name="equipment[<?php echo e($i); ?>][category]" data-equip-category>
                                        <?php $__currentLoopData = $categoryOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <option value="<?php echo e($value); ?>" <?php echo e($selectedCategory === $value ? 'selected' : ''); ?>>
                                                <?php echo e($label); ?>

                                            </option>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </select>
                                </td>
                                <td class="col-area">
                                    <input type="text"
                                           name="equipment[<?php echo e($i); ?>][area]"
                                           value="<?php echo e(old("equipment.{$i}.area", $item['area'] ?? '')); ?>"
                                           placeholder="e.g. Meeting Room 1"
                                           maxlength="150"
                                           style="font-size:.82rem;">
                                </td>
                                <td class="col-del">
                                    <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                                </td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <tr data-room-row="1"><td colspan="6" style="height:6px;border:0;"></td></tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr data-empty-row="1">
                                <td colspan="6" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
                                    No items in this category yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>3. Work Activities</h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('activities-tbody', activityRowTemplate)">
                + Add Row
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <?php $__errorArgs = ['activities'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="form-error" style="padding:.75rem 1.25rem;"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th style="width:200px;">Activity Key</th>
                        <th>Activity Label / Description</th>
                        <th class="col-del"></th>
                    </tr>
                </thead>
                <tbody id="activities-tbody">
                    <?php $__currentLoopData = $reviewPayload['activities']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $activity): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td>
                                <input type="text"
                                       name="activities[<?php echo e($i); ?>][key]"
                                       value="<?php echo e(old("activities.{$i}.key", $activity['key'])); ?>"
                                       placeholder="e.g. display_installation"
                                       maxlength="100"
                                       style="font-family:monospace;font-size:.8rem;">
                            </td>
                            <td>
                                <input type="text"
                                       name="activities[<?php echo e($i); ?>][label]"
                                       value="<?php echo e(old("activities.{$i}.label", $activity['label'])); ?>"
                                       placeholder="e.g. Display & Screen Installation"
                                       maxlength="255">
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    </div>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>4. Hazards</h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="addRow('hazards-tbody', hazardRowTemplate)">
                + Add Row
            </button>
        </div>
        <div class="review-section-body" style="padding:0;overflow:hidden;">
            <p style="padding:.75rem 1.25rem;font-size:.8125rem;color:var(--text-muted);border-bottom:1px solid var(--border);margin:0;">
                Enter one control measure per line in the Control Measures column.
            </p>
            <table class="repeater-table">
                <thead>
                    <tr>
                        <th class="col-act">Activity</th>
                        <th>Hazard</th>
                        <th class="col-risk">Risk Level</th>
                        <th>Control Measures <span style="font-weight:400;font-size:.75rem;">(one per line)</span></th>
                        <th class="col-del"></th>
                    </tr>
                </thead>
                <tbody id="hazards-tbody">
                    <?php $__currentLoopData = $reviewPayload['hazards']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $hazard): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="col-act">
                                <input type="text"
                                       name="hazards[<?php echo e($i); ?>][activity_key]"
                                       value="<?php echo e(old("hazards.{$i}.activity_key", $hazard['activity_key'])); ?>"
                                       placeholder="optional"
                                       maxlength="100"
                                       style="font-family:monospace;font-size:.78rem;">
                            </td>
                            <td>
                                <input type="text"
                                       name="hazards[<?php echo e($i); ?>][hazard]"
                                       value="<?php echo e(old("hazards.{$i}.hazard", $hazard['hazard'])); ?>"
                                       placeholder="e.g. Working at Height"
                                       maxlength="500">
                            </td>
                            <td class="col-risk">
                                <select name="hazards[<?php echo e($i); ?>][risk]">
                                    <?php $__currentLoopData = ['Low', 'Medium', 'High']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $level): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <option value="<?php echo e($level); ?>"
                                                <?php echo e(old("hazards.{$i}.risk", $hazard['risk']) === $level ? 'selected' : ''); ?>>
                                            <?php echo e($level); ?>

                                        </option>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                </select>
                            </td>
                            <td>
                                <textarea name="hazards[<?php echo e($i); ?>][control_measures]"
                                          rows="3"
                                          placeholder="Enter each control measure on a new line…"><?php echo e(old("hazards.{$i}.control_measures", implode("\n", $hazard['control_measures']))); ?></textarea>
                            </td>
                            <td class="col-del">
                                <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    </div>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>5. PPE Required</h2>
        </div>
        <div class="review-section-body">
            <?php $__errorArgs = ['ppe'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="form-error" style="margin-bottom:.75rem;"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            <div class="ppe-grid">
                <?php $__currentLoopData = $ppeOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ppeItem): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        // When old input exists (validation failure re-render), absent checkbox key = unchecked.
                        // When no old input (first render), use saved/extracted value.
                        $checked = session()->hasOldInput()
                            ? in_array($ppeItem, old('ppe', []), true)
                            : in_array($ppeItem, $reviewPayload['ppe'], true);
                    ?>
                    <label class="ppe-check">
                        <input type="checkbox" name="ppe[]" value="<?php echo e($ppeItem); ?>" <?php echo e($checked ? 'checked' : ''); ?>>
                        <?php echo e($ppeItem); ?>

                    </label>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>

            
            <?php $__currentLoopData = $reviewPayload['ppe']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ppeItem): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php if(! in_array($ppeItem, $ppeOptions, true)): ?>
                    <div style="margin-top:.75rem;display:flex;align-items:center;gap:.5rem;">
                        <label class="ppe-check" style="flex:1;">
                            <input type="checkbox" name="ppe[]" value="<?php echo e($ppeItem); ?>"
                                   <?php echo e((session()->hasOldInput() ? in_array($ppeItem, old('ppe', []), true) : in_array($ppeItem, $reviewPayload['ppe'], true)) ? 'checked' : ''); ?>>
                            <?php echo e($ppeItem); ?> <em style="font-size:.75rem;color:var(--text-muted);">(custom)</em>
                        </label>
                    </div>
                <?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>6. Access &amp; Site Constraints</h2>
        </div>
        <div class="review-section-body">
            <div class="access-grid">
                <?php
                    $accessFields = [
                        'ladders'          => 'Podium Steps / Ladders required',
                        'tower'            => 'Access Tower required',
                        'scissor_lift'     => 'Scissor Lift / MEWP required',
                        'out_of_hours'     => 'Out-of-hours working',
                        'live_environment' => 'Live / occupied environment',
                    ];
                ?>
                <?php $__currentLoopData = $accessFields; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $fieldKey => $fieldLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        // When old input exists (validation failure re-render), absent checkbox key = unchecked.
                        // When no old input (first render), use saved/extracted value.
                        $checked = session()->hasOldInput()
                            ? !empty(old("access.{$fieldKey}"))
                            : (bool) ($reviewPayload['access'][$fieldKey] ?? false);
                    ?>
                    <label class="ppe-check">
                        <input type="checkbox"
                               name="access[<?php echo e($fieldKey); ?>]"
                               value="1"
                               <?php echo e($checked ? 'checked' : ''); ?>>
                        <?php echo e($fieldLabel); ?>

                    </label>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>
    </div>

    
    <div class="review-section">
        <div class="review-section-header">
            <h2>7. Method Statement Notes</h2>
        </div>
        <div class="review-section-body">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="margin-bottom:.5rem;">
                    Additional scope notes or instructions for the AI method statement generator
                    <span style="color:var(--text-muted);font-weight:400;">(optional)</span>
                </label>
                <textarea name="method_statement_notes"
                          class="form-control"
                          rows="4"
                          maxlength="5000"
                          placeholder="e.g. All works to be carried out during school holiday period. Ceiling works in main hall require MEWP access…"><?php echo e(old('method_statement_notes', $reviewPayload['method_statement_notes'])); ?></textarea>
            </div>
        </div>
    </div>

    
    <div class="action-bar">
        <a href="<?php echo e(route('rams.index')); ?>" class="btn btn-ghost btn-sm">Cancel</a>

        
        <button type="button"
                id="btn-save-review"
                class="btn btn-outline">
            💾 Save Review
        </button>

        
        <button type="submit"
                id="btn-approve"
                class="btn btn-teal"
                onclick="return confirmApprove()">
            ✓ Approve
        </button>
    </div>

</form>


<form id="save-form" method="POST" action="<?php echo e(route('rams.quote-review.update', $rams)); ?>" style="display:none;">
    <?php echo csrf_field(); ?>
</form>

<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script>
// ─── Row counter (used for unique indices when adding new rows) ───────────────
let equipmentCount  = <?php echo e(count($reviewPayload['equipment'])); ?>;
let activityCount   = <?php echo e(count($reviewPayload['activities'])); ?>;
let hazardCount     = <?php echo e(count($reviewPayload['hazards'])); ?>;

// ─── Row templates ────────────────────────────────────────────────────────────
function equipmentRowTemplate(idx, category) {
    return `<tr data-equip-row="1">
        <td class="col-qty">
            <input type="number" name="equipment[${idx}][quantity]" value="1" min="1" max="999">
        </td>
        <td style="width:140px;">
            <input type="text" name="equipment[${idx}][part_number]" placeholder="e.g. YEA-MVC-S90"
                   maxlength="60" style="font-family:monospace;font-size:.82rem;text-transform:uppercase;"
                   oninput="this.value=this.value.toUpperCase()">
        </td>
        <td>
            <input type="text" name="equipment[${idx}][name]" placeholder="e.g. 55&quot; Display" maxlength="500">
        </td>
        <td style="width:150px;">
            <select name="equipment[${idx}][category]" data-equip-category>
                <option value="hardware" ${category === 'hardware' ? 'selected' : ''}>Hardware</option>
                <option value="cables" ${category === 'cables' ? 'selected' : ''}>Cables</option>
                <option value="consumables" ${category === 'consumables' ? 'selected' : ''}>Consumables</option>
                <option value="services" ${category === 'services' ? 'selected' : ''}>Services / Professional</option>
                <option value="option" ${category === 'option' ? 'selected' : ''}>Option (Optional Items)</option>
            </select>
        </td>
        <td class="col-area">
            <input type="text" name="equipment[${idx}][area]" placeholder="e.g. Meeting Room 1"
                   maxlength="150" style="font-size:.82rem;">
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

function activityRowTemplate(idx) {
    return `<tr>
        <td>
            <input type="text" name="activities[${idx}][key]" placeholder="e.g. display_installation" maxlength="100" style="font-family:monospace;font-size:.8rem;">
        </td>
        <td>
            <input type="text" name="activities[${idx}][label]" placeholder="e.g. Display &amp; Screen Installation" maxlength="255">
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

function hazardRowTemplate(idx) {
    return `<tr>
        <td class="col-act">
            <input type="text" name="hazards[${idx}][activity_key]" placeholder="optional" maxlength="100" style="font-family:monospace;font-size:.78rem;">
        </td>
        <td>
            <input type="text" name="hazards[${idx}][hazard]" placeholder="e.g. Working at Height" maxlength="500">
        </td>
        <td class="col-risk">
            <select name="hazards[${idx}][risk]">
                <option value="Low">Low</option>
                <option value="Medium" selected>Medium</option>
                <option value="High">High</option>
            </select>
        </td>
        <td>
            <textarea name="hazards[${idx}][control_measures]" rows="3" placeholder="Enter each control measure on a new line…"></textarea>
        </td>
        <td class="col-del">
            <button type="button" class="btn-remove" onclick="removeRow(this)" title="Remove">✕</button>
        </td>
    </tr>`;
}

// ─── Generic add / remove ─────────────────────────────────────────────────────
function addRow(tbodyId, templateFn, category) {
    const tbody = document.getElementById(tbodyId);
    // Use a timestamp-based index to guarantee uniqueness without caring about gaps.
    const idx = Date.now();
    const div = document.createElement('tbody');
    div.innerHTML = templateFn(idx, category || 'hardware');
    const row = div.firstElementChild;
    tbody.appendChild(row);
    ensureEquipmentEmptyState(tbody);
    // Focus first input in the new row
    const first = row.querySelector('input, textarea, select');
    if (first) first.focus();
}

function removeRow(btn) {
    const row = btn.closest('tr');
    if (row) {
        const tbody = row.closest('tbody');
        row.remove();
        if (tbody) ensureEquipmentEmptyState(tbody);
    }
}

// ─── Save Review ──────────────────────────────────────────────────────────────
// Serialise the main form and re-submit it via the hidden save form so we
// can POST to a different URL without duplicating all the field markup.
document.getElementById('btn-save-review').addEventListener('click', function () {
    const reviewForm = document.getElementById('review-form');
    const saveForm   = document.getElementById('save-form');

    // Copy all inputs from the review form into the save form as hidden fields.
    // Remove any previously cloned fields first.
    saveForm.querySelectorAll('[data-cloned]').forEach(el => el.remove());

    const data = new FormData(reviewForm);
    for (const [key, value] of data.entries()) {
        if (key === '_token') continue; // save-form already has its own CSRF token
        const hidden = document.createElement('input');
        hidden.type  = 'hidden';
        hidden.name  = key;
        hidden.value = value;
        hidden.setAttribute('data-cloned', '1');
        saveForm.appendChild(hidden);
    }

    saveForm.submit();
});

// ─── Move row when category changes ──────────────────────────────────────────
document.addEventListener('change', function (e) {
    if (!e.target.matches('select[data-equip-category]')) return;
    const select = e.target;
    const row = select.closest('tr');
    const category = select.value || 'hardware';
    const tbody = document.getElementById('equipment-tbody-' + category);
    const prevTbody = row ? row.closest('tbody') : null;
    if (row && tbody) {
        tbody.appendChild(row);
        if (prevTbody) ensureEquipmentEmptyState(prevTbody);
        ensureEquipmentEmptyState(tbody);
    }
});

// ─── Empty state handling for equipment categories ───────────────────────────
function ensureEquipmentEmptyState(tbody) {
    if (!tbody) return;
    const hasRows = tbody.querySelectorAll('tr[data-equip-row]').length > 0;
    let emptyRow = tbody.querySelector('tr[data-empty-row]');
    if (hasRows && emptyRow) {
        emptyRow.remove();
        return;
    }
    if (!hasRows && !emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.setAttribute('data-empty-row', '1');
        emptyRow.innerHTML = `<td colspan="6" style="color:#888;font-size:.82rem;padding:.75rem 1rem;">
            No items in this category yet.
        </td>`;
        tbody.appendChild(emptyRow);
    }
}

// Initialise empty state on load (handles categories with zero rows)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('tbody[id^="equipment-tbody-"]').forEach(ensureEquipmentEmptyState);
});

// ─── Approve confirmation ─────────────────────────────────────────────────────
function confirmApprove() {
    return confirm(
        'Approve this reviewed data?\n\n' +
        'Once approved, return to the project page and click Generate to build the RAMS document. ' +
        'You can still edit and re-approve at any time.'
    );
}
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/stcav/rams.21stcav.com/resources/views/rams/quote-review.blade.php ENDPATH**/ ?>