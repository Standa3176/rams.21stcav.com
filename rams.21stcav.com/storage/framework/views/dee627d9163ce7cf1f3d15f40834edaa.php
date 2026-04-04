<?php $__env->startSection('title', 'Projects'); ?>

<?php $__env->startSection('content'); ?>


<?php if (isset($component)) { $__componentOriginal2ef17d73532546a811006559651047c4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2ef17d73532546a811006559651047c4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard.page-header','data' => ['title' => ''.e($showDeleted ? 'Projects — Deleted' : 'Projects').'','breadcrumb' => 'Operations Platform']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard.page-header'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => ''.e($showDeleted ? 'Projects — Deleted' : 'Projects').'','breadcrumb' => 'Operations Platform']); ?>
     <?php $__env->slot('actions', null, []); ?> 
        <?php if($isAdmin): ?>
            <?php if($showDeleted): ?>
                <a href="<?php echo e(route('projects.index')); ?>" class="btn btn-outline btn-sm">← Live Projects</a>
            <?php else: ?>
                <a href="<?php echo e(route('projects.index', ['show_deleted' => 1])); ?>" class="btn btn-outline btn-sm">🗑 View Deleted</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if(! $showDeleted): ?>
        <a href="<?php echo e(route('projects.create')); ?>" class="btn btn-teal btn-sm">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New Project
        </a>
        <?php endif; ?>
     <?php $__env->endSlot(); ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2ef17d73532546a811006559651047c4)): ?>
<?php $attributes = $__attributesOriginal2ef17d73532546a811006559651047c4; ?>
<?php unset($__attributesOriginal2ef17d73532546a811006559651047c4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2ef17d73532546a811006559651047c4)): ?>
<?php $component = $__componentOriginal2ef17d73532546a811006559651047c4; ?>
<?php unset($__componentOriginal2ef17d73532546a811006559651047c4); ?>
<?php endif; ?>


<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>


<div class="proj-filter-bar">

    
    <div class="proj-filter-tabs">
        <a href="<?php echo e(route('projects.index')); ?>"
           class="proj-filter-tab <?php echo e(!$status ? 'active' : ''); ?>">
            All
            <span class="proj-filter-tab__count"><?php echo e($statusCounts->sum()); ?></span>
        </a>
        <?php $__currentLoopData = \App\Models\Project::STATUS_LABELS; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php $count = $statusCounts->get($key, 0); ?>
            <?php if($count > 0): ?>
            <a href="<?php echo e(route('projects.index', ['status' => $key])); ?>"
               class="proj-filter-tab <?php echo e($status === $key ? 'active' : ''); ?>">
                <?php echo e($label); ?>

                <span class="proj-filter-tab__count"><?php echo e($count); ?></span>
            </a>
            <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <form method="GET" action="<?php echo e(route('projects.index')); ?>" class="proj-search-form">
        <?php if($status): ?>
            <input type="hidden" name="status" value="<?php echo e($status); ?>">
        <?php endif; ?>
        <div class="proj-search-input-wrap">
            <svg class="proj-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text"
                   name="search"
                   value="<?php echo e($search); ?>"
                   placeholder="Search name, client or ref…"
                   class="proj-search-input">
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if($search): ?>
            <a href="<?php echo e(route('projects.index', $status ? ['status' => $status] : [])); ?>"
               class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

</div>


