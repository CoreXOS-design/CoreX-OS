<?php
    /* SIDEBAR_ROLE_HELPERS_2026 */
    $u = auth()->user();
    $effectiveRole = strtolower(trim((string)($u?->effectiveRole() ?? ($u->role ?? ""))));
    $effectiveBranchId = $u?->effectiveBranchId();

    $navIsAdmin = ($effectiveRole === "admin") || (bool)($u->is_admin ?? 0);
    $navIsBM = ($effectiveRole === "branch_manager");
    $navIsAgent = ($effectiveRole === "agent");
?>

<div :class="collapsed ? 'sidebar-collapsed' : ''" class="rounded-2xl bg-white/5 border border-white/10 p-4 text-white">
    <div class="text-xs uppercase tracking-wider text-white/70 mb-3"><span x-show="!collapsed" x-transition.opacity>Menu</span></div>

    <div class="mb-4 pb-4 border-b border-white/10">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0" x-show="!collapsed" x-transition.opacity>
                <div class="text-sm font-semibold text-white truncate"><?php echo e(Auth::user()->name); ?></div>
                <div class="text-xs text-white/70 truncate"><?php echo e(Auth::user()->email); ?></div>
            </div>

            <button type="button"
                class="shrink-0 inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 hover:bg-white/10 px-2 py-2"
                @click="collapsed = !collapsed"
                :aria-label="collapsed ? 'Expand menu' : 'Collapse menu'">
                <span class="text-white/80" x-text="collapsed ? '»' : '«'"></span>
            </button>
        </div>

    </div>

    <div class="space-y-1">
        <a href="<?php echo e(route('dashboard')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('dashboard') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Home</span></a>
        <a href="<?php echo e(route('worksheet.index')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('worksheet.*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Worksheet</span></a>
        <?php if(auth()->user()->can_capture_rentals || in_array(auth()->user()->role, ['admin','branch_manager'])): ?>
        <a href="<?php echo e(route('rentals.index')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('rentals.*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Rentals</span></a><?php endif; ?>

                  <a href="<?php echo e(route('ellie.index')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('ellie.index') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Ellie, Your AI Assistant</span></a>

        <a href="<?php echo e(route('agent.listings')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('agent.listings*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>My Listing Stock</span></a>

      <div class="mt-6">
          <div class="text-xs uppercase tracking-wider text-white/70 mb-2 sidebar-heading"><span x-show="!collapsed" x-transition.opacity>Tools</span></div>
          <div class="space-y-1">
              <a href="<?php echo e(route('tools.commission')); ?>"
                 class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e((request()->routeIs('tools.commission') && !request()->query('section')) ? 'bg-white/10' : ''); ?>">
                  Commission Calculator</a>

              <a href="<?php echo e(route('tools.cma')); ?>"
                 class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('tools.cma') ? 'bg-white/10' : ''); ?>">
                  CMA Certificate Generator</a>

              <a href="<?php echo e(route('tools.commission')); ?>?section=history"
                 class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e((request()->routeIs('tools.commission') && request()->query('section') === 'history') ? 'bg-white/10' : ''); ?>">
                  History & Logs</a>
