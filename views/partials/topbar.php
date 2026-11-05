<div class="topbar">
  <div class="breadcrumb"><?= $view === 'admin' ? 'Workspace <span aria-hidden="true">/</span> <strong>Dashboard</strong>' : 'Store <span aria-hidden="true">/</span> <strong>Shop</strong>' ?></div>
  <div class="topbar-actions">
    <nav class="view-switch" aria-label="Choose a view">
      <a href="<?= e(pageLink(['view' => 'admin', 'cart' => null, 'checkout' => null, 'order' => null, 'product' => null])) ?>" aria-current="<?= $view === 'admin' ? 'page' : 'false' ?>">Admin</a>
      <a href="<?= e(pageLink(['view' => 'store', 'section' => null])) ?>" aria-current="<?= $view === 'store' ? 'page' : 'false' ?>">Storefront</a>
    </nav>
    <?php if ($showAdminDashboard): ?>
      <div class="topbar-right"><span class="live-dot" aria-hidden="true"></span>PHP inventory dashboard</div>
    <?php elseif ($view === 'store'): ?>
      <a class="cart-open" href="<?= e(pageLink(['view' => 'store', 'cart' => 1, 'checkout' => null, 'product' => null])) ?>">Cart <span class="cart-count"><?= $cartQuantity ?></span></a>
    <?php endif; ?>
  </div>
</div>
