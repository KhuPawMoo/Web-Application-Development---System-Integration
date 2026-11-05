<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $view === 'admin' ? 'Inventory Dashboard' : 'Northstar Store' ?></title>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="app" data-view="<?= e($view) ?>" data-admin-authenticated="<?= $isAdminAuthenticated ? 'true' : 'false' ?>">
  <?php if ($showAdminDashboard): ?>
    <?php require __DIR__ . '/partials/admin-sidebar.php'; ?>
  <?php endif; ?>
  <div class="main">
    <?php require __DIR__ . '/partials/topbar.php'; ?>
    <?php if (is_array($flashMessage)): ?>
      <div class="flash <?= e($flashMessage['type'] ?? 'success') ?>" role="<?= ($flashMessage['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($view === 'admin' && !$isAdminAuthenticated): ?>
      <?php require __DIR__ . '/admin/login.php'; ?>
    <?php elseif ($view === 'admin'): ?>
      <?php require __DIR__ . '/admin/dashboard.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/storefront.php'; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