<?php if($showDeleted): ?>

    
    <?php if($projects->isEmpty()): ?>
        <div class="alert alert-info">No deleted projects found.</div>
    <?php else: ?>
    <?php if (isset($component)) { $__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard.table-wrapper','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard.table-wrapper'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Ref</th>
                    <th>Deleted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php $__currentLoopData = $projects; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $project): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr style="opacity:.6;background:#fff8f8;">
                    <td><strong><?php echo e($project->name); ?></strong></td>
                    <td><?php echo e($project->client_name); ?></td>
                    <td><?php echo e($project->ref ?? '—'); ?></td>
                    <td style="font-size:.8rem;color:#9CA3AF;white-space:nowrap;"><?php echo e($project->deleted_at->format('d M Y H:i')); ?></td>
                    <td>
                        <div class="actions">
                            <form method="POST" action="<?php echo e(route('projects.restore', $project->id)); ?>" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn btn-outline btn-sm">↩ Restore</button>
                            </form>
                            <form method="POST" action="<?php echo e(route('projects.force-destroy', $project->id)); ?>" style="margin:0;"
                                  onsubmit="return confirm('Permanently delete project &quot;<?php echo e(addslashes($project->name)); ?>&quot;?\n\nThis CANNOT be undone.');">
                                <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                <button type="submit" class="btn btn-danger btn-sm">✕ Delete Forever</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
        <?php if($projects->hasPages()): ?>
         <?php $__env->slot('footer', null, []); ?> 
            <div class="pagination-wrap" style="margin:0;justify-content:flex-end;"><?php echo e($projects->links()); ?></div>
         <?php $__env->endSlot(); ?>
        <?php endif; ?>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616)): ?>
<?php $attributes = $__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616; ?>
<?php unset($__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616); ?>
<?php endif; ?>
<?php if (isset($__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616)): ?>
<?php $component = $__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616; ?>
<?php unset($__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616); ?>
<?php endif; ?>
    <?php endif; ?>

<?php elseif($projects->isEmpty()): ?>

    <?php if (isset($component)) { $__componentOriginal50f6691cb7e71446f1706a70a912a0e8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal50f6691cb7e71446f1706a70a912a0e8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard.empty-state','data' => ['title' => 'No projects found','message' => $search ? 'Try adjusting your search or filters.' : 'Create your first project to get started.','href' => ''.e(route('projects.create')).'','action' => 'Create Project']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard.empty-state'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'No projects found','message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($search ? 'Try adjusting your search or filters.' : 'Create your first project to get started.'),'href' => ''.e(route('projects.create')).'','action' => 'Create Project']); ?>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal50f6691cb7e71446f1706a70a912a0e8)): ?>
<?php $attributes = $__attributesOriginal50f6691cb7e71446f1706a70a912a0e8; ?>
<?php unset($__attributesOriginal50f6691cb7e71446f1706a70a912a0e8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal50f6691cb7e71446f1706a70a912a0e8)): ?>
<?php $component = $__componentOriginal50f6691cb7e71446f1706a70a912a0e8; ?>
<?php unset($__componentOriginal50f6691cb7e71446f1706a70a912a0e8); ?>
<?php endif; ?>

