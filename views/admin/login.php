<main class="content">
  <section class="panel login-panel" aria-labelledby="admin-login-title">
    <div class="panel-heading"><div><h1 id="admin-login-title">Admin sign in</h1><p>Sign in to view inventory and customer orders.</p></div></div>
    <div class="login-panel-body">
      <?php if (!$adminPasswordConfigured): ?><p class="order-notice" role="alert">Admin access is disabled until the server's <code>ADMIN_PASSWORD</code> environment variable is set to at least 12 characters.</p><?php endif; ?>
      <form class="checkout-form" method="post" action="index.php"><input type="hidden" name="action" value="admin_login"><input type="hidden" name="view" value="admin"><?= csrfField() ?><label for="admin-password">Admin password<input id="admin-password" name="password" type="password" autocomplete="current-password" required <?= !$adminPasswordConfigured ? 'disabled' : '' ?>></label><button class="primary" type="submit" <?= !$adminPasswordConfigured ? 'disabled' : '' ?>>Sign in</button></form>
      <p class="admin-note">Customer orders are only available to signed-in admins on this PHP server.</p>
    </div>
  </section>
</main>
