<?php $__env->startSection('title', $project->name); ?>

<?php $__env->startSection('content'); ?>

<?php
    $colour     = $project->status_colour;
    $lifecycle  = \App\Models\Project::LIFECYCLE;
    $currentIdx = array_search($project->status, $lifecycle);
    $isAdmin    = auth()->user()?->isAdmin();
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo e($project->name); ?></h1>
        <p style="color:#666; font-size:.875rem; margin-top:.25rem;">
            <?php echo e($project->client_name); ?> &nbsp;·&nbsp; <?php echo e($project->site_address); ?>

            <?php if($project->ref): ?> &nbsp;·&nbsp; Ref: <strong><?php echo e($project->ref); ?></strong> <?php endif; ?>
        </p>
    </div>
    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a href="<?php echo e(route('projects.index')); ?>" class="btn btn-outline btn-sm">← Projects</a>
    </div>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>


<div class="card card-sm" style="margin-bottom:1.25rem; overflow:hidden; padding:1.25rem 1.5rem;">
    <div style="display:flex; align-items:center; gap:0; overflow-x:auto; padding-bottom:.25rem;">
        <?php $__currentLoopData = $lifecycle; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $step): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $stepLabel  = \App\Models\Project::STATUS_LABELS[$step];
                $stepColour = \App\Models\Project::STATUS_COLOURS[$step];
                $isActive   = $step === $project->status;
                $isPast     = $i < $currentIdx;
                $isFuture   = $i > $currentIdx;
            ?>
            <div style="display:flex; align-items:center; flex-shrink:0;">
                <div style="
                    padding:.3rem .75rem;
                    border-radius:3px;
                    font-size:.75rem;
                    font-weight:<?php echo e($isActive ? '700' : '500'); ?>;
                    background:<?php echo e($isActive ? $stepColour : ($isPast ? $stepColour.'22' : '#f4f6f8')); ?>;
                    color:<?php echo e($isActive ? '#fff' : ($isPast ? $stepColour : '#aaa')); ?>;
                    border:1px solid <?php echo e($isActive ? $stepColour : ($isPast ? $stepColour.'44' : '#ddd')); ?>;
                    white-space:nowrap;
                "><?php echo e($stepLabel); ?></div>
                <?php if(!$loop->last): ?>
                    <div style="width:18px; height:1px; background:#ddd; flex-shrink:0;"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
</div>