<?php else: ?>

    <?php if (isset($component)) { $__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard.table-wrapper','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard.table-wrapper'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Ref</th>
                    <th>Updated</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php $__currentLoopData = $projects; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $project): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr style="<?php echo e($project->isArchived() ? 'opacity:.5;' : ''); ?>">
                    <td>
                        <a href="<?php echo e(route('projects.show', $project)); ?>" style="font-weight:600;">
                            <?php echo e($project->name); ?>

                        </a>
                        <?php if($project->site_address): ?>
                            <div style="font-size:.78rem; color:#9CA3AF; margin-top:1px;">
                                <?php echo e(Str::limit($project->site_address, 60)); ?>

                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="color:#6B7280;"><?php echo e($project->client_name); ?></td>
                    <td>
                        <?php if (isset($component)) { $__componentOriginal41cda017b93539193a6609497ccd9474 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal41cda017b93539193a6609497ccd9474 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard.status-badge','data' => ['status' => $project->status]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard.status-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($project->status)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal41cda017b93539193a6609497ccd9474)): ?>
<?php $attributes = $__attributesOriginal41cda017b93539193a6609497ccd9474; ?>
<?php unset($__attributesOriginal41cda017b93539193a6609497ccd9474); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal41cda017b93539193a6609497ccd9474)): ?>
<?php $component = $__componentOriginal41cda017b93539193a6609497ccd9474; ?>
<?php unset($__componentOriginal41cda017b93539193a6609497ccd9474); ?>
<?php endif; ?>
                    </td>
                    <td style="font-size:.85rem; color:#9CA3AF;"><?php echo e($project->ref ?? '—'); ?></td>
                    <td style="font-size:.8rem; color:#9CA3AF; white-space:nowrap;">
                        <?php echo e($project->updated_at->diffForHumans()); ?>

                    </td>
                    <td style="text-align:right;">
                        <div class="actions" style="justify-content:flex-end;">
                            <a href="<?php echo e(route('projects.show', $project)); ?>" class="btn btn-outline btn-sm">View</a>
                            <form method="POST" action="<?php echo e(route('projects.destroy', $project->id)); ?>" style="margin:0;"
                                  onsubmit="return confirm('Delete project &quot;<?php echo e(addslashes($project->name)); ?>&quot;? Admins can restore it later.');">
                                <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                <button type="submit" class="btn btn-danger-outline btn-sm" title="Delete">✕</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>

        <?php if($projects->hasPages()): ?>
         <?php $__env->slot('footer', null, []); ?> 
            <div class="pagination-wrap" style="margin:0; justify-content:flex-end;">
                <?php echo e($projects->links()); ?>

            </div>
         <?php $__env->endSlot(); ?>
        <?php endif; ?>

     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616)): ?>
<?php $attributes = $__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616; ?>
<?php unset($__attributesOriginale49fbbd14fd22829fc2c9a2a3b13a616); ?>
<?php endif; ?>
<?php if (isset($__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616)): ?>
<?php $component = $__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616; ?>
<?php unset($__componentOriginale49fbbd14fd22829fc2c9a2a3b13a616); ?>
<?php endif; ?>

<?php endif; ?>

<style>
/* ── Filter bar ────────────────────────────────────────────────── */
.proj-filter-bar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.25rem;
}

/* Status tabs */
.proj-filter-tabs {
    display: flex;
    gap: .25rem;
    flex-wrap: wrap;
    align-items: center;
}
.proj-filter-tab {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .85rem;
    border-radius: 9999px;
    font-size: .8125rem;
    font-weight: 500;
    color: #6B7280;
    border: 1px solid #E5E7EB;
    background: #fff;
    text-decoration: none;
    transition: background .12s, border-color .12s, color .12s;
    white-space: nowrap;
}
.proj-filter-tab:hover {
    background: #EBF6F7;
    border-color: #C8E9EC;
    color: #178A95;
    text-decoration: none;
}
.proj-filter-tab.active {
    background: #178A95;
    border-color: #178A95;
    color: #fff;
}
.proj-filter-tab.active .proj-filter-tab__count { opacity: .75; }
.proj-filter-tab__count {
    font-size: .7rem;
    font-weight: 700;
    opacity: .65;
}

/* Search form */
.proj-search-form         { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.proj-search-input-wrap   { position: relative; }
.proj-search-icon         { position: absolute; left: .65rem; top: 50%; transform: translateY(-50%); color: #9CA3AF; pointer-events: none; }
.proj-search-input {
    padding: .4rem .75rem .4rem 2.1rem;
    border: 1px solid #D1D5DB;
    border-radius: 8px;
    font-size: .875rem;
    font-family: inherit;
    color: #1F2937;
    background: #fff;
    width: 260px;
    transition: border-color .15s, box-shadow .15s;
}
.proj-search-input:focus {
    outline: none;
    border-color: #178A95;
    box-shadow: 0 0 0 3px rgba(23,138,149,.15);
}

@media (max-width: 640px) {
    .proj-filter-bar      { flex-direction: column; }
    .proj-search-input    { width: 100%; }
    .proj-search-form     { width: 100%; }
}
</style>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/stcav/rams.21stcav.com/resources/views/projects/index.blade.php ENDPATH**/ ?>