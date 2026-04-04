
<?php if(auth()->guard()->check()): ?>
<style>
    /* ── Sidebar nav ────────────────────────────────────────────── */
    .sidebar-nav {
        display: flex;
        flex-direction: column;
        padding: 1rem 0 3rem;
    }

    .snav-label {
        padding: 1.25rem .875rem .3rem;
        font-size: .625rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: rgba(255,255,255,.3);
        white-space: nowrap;
        user-select: none;
    }

    .snav-link {
        position: relative;
        display: flex;
        align-items: center;
        gap: .625rem;
        padding: .5rem .875rem;
        margin: 0 .5rem;
        border-radius: 6px;
        color: rgba(255,255,255,.65);
        font-size: .875rem;
        font-weight: 400;
        text-decoration: none;
        transition: background 120ms ease, color 120ms ease;
        line-height: 1.3;
    }
    .snav-link:hover {
        background: rgba(255,255,255,.07);
        color: rgba(255,255,255,.95);
        text-decoration: none;
    }
    .snav-link.active {
        background: rgba(23,138,149,.22);
        color: #fff;
        font-weight: 500;
    }
    .snav-link.active .snav-icon { color: #5DD6DF; }
    .snav-link.active::before {
        content: '';
        position: absolute;
        left: -.5rem;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 60%;
        background: #178A95;
        border-radius: 0 3px 3px 0;
    }

    .snav-icon {
        flex-shrink: 0;
        color: rgba(255,255,255,.4);
        transition: color 120ms ease;
    }
    .snav-link:hover .snav-icon { color: rgba(255,255,255,.75); }

    .snav-sep {
        height: 1px;
        background: rgba(255,255,255,.07);
        margin: .625rem .875rem;
    }

    .snav-admin-dot {
        margin-left: auto;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #F5C542;
        flex-shrink: 0;
    }

    /* Admin-only nav styling (yellow) */
    .snav-label.admin-only { color: #F5C542; }
    .snav-link.admin-only { color: rgba(245,197,66,.9); }
    .snav-link.admin-only .snav-icon { color: rgba(245,197,66,.85); }
    .snav-link.admin-only:hover {
        color: #FFD976;
    }
    .snav-link.admin-only:hover .snav-icon {
        color: #FFD976;
    }
</style>

<nav class="sidebar-nav" aria-label="Main navigation">
    <?php $isAdmin = auth()->user()?->isAdmin(); ?>

    
    <div class="snav-label">Main</div>

    <?php if($isAdmin): ?>
    <a href="<?php echo e(route('dashboard')); ?>"
       class="snav-link <?php echo e(request()->routeIs('dashboard') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
        </svg>
        Dashboard
    </a>
    <?php endif; ?>

    <a href="<?php echo e(route('projects.index')); ?>"
       class="snav-link <?php echo e(request()->routeIs('projects.*') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
        Projects
    </a>

    <?php if($isAdmin): ?>
    <div class="snav-sep"></div>

    
    <div class="snav-label admin-only">Delivery Tools</div>

    <a href="<?php echo e(route('rams.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('rams.*') && ! request()->routeIs('rams.upload*') && ! request()->routeIs('rams.settings*') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        RAMS
    </a>

    <a href="<?php echo e(route('site-surveys.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('site-surveys.*') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        Site Surveys
    </a>

    <a href="<?php echo e(route('cable-schedules.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('cable-schedules.*') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="8" y1="6"  x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
            <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6"  x2="3.01" y2="6"/>
            <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
        </svg>
        Cable Schedules
    </a>

    <a href="<?php echo e(route('om-manuals.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('om-manuals.*') ? 'active' : ''); ?>"
       title="Operations &amp; Maintenance Manuals">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
        </svg>
        O&amp;M Manuals
    </a>

    <div class="snav-sep"></div>

    
    <div class="snav-label admin-only">Import</div>

    <a href="<?php echo e(route('quote-import.create')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('quote-import.*') ? 'active' : ''); ?>"
       title="Import a QuoteWerks PDF to generate a project package">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="16 16 12 12 8 16"/>
            <line x1="12" y1="12" x2="12" y2="21"/>
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
        </svg>
        Quote Import
    </a>
    <?php endif; ?>

    
    <?php if($isAdmin): ?>
    <div class="snav-sep"></div>
    <div class="snav-label admin-only">Admin</div>

    <a href="<?php echo e(route('admin.users.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('admin.users.*') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Users
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="<?php echo e(route('hazard-templates.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('hazard-templates.*') ? 'active' : ''); ?>">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Hazard Templates
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="<?php echo e(route('rams.settings')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('rams.settings*') ? 'active' : ''); ?>"
       title="AI Provider Settings">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.07 4.93A10 10 0 1 0 4.93 19.07"/><path d="M19.07 4.93L12 12"/>
        </svg>
        AI Settings
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>

    <a href="<?php echo e(route('admin.worker.index')); ?>"
       class="snav-link admin-only <?php echo e(request()->routeIs('admin.worker*') ? 'active' : ''); ?>"
       title="Queue Worker Monitor">
        <svg class="snav-icon" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
        </svg>
        Worker
        <span class="snav-admin-dot" aria-hidden="true"></span>
    </a>
    <?php endif; ?>

</nav>
<?php endif; ?>
<?php /**PATH /home/stcav/rams.21stcav.com/resources/views/layouts/navigation.blade.php ENDPATH**/ ?>