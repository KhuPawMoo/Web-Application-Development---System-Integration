<main class="content" id="overview">
  <section class="page-heading" aria-labelledby="page-title">
    <div><h1 id="page-title">Inventory overview</h1><p class="subtitle">Monitor products, stock, catalog performance, and customer orders.</p></div>
    <div class="updated"><?= $catalogError === '' ? count($products) . ' products loaded' : 'Catalog unavailable' ?></div>
  </section>

  <?php if ($catalogError !== ''): ?><p class="order-notice" role="alert"><?= e($catalogError) ?></p><?php endif; ?>
  <?php if ($databaseError !== ''): ?><p class="order-notice" role="alert"><?= e($databaseError) ?></p><?php endif; ?>

  <form class="admin-logout" method="post" action="index.php"><input type="hidden" name="action" value="admin_logout"><input type="hidden" name="view" value="admin"><?= csrfField() ?><button class="close-button" type="submit">Sign out</button></form>
  <section class="stats" aria-label="Inventory summary">
    <article class="stat"><div class="stat-top"><span>Total products</span><span class="stat-icon" aria-hidden="true">▤</span></div><div class="stat-value"><?= count($products) ?></div><div class="stat-note">Across the full catalog</div></article>
    <article class="stat"><div class="stat-top"><span>Low stock items</span><span class="stat-icon" aria-hidden="true">!</span></div><div class="stat-value"><?= $lowStockCount ?></div><div class="stat-note alert">10 units or fewer</div></article>
    <article class="stat"><div class="stat-top"><span>Inventory value</span><span class="stat-icon" aria-hidden="true">$</span></div><div class="stat-value"><?= e(money($totalInventoryValue)) ?></div><div class="stat-note">Listed price × stock</div></article>
    <article class="stat"><div class="stat-top"><span>Average rating</span><span class="stat-icon" aria-hidden="true">★</span></div><div class="stat-value"><?= number_format($averageRating, 1) ?> / 5</div><div class="stat-note">Across all products</div></article>
    <article class="stat"><div class="stat-top"><span>New orders</span><span class="stat-icon" aria-hidden="true">▣</span></div><div class="stat-value"><?= $newOrderCount ?></div><div class="stat-note">Purchases awaiting review</div></article>
  </section>

  <?php require __DIR__ . '/inventory.php'; ?>
  <?php require __DIR__ . '/orders.php'; ?>
</main>