</div>
      </div>

    </div>

    <?php if($navIsAgent): ?>
        <div class="mt-6">
            <div class="text-xs uppercase tracking-wider text-white/70 mb-2 sidebar-heading"><span x-show="!collapsed" x-transition.opacity>My Performance</span></div>
            <div class="space-y-1">
                <a href="<?php echo e(route('agent.dashboard')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('agent.dashboard') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Agent Dashboard</span></a>
                <a href="<?php echo e(route('agent.daily.summary')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('agent.daily.summary*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Daily Activity Summary</span></a>
                <a href="<?php echo e(route('agent.daily')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('agent.daily') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>My Daily Activity</span></a>
                <a href="<?php echo e(route('agent.deals.index')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('agent.deals.*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>My Deals</span></a>
            </div>
        </div>
    <?php endif; ?>

    <?php if($navIsBM): ?>
        <div class="mt-6">
            <div class="text-xs uppercase tracking-wider text-white/70 mb-2 sidebar-heading"><span x-show="!collapsed" x-transition.opacity>Branch</span></div>
            <div class="space-y-1">
                <a href="<?php echo e(route('bm.daily.summary')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('bm.daily.summary*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Daily Activity Summary</span></a>

                <a href="<?php echo e(route('bm.performance')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('bm.performance*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Branch Performance</span></a>

                <a href="<?php echo e(route('bm.listings')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('bm.listings*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Branch Listing Stock</span></a>
                <a href="<?php echo e(route('bm.my.dashboard')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('bm.my.dashboard') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>My Agent Dashboard</span></a>
                <a href="<?php echo e(route('admin.deals')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.deals*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Deal Register</span></a>
            </div>
        </div>

        <div class="mt-6">
            <div class="text-xs uppercase tracking-wider text-white/70 mb-2 sidebar-heading"><span x-show="!collapsed" x-transition.opacity>Setup</span></div>
            <div class="space-y-1">
                <a href="<?php echo e(route('bm.worksheet.market')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('bm.worksheet.market*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Worksheet Market</span></a>
                <a href="<?php echo e(route('admin.daily.summary')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.daily.summary*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Daily Activity Summary</span></a>

                <a href="<?php echo e(route('admin.targets')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.targets*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Daily Activity Targets</span></a>
                <a href="<?php echo e(route('admin.targets.activity.definitions')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.targets.activity.definitions*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Activity Definitions</span></a>
                <a href="<?php echo e(route('admin.targets.activity.setup')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.targets.activity.setup*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Activity Setup</span></a>
                  <a href="<?php echo e(route('bm.tv-messages')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('bm.tv-messages*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>TV Messages</span></a>
                <?php if($effectiveBranchId): ?>
                    <a href="<?php echo e(route('agent.daily')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('agent.daily') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Daily Activity Capture</span></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if($navIsAdmin): ?>
        <div class="mt-6">
            <div class="text-xs uppercase tracking-wider text-white/70 mb-2 sidebar-heading"><span x-show="!collapsed" x-transition.opacity>Admin</span></div>
            <div class="space-y-1">
                <a href="<?php echo e(route('admin.performance')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.performance*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Performance</span></a>
                <?php if(\Illuminate\Support\Facades\Route::has('admin.listings.stock')): ?>
                <a href="<?php echo e(route('admin.listings.stock')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.listings.stock*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Company Listing Stock</span></a>
                <?php endif; ?>
                <a href="<?php echo e(route('admin.performance-settings.edit')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.performance-settings*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Company Settings</span></a>
                <a href="<?php echo e(route('admin.designations.index')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.designations*') ? 'bg-white/10' : ''); ?>">
                    <span x-show="!collapsed" x-transition.opacity>Designations</span>
                </a>

                <a href="<?php echo e(route('admin.deals')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.deals*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Deal Register</span></a>

                <a href="<?php echo e(route('admin.listings.agents')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.listings.agents*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Listing Stock</span></a>
                <a href="<?php echo e(route('admin.listings.import')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.listings.import*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Import Listings</span></a>

                <a href="<?php echo e(route('admin.daily.summary')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.daily.summary*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Daily Activity Summary</span></a>

                <a href="<?php echo e(route('admin.targets')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.targets') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Targets (Daily Activity)</span></a>
                <a href="<?php echo e(route('admin.targets.activity.definitions')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.targets.activity.definitions*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Activity Definitions</span></a>
                <a href="<?php echo e(route('admin.targets.activity.setup')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.targets.activity.setup*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Activity Setup</span></a>
                <a href="<?php echo e(route('admin.worksheet-market')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.worksheet-market*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Worksheet Market</span></a>
                <a href="<?php echo e(route('admin.branch-assignments')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.branch-assignments') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Branch Assignments</span></a>
                <a href="<?php echo e(route('admin.users')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.users') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>Users</span></a>

                  <a href="<?php echo e(route('admin.tv-messages')); ?>" class="block px-3 py-2 rounded-lg text-white/90 hover:bg-white/10 <?php echo e(request()->routeIs('admin.tv-messages*') ? 'bg-white/10' : ''); ?>"><span class="inline-block w-2 h-2 rounded-full bg-white/50 mr-2 align-middle" x-show="collapsed"></span><span x-show="!collapsed" x-transition.opacity>TV Messages</span></a>
            </div>
        </div>
    <?php endif; ?>




<style>
/* SIDEBAR_COLLAPSE_CSS_2026 */
.sidebar-collapsed .sidebar-heading { display: none; }

/* Default link layout */
a { display: flex; align-items: center; }

/* COLLAPSED: hide ALL text nodes inside links (covers plain text + spans) */
.sidebar-collapsed a{
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0;       /* kills label text everywhere */
  line-height: 0;
}

/* Hide old dot spans (if any remain) */
.sidebar-collapsed a span[x-show="collapsed"]{
  display: none !important;
}

/* Keep icons visible (if present) */
.sidebar-collapsed a svg{
  width: 1rem;
  height: 1rem;
  display: inline-block !important;
}
</style>

</div><?php /**PATH C:\Users\USER-PC\Documents\Projects\New folder\hfc dash\hfc-dash\resources\views/layouts/sidebar.blade.php ENDPATH**/ ?>