<div style="display:grid; grid-template-columns:1fr 320px; gap:1.25rem; align-items:start;">

    
    <div>

        
        <?php if(!$project->isArchived()): ?>
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">
                Lifecycle Action
            </h2>

            <div style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-start;">
                <?php if($nextStatus): ?>
                    <?php $nextLabel = \App\Models\Project::STATUS_LABELS[$nextStatus]; ?>
                    <form method="POST" action="<?php echo e(route('projects.transition', $project)); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="to_status" value="<?php echo e($nextStatus); ?>">
                        <button type="submit" class="btn btn-teal"
                                onclick="return confirm('Advance project to <?php echo e($nextLabel); ?>?')">
                            Advance → <?php echo e($nextLabel); ?>

                        </button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="<?php echo e(route('projects.archive', $project)); ?>"
                      style="display:flex; gap:.5rem; align-items:center;">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-outline btn-sm"
                            onclick="return confirm('Archive this project?')">
                        Archive
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if($project->canReopen()): ?>
        <div class="card card-sm" style="border-left:3px solid #fd7e14; margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem;">Reopen Project</h2>
            <form method="POST" action="<?php echo e(route('projects.reopen', $project)); ?>"
                  style="display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap; margin-top:.75rem;">
                <?php echo csrf_field(); ?>
                <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                    <label class="form-label" for="reopen_reason">Reason for Reopening <span class="req">*</span></label>
                    <input id="reopen_reason" name="reopen_reason" type="text"
                           class="form-control" placeholder="e.g. Customer requested additional works" required>
                </div>
                <button type="submit" class="btn btn-outline btn-sm" style="margin-bottom:0;">
                    Reopen
                </button>
            </form>
        </div>
        <?php endif; ?>

        
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Quote History
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        <?php echo e($project->projectQuotes->count()); ?>

                    </span>
                </h2>
                <?php
                    $latestRams = $project->ramsDocuments->sortByDesc('id')->first();
                ?>
                <div style="display:flex; gap:.5rem;">
                    <a href="<?php echo e(route('quote-import.create', ['project_id' => $project->id])); ?>" class="btn btn-teal btn-sm" style="font-size:.78rem;">
                        ↑ Upload New Quote
                    </a>
                    <?php if($latestRams): ?>
                        <a href="<?php echo e(route('rams.quote-review.show', $latestRams)); ?>"
                           class="btn btn-sm"
                           style="font-size:.78rem; background:#f6c343; color:#1a1a1a; border:1px solid #d8a62e;">
                            ✎ Edit Project Data
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($project->projectQuotes->isEmpty()): ?>
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No quotes uploaded yet.
                    <a href="<?php echo e(route('quote-import.create', ['project_id' => $project->id])); ?>" style="color:var(--teal);">Upload a quote PDF</a> to get started.
                </p>
            <?php else: ?>
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th style="width:50px;">Ver.</th>
                            <th>Original File</th>
                            <th>Quote Ref</th>
                            <th>Client</th>
                            <th style="white-space:nowrap;">Uploaded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $project->projectQuotes->sortByDesc('version_number'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pq): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td style="text-align:center; font-weight:700; color:#007B8A;">
                                v<?php echo e($pq->version_number); ?>

                            </td>
                            <td>
                                <span title="<?php echo e($pq->original_filename); ?>" style="font-family:monospace; font-size:.78rem;">
                                    <?php echo e(\Illuminate\Support\Str::limit($pq->original_filename, 45)); ?>

                                </span>
                            </td>
                            <td><?php echo e($pq->quote_reference ?? '—'); ?></td>
                            <td style="color:#666;"><?php echo e($pq->client_name ?? '—'); ?></td>
                            <td style="white-space:nowrap; color:#888;">
                                <?php echo e($pq->created_at->format('d M Y')); ?><br>
                                <small><?php echo e($pq->created_at->format('H:i')); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    RAMS Documents
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        <?php echo e($project->ramsDocuments->count()); ?>

                    </span>
                </h2>
                <?php
                    $latestPackage      = $project->latestPackage ?: $project->packages()->latest()->first();
                    $latestAwaitingRams = $project->ramsDocuments->where('status', \App\Models\RamsDocument::STATUS_AWAITING_REVIEW)->sortByDesc('id')->first();
                    $generatingRams     = $project->ramsDocuments->whereIn('status', [\App\Models\RamsDocument::STATUS_GENERATING, \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION])->first();
                    $hasCompletedRams   = $project->ramsDocuments->whereIn('status', [\App\Models\RamsDocument::STATUS_COMPLETED, \App\Models\RamsDocument::STATUS_FOR_REVIEW, \App\Models\RamsDocument::STATUS_DRAFT])->isNotEmpty();
                ?>
                <div style="display:flex; gap:.5rem; align-items:center;">
                    <?php if($latestAwaitingRams): ?>
                        
                        <a href="<?php echo e(route('rams.quote-review.show', $latestAwaitingRams)); ?>"
                           class="btn btn-teal btn-sm" style="font-size:.78rem;">
                            ✎ Review &amp; Generate
                        </a>
                    <?php elseif($generatingRams): ?>
                        
                        <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>
                    <?php elseif($hasCompletedRams): ?>
                        
                        <a href="<?php echo e(route('quote-import.create', ['project_id' => $project->id])); ?>"
                           class="btn btn-outline btn-sm" style="font-size:.78rem;">
                            + New Version
                        </a>
                    <?php elseif($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED): ?>
                        
                        <form method="POST" action="<?php echo e(route('rams.from-project', $project)); ?>" style="margin:0;">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="btn btn-teal btn-sm" style="font-size:.78rem;">
                                + Create RAMS
                            </button>
                        </form>
                    <?php elseif($latestPackage): ?>
                        <a href="<?php echo e(route('quote-import.review', $latestPackage)); ?>"
                           class="btn btn-outline btn-sm" style="font-size:.78rem;">Review Quote Data</a>
                    <?php else: ?>
                        <span style="font-size:.78rem; color:#888;">Upload quote in Quote History</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($project->ramsDocuments->isEmpty()): ?>
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No RAMS documents yet.
                    <?php if($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED): ?>
                        <form method="POST" action="<?php echo e(route('rams.from-project', $project)); ?>" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="link-button" style="color:var(--teal); background:none; border:0; padding:0; font-size:inherit; cursor:pointer;">
                                Create RAMS
                            </button>
                        </form>
                        from the reviewed project data.
                    <?php elseif($latestPackage): ?>
                        <a href="<?php echo e(route('quote-import.review', $latestPackage)); ?>" style="color:var(--teal);">Review quote data</a> to enable RAMS generation.
                    <?php else: ?>
                        Upload a quote in Quote History to enable RAMS generation.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <?php
                    $ramsSorted = $project->ramsDocuments->sortByDesc('created_at')->values();
                    $versionMap = $project->ramsDocuments
                        ->sortBy('created_at')
                        ->values()
                        ->mapWithKeys(fn($doc, $i) => [$doc->id => $i + 1]);
                ?>
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th style="width:70px;">Ver.</th>
                            <th>Project / Ref</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $ramsSorted; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rams): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php
                                $status      = $rams->status;
                                $sup         = $rams->isSuperseded();
                                $isPipeline  = $rams->isPipelineStatus();
                            ?>
                            <tr style="<?php echo e($sup ? 'opacity:.45;' : ''); ?>">
                                <td style="text-align:center; font-weight:700; color:#007B8A;">
                                    v<?php echo e($versionMap[$rams->id] ?? '—'); ?>

                                </td>
                                <td>
                                    <strong><?php echo e($rams->project_name ?: '—'); ?></strong>
                                    <?php if($rams->project_ref): ?>
                                        <br><small style="color:#888; font-size:.75rem;"><?php echo e($rams->project_ref); ?></small>
                                    <?php endif; ?>
                                    <?php if($sup): ?>
                                        <br><small style="color:#c0392b; font-size:.72rem;">Superseded</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($sup): ?>
                                        <span class="badge badge-grey">Superseded</span>
                                    <?php elseif($isPipeline): ?>
                                        <span class="badge <?php echo e($rams->statusBadgeClass()); ?>"><?php echo e($rams->statusLabel()); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-grey"><?php echo e($rams->statusLabel()); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap; color:#888;">
                                    <?php echo e($rams->created_at->format('d M Y')); ?><br>
                                    <small><?php echo e($rams->created_at->format('H:i')); ?></small>
                                </td>
                                <td>
                                    <div style="display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; <?php echo e($sup ? 'pointer-events:none;' : ''); ?>">

                                        <?php if($status === \App\Models\RamsDocument::STATUS_APPROVED): ?>
                                            
                                            <form method="POST" action="<?php echo e(route('rams.retry-generation', $rams)); ?>" style="margin:0;">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-teal btn-sm" style="font-size:.75rem;">▶ Generate</button>
                                            </form>

                                        <?php elseif(in_array($status, [
                                            \App\Models\RamsDocument::STATUS_UPLOADED,
                                            \App\Models\RamsDocument::STATUS_APPROVED_FOR_GENERATION,
                                            \App\Models\RamsDocument::STATUS_GENERATING,
                                        ], true)): ?>
                                            
                                            <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>

                                        <?php elseif($status === \App\Models\RamsDocument::STATUS_COMPLETED && $rams->filename): ?>
                                            
                                            <a href="<?php echo e(route('rams.download', $rams)); ?>"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                            <a href="<?php echo e(route('rams.download-pdf', $rams)); ?>"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                            <form method="POST" action="<?php echo e(route('rams.retry-generation', $rams)); ?>" style="margin:0;">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;"
                                                        onclick="return confirm('Rebuild the DOCX from the approved data?');">↺ Regen</button>
                                            </form>
                                        <?php elseif($status === \App\Models\RamsDocument::STATUS_FAILED): ?>
                                            
                                            <span style="font-size:.78rem; color:#991b1b; margin-right:.25rem;">⚠ Failed</span>
                                            <?php if(!empty($rams->reviewed_data)): ?>
                                                <form method="POST" action="<?php echo e(route('rams.retry-generation', $rams)); ?>" style="margin:0;">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">↺ Retry</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="<?php echo e(route('rams.retry-extraction', $rams)); ?>" style="margin:0;">
                                                    <?php echo csrf_field(); ?>
                                                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">↺ Retry</button>
                                                </form>
                                            <?php endif; ?>

                                        <?php elseif($rams->filename && in_array($status, [
                                            \App\Models\RamsDocument::STATUS_FOR_REVIEW,
                                            \App\Models\RamsDocument::STATUS_DRAFT,
                                        ], true)): ?>
                                            
                                            <a href="<?php echo e(route('rams.download', $rams)); ?>"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                            <a href="<?php echo e(route('rams.download-pdf', $rams)); ?>"
                                               class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                            <form method="POST" action="<?php echo e(route('rams.retry-generation', $rams)); ?>" style="margin:0;">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;"
                                                        onclick="return confirm('Rebuild the DOCX from the approved data?');">↺ Regen</button>
                                            </form>
                                        <?php endif; ?>

                                        
                                        <form method="POST"
                                              action="<?php echo e(route('rams.destroy', $rams)); ?>"
                                              onsubmit="return confirm('Delete this RAMS document? Admins can restore it later.');"
                                              style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <?php echo method_field('DELETE'); ?>
                                            <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        
        
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    O&amp;M Manuals
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        <?php echo e($project->omManuals->count()); ?>

                    </span>
                </h2>
                <?php
                    $latestPackage       = $project->latestPackage ?: $project->packages()->latest()->first();
                    $generatingOm        = $project->omManuals->where('status', \App\Models\OmManual::STATUS_GENERATING)->first();
                    $hasCompletedOm      = $project->omManuals->whereIn('status', [\App\Models\OmManual::STATUS_DRAFT, \App\Models\OmManual::STATUS_FINAL])->isNotEmpty();
                ?>
                <?php if($generatingOm): ?>
                    
                    <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>
                <?php elseif($hasCompletedOm): ?>
                    
                    <a href="<?php echo e(route('quote-import.create', ['project_id' => $project->id])); ?>"
                       class="btn btn-outline btn-sm" style="font-size:.78rem;">
                        + New Version
                    </a>
                <?php elseif($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED): ?>
                    <form method="POST" action="<?php echo e(route('om-manuals.generate-from-project', $project)); ?>" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-teal btn-sm" style="font-size:.78rem;">
                            + Create O&amp;M
                        </button>
                    </form>
                <?php elseif($latestPackage): ?>
                    <a href="<?php echo e(route('quote-import.review', $latestPackage)); ?>"
                       class="btn btn-outline btn-sm" style="font-size:.78rem;">
                        Review Quote Data
                    </a>
                <?php else: ?>
                    <span style="font-size:.78rem; color:#888;">Upload quote in Quote History</span>
                <?php endif; ?>
            </div>

            <?php if($project->omManuals->isEmpty()): ?>
                <p style="color:#888; font-size:.875rem; padding:1rem 1.25rem; margin:0;">
                    No O&amp;M manuals yet.
                    <?php if($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED): ?>
                        <form method="POST" action="<?php echo e(route('om-manuals.generate-from-project', $project)); ?>" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="link-button" style="color:var(--teal); background:none; border:0; padding:0; font-size:inherit; cursor:pointer;">
                                Create an O&amp;M manual
                            </button>
                        </form>
                    <?php elseif($latestPackage): ?>
                        <a href="<?php echo e(route('quote-import.review', $latestPackage)); ?>"
                           style="color:var(--teal);">Review quote data</a> to enable O&amp;M generation.
                    <?php else: ?>
                        Upload a quote in Quote History to enable O&amp;M generation.
                    <?php endif; ?>
                    for this project.
                </p>
            <?php else: ?>
                <table class="data-table" style="font-size:.84rem;">
                    <thead>
                        <tr>
                            <th>Manual</th>
                            <th>Status</th>
                            <th style="white-space:nowrap;">Created</th>
                            <th style="min-width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $project->omManuals->sortByDesc('created_at'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $manual): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td>
                                <strong><?php echo e($manual->project_name ?? 'O&M Manual #' . $manual->id); ?></strong>
                                <?php if($manual->project_ref): ?>
                                    <br><small style="color:#888; font-size:.75rem;"><?php echo e($manual->project_ref); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo e($manual->statusBadgeClass()); ?>" style="font-size:.75rem;">
                                    <?php echo e($manual->statusLabel()); ?>

                                </span>
                            </td>
                            <td style="color:#888; white-space:nowrap;">
                                <?php echo e($manual->created_at->format('d M Y')); ?><br>
                                <small><?php echo e($manual->created_at->format('H:i')); ?></small>
                            </td>
                            <td>
                                <div style="display:flex; gap:.35rem; flex-wrap:wrap; align-items:center;">

                                    <?php if($manual->status === \App\Models\OmManual::STATUS_GENERATING): ?>
                                        
                                        <span style="font-size:.78rem; color:#888; font-style:italic;">Processing…</span>

                                    <?php elseif($manual->status === \App\Models\OmManual::STATUS_FAILED): ?>
                                        
                                        <span style="font-size:.78rem; color:#991b1b; margin-right:.25rem;" title="<?php echo e($manual->error_message); ?>">⚠ Failed</span>
                                        <?php if(! empty($manual->extracted_data)): ?>
                                            <form method="POST" action="<?php echo e(route('om-manuals.retry-generation', $manual)); ?>" style="margin:0;">
                                                <?php echo csrf_field(); ?>
                                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;">↺ Retry</button>
                                            </form>
                                        <?php endif; ?>

                                    <?php elseif($manual->isGenerated()): ?>
                                        
                                        <a href="<?php echo e(route('om-manuals.download', $manual)); ?>"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ .docx</a>
                                        <a href="<?php echo e(route('om-manuals.download-pdf', $manual)); ?>"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">↓ PDF</a>
                                        <form method="POST" action="<?php echo e(route('om-manuals.retry-generation', $manual)); ?>" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.75rem;"
                                                    onclick="return confirm('Rebuild this O&amp;M manual from the existing data?');">↺ Regen</button>
                                        </form>

                                    <?php elseif($manual->status === \App\Models\OmManual::STATUS_EXTRACTED): ?>
                                        
                                        <a href="<?php echo e(route('om-manuals.edit', $manual)); ?>"
                                           class="btn btn-teal btn-sm" style="font-size:.75rem;">✎ Review</a>

                                    <?php else: ?>
                                        <a href="<?php echo e(route('om-manuals.edit', $manual)); ?>"
                                           class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                                    <?php endif; ?>

                                    
                                    <form method="POST"
                                          action="<?php echo e(route('om-manuals.destroy', $manual)); ?>"
                                          onsubmit="return confirm('Delete this O&amp;M manual?');"
                                          style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <?php echo method_field('DELETE'); ?>
                                        <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        
        <?php if($project->siteSurveys->isNotEmpty()): ?>
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Site Surveys
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        <?php echo e($project->siteSurveys->count()); ?>

                    </span>
                </h2>
            </div>
            <table class="data-table" style="font-size:.84rem;">
                <thead>
                    <tr>
                        <th>Survey</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $project->siteSurveys->sortByDesc('created_at'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $survey): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td><?php echo e($survey->name ?? 'Site Survey #' . $survey->id); ?></td>
                        <td><span class="badge badge-grey"><?php echo e(ucfirst($survey->status ?? 'draft')); ?></span></td>
                        <td style="color:#888; white-space:nowrap;"><?php echo e($survey->created_at->format('d M Y')); ?></td>
                        <td>
                            <a href="<?php echo e(route('site-surveys.show', $survey)); ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        
        <?php if($project->cableSchedules->isNotEmpty()): ?>
        <div class="card card-sm" style="margin-bottom:1.25rem; padding:0; overflow:hidden;">
            <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border);">
                <h2 class="section-heading" style="font-size:.9rem; margin:0;">
                    Cable Schedules
                    <span style="background:#f0f0f0; color:#666; font-size:.72rem; font-weight:600; padding:.1rem .45rem; border-radius:10px; margin-left:.35rem;">
                        <?php echo e($project->cableSchedules->count()); ?>

                    </span>
                </h2>
            </div>
            <table class="data-table" style="font-size:.84rem;">
                <thead>
                    <tr>
                        <th>Schedule</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $project->cableSchedules->sortByDesc('created_at'); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $cs): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td><?php echo e($cs->name ?? 'Cable Schedule #' . $cs->id); ?></td>
                        <td style="color:#888; white-space:nowrap;"><?php echo e($cs->created_at->format('d M Y')); ?></td>
                        <td>
                            <a href="<?php echo e(route('cable-schedules.edit', $cs)); ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">View</a>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        
        <div class="card card-sm">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Activity Log</h2>
            <?php if($project->activityLog->isEmpty()): ?>
                <p style="color:#888; font-size:.875rem;">No activity recorded yet.</p>
            <?php else: ?>
                <ul style="list-style:none; padding:0; margin:0;">
                    <?php $__currentLoopData = $project->activityLog->take(20); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $entry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li style="display:flex; gap:.75rem; padding:.55rem 0; border-bottom:1px solid #f0f0f0; font-size:.84rem;">
                        <span style="color:#888; white-space:nowrap; padding-top:1px; min-width:110px;">
                            <?php echo e($entry->created_at->format('d M Y H:i')); ?>

                        </span>
                        <span style="color:#333;"><?php echo e($entry->description); ?></span>
                    </li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>

    
    <div>
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Project Details</h2>
            <dl style="display:grid; grid-template-columns:auto 1fr; gap:.4rem .75rem; font-size:.85rem;">
                <dt style="color:#888; font-weight:600;">Status</dt>
                <dd>
                    <span class="badge"
                          style="background:<?php echo e($colour); ?>22; color:<?php echo e($colour); ?>;
                                 border:1px solid <?php echo e($colour); ?>44;
                                 padding:.15rem .5rem; border-radius:10px; font-size:.78rem;">
                        <?php echo e($project->status_label); ?>

                    </span>
                </dd>
                <dt style="color:#888; font-weight:600;">Ref</dt>
                <dd><?php echo e($project->ref ?? '—'); ?></dd>
                <dt style="color:#888; font-weight:600;">Client</dt>
                <dd><?php echo e($project->client_name); ?></dd>
                <dt style="color:#888; font-weight:600;">Site</dt>
                <dd><?php echo e($project->site_address); ?></dd>
                <?php if($project->works_description): ?>
                <dt style="color:#888; font-weight:600;">Scope</dt>
                <dd><?php echo e($project->works_description); ?></dd>
                <?php endif; ?>
                <?php if($project->notes): ?>
                <dt style="color:#888; font-weight:600;">Notes</dt>
                <dd style="color:#666;"><?php echo e($project->notes); ?></dd>
                <?php endif; ?>
                <dt style="color:#888; font-weight:600;">Created</dt>
                <dd><?php echo e($project->created_at->format('d M Y')); ?></dd>
                <?php if($project->reopened_at): ?>
                <dt style="color:#888; font-weight:600;">Reopened</dt>
                <dd><?php echo e($project->reopened_at->format('d M Y')); ?><br>
                    <span style="color:#666; font-size:.8rem;"><?php echo e($project->reopen_reason); ?></span></dd>
                <?php endif; ?>
            </dl>

            <div style="margin-top:1rem; padding-top:.75rem; border-top:1px solid #f0f0f0;">
                <a href="<?php echo e(route('projects.edit', $project)); ?>" class="btn btn-outline btn-sm">Edit Details</a>
            </div>
        </div>

        
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Documents</h2>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:.5rem;">
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">Quotes</div>
                    <div style="font-size:1.3rem; font-weight:700;"><?php echo e($project->projectQuotes->count()); ?></div>
                </div>
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">RAMS</div>
                    <div style="font-size:1.3rem; font-weight:700;"><?php echo e($project->ramsDocuments->count()); ?></div>
                </div>
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">O&amp;M</div>
                    <div style="font-size:1.3rem; font-weight:700;"><?php echo e($project->omManuals->count()); ?></div>
                </div>
                <div style="border:1px solid #dde; border-radius:4px; padding:.65rem .75rem; text-align:center;">
                    <div style="font-size:.72rem; font-weight:700; color:#007B8A; text-transform:uppercase; letter-spacing:.04em;">Surveys</div>
                    <div style="font-size:1.3rem; font-weight:700;"><?php echo e($project->siteSurveys->count()); ?></div>
                </div>
            </div>
        </div>

        
        <div class="card card-sm" style="margin-bottom:1.25rem;">
            <h2 class="section-heading" style="font-size:.9rem; margin-bottom:.75rem;">Quick Actions</h2>
            <div style="display:flex; flex-direction:column; gap:.5rem;">
                <?php $latestPackage = $project->latestPackage ?: $project->packages()->latest()->first(); ?>
                <?php if($latestPackage && $latestPackage->status === \App\Models\ProjectPackage::STATUS_REVIEWED): ?>
                    <form method="POST" action="<?php echo e(route('om-manuals.generate-from-project', $project)); ?>" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-outline btn-sm" style="text-align:center;">
                            + Create O&amp;M Manual
                        </button>
                    </form>
                <?php elseif($latestPackage): ?>
                    <a href="<?php echo e(route('quote-import.review', $latestPackage)); ?>"
                       class="btn btn-outline btn-sm" style="text-align:center;">
                        Review Quote Data
                    </a>
                <?php endif; ?>
            </div>
        </div>

        
        <?php if($project->isArchived()): ?>
        <div class="card card-sm" style="border-left:3px solid #c0392b;">
            <p style="font-size:.8rem; color:#666; margin-bottom:.5rem;">
                Permanently delete this project and all associated data.
            </p>
            <form method="POST" action="<?php echo e(route('projects.destroy', $project)); ?>">
                <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('Permanently delete project &quot;<?php echo e($project->name); ?>&quot;? This cannot be undone.')">
                    Delete Project
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/stcav/rams.21stcav.com/resources/views/projects/show.blade.php ENDPATH**/ ?>