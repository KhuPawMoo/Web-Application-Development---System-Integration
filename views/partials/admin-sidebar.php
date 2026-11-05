<aside class="sidebar" aria-label="Main navigation">
  <a class="brand" href="<?= e(pageLink(['view' => 'admin', 'section' => null])) ?>"><span class="brand-mark" aria-hidden="true">▦</span><span>Northstar Admin</span></a>
  <p class="nav-label">Workspace</p>
  <nav class="nav">
    <a href="#overview"><span class="nav-icon" aria-hidden="true">⌂</span>Dashboard</a>
    <a href="#inventory"><span class="nav-icon" aria-hidden="true">▤</span>Inventory</a>
    <a href="#orders"><span class="nav-icon" aria-hidden="true">▣</span>Orders <?php if ($newOrderCount > 0): ?><span class="cart-count"><?= $newOrderCount ?></span><?php endif; ?></a>
  </nav>
  <div class="sidebar-bottom">
    <div class="avatar" aria-hidden="true">AD</div>
    <div><div class="profile-name">Admin User</div><div class="profile-role">Administrator</div></div>
  </div>
</aside>
