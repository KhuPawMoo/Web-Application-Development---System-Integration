<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://cdn.dummyjson.com data:; style-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'");

const CATALOG_URL = 'https://dummyjson.com/products?limit=0';
const PAGE_SIZE = 10;
const STORE_PAGE_SIZE = 12;
const LOW_STOCK_THRESHOLD = 10;

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|int $amount): string
{
    return '$' . number_format((float)$amount, 2, '.', ',');
}

function readable(string $value): string
{
    return ucwords(str_replace('-', ' ', $value));
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirectTo(array $query): never
{
    header('Location: index.php?' . http_build_query($query), true, 303);
    exit;
}

function catalogProducts(): array
{
    $curl = curl_init(CATALOG_URL);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the product catalog request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'NorthstarInventory/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);

    if ($body === false) {
        throw new RuntimeException('Could not load products from the catalog: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('The product catalog returned HTTP ' . $status . '.');
    }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!isset($data['products']) || !is_array($data['products'])) {
        throw new RuntimeException('The product catalog returned an invalid response.');
    }

    return array_values(array_filter($data['products'], static fn($product): bool =>
        is_array($product) && isset($product['id'], $product['title'], $product['price'], $product['stock'])
    ));
}

function orderDatabase(): PDO
{
    $configuredPath = getenv('ORDER_DB_PATH');
    $databasePath = $configuredPath !== false && $configuredPath !== ''
        ? $configuredPath
        : dirname(__DIR__) . '/system-integration-data/orders.sqlite';
    $directory = dirname($databasePath);

    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the order database directory. Set ORDER_DB_PATH to a writable location.');
    }

    $db = new PDO('sqlite:' . $databasePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_code TEXT NOT NULL UNIQUE,
            checkout_token TEXT NOT NULL UNIQUE,
            customer_name TEXT NOT NULL,
            customer_email TEXT NOT NULL,
            created_at TEXT NOT NULL,
            total REAL NOT NULL,
            status TEXT NOT NULL DEFAULT \'new\' CHECK (status IN (\'new\', \'reviewed\'))
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            thumbnail TEXT NOT NULL,
            unit_price REAL NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0)
        )'
    );

    return $db;
}

function stockStatus(array $product): array
{
    if ((int)$product['stock'] <= 0) {
        return ['label' => 'Out of stock', 'class' => 'out-stock'];
    }
    if ((int)$product['stock'] <= LOW_STOCK_THRESHOLD) {
        return ['label' => 'Low stock', 'class' => 'low-stock'];
    }

    return ['label' => 'In stock', 'class' => 'in-stock'];
}

function loadOrderItems(PDO $db, int $orderId): array
{
    $statement = $db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $statement->execute([$orderId]);
    return $statement->fetchAll();
}

function loadOrders(PDO $db): array
{
    return $db->query('SELECT * FROM orders ORDER BY created_at DESC, id DESC')->fetchAll();
}

$db = null;
$databaseError = '';
try {
    $db = orderDatabase();
} catch (Throwable $error) {
    error_log('Order database initialization failed: ' . $error->getMessage());
    $databaseError = 'Order storage is unavailable. Configure ORDER_DB_PATH to a writable SQLite database location.';
}

$catalogError = '';
try {
    $products = catalogProducts();
} catch (Throwable $error) {
    $products = [];
    $catalogError = $error->getMessage();
}
$productsById = [];
foreach ($products as $product) {
    $productsById[(int)$product['id']] = $product;
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
if (!isset($_SESSION['checkout_token'])) {
    $_SESSION['checkout_token'] = bin2hex(random_bytes(24));
}

$orders = [];
if ($db instanceof PDO) {
    try {
        $orders = loadOrders($db);
    } catch (PDOException $error) {
        $databaseError = 'Could not read customer orders: ' . $error->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals(csrfToken(), $submittedToken)) {
        flash('Your form expired. Please try again.', 'error');
        redirectTo(['view' => $_POST['view'] ?? 'admin']);
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $view = ($_POST['view'] ?? '') === 'store' ? 'store' : 'admin';
    if ($action === 'admin_login') {
        $configuredPassword = getenv('ADMIN_PASSWORD');
        $submittedPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($configuredPassword === false || strlen($configuredPassword) < 12) {
            flash('Admin sign-in is not configured. Set ADMIN_PASSWORD to a value at least 12 characters long.', 'error');
        } elseif (hash_equals($configuredPassword, $submittedPassword)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            flash('Admin sign-in successful.');
        } else {
            flash('Invalid admin password.', 'error');
        }
        redirectTo(['view' => 'admin']);
    }

    if ($action === 'admin_logout') {
        unset($_SESSION['admin_authenticated']);
        session_regenerate_id(true);
        flash('Signed out of the admin view.');
        redirectTo(['view' => 'store']);
    }

    if ($action === 'add_to_cart') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $product = $productId === false ? null : ($productsById[$productId] ?? null);
        if ($product === null || (int)$product['stock'] <= 0) {
            flash('That product is unavailable.', 'error');
        } else {
            $current = (int)($_SESSION['cart'][$productId] ?? 0);
            if ($current >= (int)$product['stock']) {
                flash('The cart already has the available stock for that product.', 'error');
            } else {
                $_SESSION['cart'][$productId] = $current + 1;
                flash((string)$product['title'] . ' added to your cart.');
            }
        }
        redirectTo(['view' => 'store', 'cart' => 1]);
    }

    if ($action === 'update_cart') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $quantity === false) {
            flash('Invalid cart update.', 'error');
        } elseif ($quantity <= 0) {
            unset($_SESSION['cart'][$productId]);
        } elseif (isset($productsById[$productId]) && $quantity <= (int)$productsById[$productId]['stock']) {
            $_SESSION['cart'][$productId] = $quantity;
        } else {
            flash('Requested quantity exceeds available stock.', 'error');
        }
        redirectTo(['view' => 'store', 'cart' => 1]);
    }

    if ($action === 'remove_from_cart') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId !== false) {
            unset($_SESSION['cart'][$productId]);
        }
        redirectTo(['view' => 'store', 'cart' => 1]);
    }

    if ($action === 'checkout') {
        $name = trim(is_string($_POST['customer_name'] ?? null) ? $_POST['customer_name'] : '');
        $email = trim(is_string($_POST['customer_email'] ?? null) ? $_POST['customer_email'] : '');
        if ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            flash('Enter a name (up to 100 characters) and a valid email address.', 'error');
            redirectTo(['view' => 'store', 'checkout' => 1]);
        }
        if ($db === null || $databaseError !== '') {
            flash($databaseError !== '' ? $databaseError : 'Order storage is unavailable.', 'error');
            redirectTo(['view' => 'store', 'checkout' => 1]);
        }
        $submittedCheckoutToken = $_POST['checkout_token'] ?? '';
        if (!is_string($submittedCheckoutToken) || !hash_equals($_SESSION['checkout_token'], $submittedCheckoutToken)) {
            flash('This checkout form has already been used or expired. Please start checkout again.', 'error');
            redirectTo(['view' => 'store', 'cart' => 1]);
        }
        if ($catalogError !== '') {
            flash('The product catalog is unavailable. Your purchase was not completed.', 'error');
            redirectTo(['view' => 'store', 'checkout' => 1]);
        }

        $cartItems = [];
        $total = 0.0;
        foreach ($_SESSION['cart'] as $rawProductId => $rawQuantity) {
            $productId = (int)$rawProductId;
            $quantity = (int)$rawQuantity;
            $product = $productsById[$productId] ?? null;
            if ($product === null || $quantity < 1 || $quantity > (int)$product['stock']) {
                flash('A product or quantity in your cart is no longer available. Review your cart and try again.', 'error');
                redirectTo(['view' => 'store', 'cart' => 1]);
            }
            $item = [
                'product_id' => $productId,
                'product_name' => (string)$product['title'],
                'thumbnail' => (string)($product['thumbnail'] ?? ''),
                'unit_price' => (float)$product['price'],
                'quantity' => $quantity,
            ];
            $cartItems[] = $item;
            $total += $item['unit_price'] * $quantity;
        }
        if ($cartItems === []) {
            flash('Your cart is empty. Add a product before checking out.', 'error');
            redirectTo(['view' => 'store']);
        }

        try {
            $existing = $db->prepare('SELECT order_code FROM orders WHERE checkout_token = ?');
            $existing->execute([$_SESSION['checkout_token']]);
            $existingCode = $existing->fetchColumn();
            if (is_string($existingCode)) {
                redirectTo(['view' => 'store', 'order' => $existingCode]);
            }

            $db->beginTransaction();
            $orderCode = 'ORD-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insertOrder = $db->prepare(
                'INSERT INTO orders (order_code, checkout_token, customer_name, customer_email, created_at, total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertOrder->execute([
                $orderCode,
                $_SESSION['checkout_token'],
                $name,
                $email,
                gmdate('c'),
                $total,
            ]);
            $orderId = (int)$db->lastInsertId();
            $insertItem = $db->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, thumbnail, unit_price, quantity)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($cartItems as $item) {
                $insertItem->execute([
                    $orderId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['thumbnail'],
                    $item['unit_price'],
                    $item['quantity'],
                ]);
            }
            $db->commit();
            $_SESSION['cart'] = [];
            $_SESSION['checkout_token'] = bin2hex(random_bytes(24));
            flash('Your demo order ' . $orderCode . ' was recorded. No payment was taken.');
            redirectTo(['view' => 'store', 'order' => $orderCode]);
        } catch (PDOException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('Could not save your order: ' . $error->getMessage(), 'error');
            redirectTo(['view' => 'store', 'checkout' => 1]);
        }
    }

    if ($action === 'mark_reviewed') {
        if (($_SESSION['admin_authenticated'] ?? false) !== true) {
            flash('Sign in to manage customer orders.', 'error');
            redirectTo(['view' => 'admin']);
        }
        $orderCode = is_string($_POST['order_code'] ?? null) ? $_POST['order_code'] : '';
        if ($db === null || $databaseError !== '') {
            flash($databaseError !== '' ? $databaseError : 'Order storage is unavailable.', 'error');
        } else {
            $statement = $db->prepare('UPDATE orders SET status = "reviewed" WHERE order_code = ?');
            $statement->execute([$orderCode]);
            flash($statement->rowCount() > 0 ? 'Order marked as reviewed.' : 'Order was already reviewed or could not be found.');
        }
        redirectTo(['view' => 'admin', 'section' => 'orders']);
    }

    flash('Unknown action.', 'error');
    redirectTo(['view' => $view]);
}

$view = ($_GET['view'] ?? 'admin') === 'store' ? 'store' : 'admin';
$isAdminAuthenticated = ($_SESSION['admin_authenticated'] ?? false) === true;
$adminPasswordConfigured = ($configuredPassword = getenv('ADMIN_PASSWORD')) !== false && strlen($configuredPassword) >= 12;
$showAdminDashboard = $view === 'admin' && $isAdminAuthenticated;
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$cart = [];
$cartTotal = 0.0;
foreach ($_SESSION['cart'] as $productId => $quantity) {
    $product = $productsById[(int)$productId] ?? null;
    if ($product !== null) {
        $cart[] = ['product' => $product, 'quantity' => (int)$quantity];
        $cartTotal += (float)$product['price'] * (int)$quantity;
    }
}

$selectedProductId = filter_var($_GET['product'] ?? null, FILTER_VALIDATE_INT);
$selectedProduct = $selectedProductId === false ? null : ($productsById[$selectedProductId] ?? null);
$query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$category = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
$stockFilter = is_string($_GET['stock'] ?? null) ? $_GET['stock'] : '';
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'title-asc';
$sortOptions = ['title-asc', 'price-asc', 'price-desc', 'stock-asc', 'rating-desc'];
if (!in_array($sort, $sortOptions, true)) {
    $sort = 'title-asc';
}
$storeSortOptions = ['title-asc', 'price-asc', 'price-desc', 'rating-desc'];
$storeSort = is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], $storeSortOptions, true)
    ? $_GET['sort']
    : 'title-asc';

$filteredProducts = array_values(array_filter($products, static function (array $product) use ($query, $category, $stockFilter, $view): bool {
    $searchable = strtolower(implode(' ', [
        (string)$product['title'],
        (string)($product['brand'] ?? ''),
        (string)($product['category'] ?? ''),
        $view === 'store' ? (string)($product['description'] ?? '') : '',
    ]));
    if ($query !== '' && !str_contains($searchable, strtolower($query))) {
        return false;
    }
    if ($category !== '' && ($product['category'] ?? '') !== $category) {
        return false;
    }
    $stock = (int)$product['stock'];
    return match ($stockFilter) {
        'in' => $stock > LOW_STOCK_THRESHOLD,
        'low' => $stock > 0 && $stock <= LOW_STOCK_THRESHOLD,
        'out' => $stock <= 0,
        default => true,
    };
}));

$sortBy = explode('-', $view === 'store' ? $storeSort : $sort)[0];
$sortOrder = str_ends_with($view === 'store' ? $storeSort : $sort, '-desc') ? -1 : 1;
usort($filteredProducts, static function (array $a, array $b) use ($sortBy, $sortOrder): int {
    $comparison = $sortBy === 'title'
        ? strcasecmp((string)$a['title'], (string)$b['title'])
        : ((float)($a[$sortBy] ?? 0) <=> (float)($b[$sortBy] ?? 0));
    return $comparison * $sortOrder;
});

$pageSize = $view === 'store' ? STORE_PAGE_SIZE : PAGE_SIZE;
$pageCount = max(1, (int)ceil(count($filteredProducts) / $pageSize));
$currentPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$currentPage = max(1, min($pageCount, $currentPage === false ? 1 : $currentPage));
$pageProducts = array_slice($filteredProducts, ($currentPage - 1) * $pageSize, $pageSize);
$categories = array_values(array_unique(array_filter(array_map(
    static fn(array $product): string => (string)($product['category'] ?? ''),
    $products
))));
sort($categories);
$totalInventoryValue = array_reduce(
    $products,
    static fn(float $sum, array $product): float => $sum + (float)$product['price'] * (int)$product['stock'],
    0.0
);
$lowStockCount = count(array_filter($products, static fn(array $product): bool =>
    (int)$product['stock'] > 0 && (int)$product['stock'] <= LOW_STOCK_THRESHOLD
));
$averageRating = $products === []
    ? 0.0
    : array_sum(array_map(static fn(array $product): float => (float)($product['rating'] ?? 0), $products)) / count($products);
$newOrderCount = count(array_filter($orders, static fn(array $order): bool => $order['status'] === 'new'));
$selectedOrderCode = is_string($_GET['order_detail'] ?? null) ? $_GET['order_detail'] : '';
$selectedOrder = null;
$selectedOrderItems = [];
if ($db instanceof PDO && $selectedOrderCode !== '') {
    $statement = $db->prepare('SELECT * FROM orders WHERE order_code = ?');
    $statement->execute([$selectedOrderCode]);
    $selectedOrder = $statement->fetch() ?: null;
    if ($selectedOrder !== null) {
        $selectedOrderItems = loadOrderItems($db, (int)$selectedOrder['id']);
    }
}
$successOrderCode = is_string($_GET['order'] ?? null) ? $_GET['order'] : '';
$successOrder = null;
if ($db instanceof PDO && $successOrderCode !== '') {
    $statement = $db->prepare('SELECT * FROM orders WHERE order_code = ?');
    $statement->execute([$successOrderCode]);
    $successOrder = $statement->fetch() ?: null;
}

function pageLink(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'index.php?' . http_build_query($query);
}

$cartQuantity = array_sum(array_column($cart, 'quantity'));
?>
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
  <?php endif; ?>

  <div class="main">
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

    <?php if (is_array($flashMessage)): ?>
      <div class="flash <?= e($flashMessage['type'] ?? 'success') ?>" role="<?= ($flashMessage['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage['message'] ?? '') ?></div>
    <?php endif; ?>

    <?php if ($view === 'admin' && !$isAdminAuthenticated): ?>
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
    <?php elseif ($view === 'admin'): ?>
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

      <section class="panel" id="inventory" aria-labelledby="inventory-title">
        <div class="panel-heading"><div><h2 id="inventory-title">Product inventory</h2><p>Search and review products in your catalog.</p></div><span class="count-badge"><?= count($filteredProducts) ?> products</span></div>
        <form class="filters" action="index.php" method="get" role="search">
          <input type="hidden" name="view" value="admin">
          <label class="search-wrap"><span class="search-icon" aria-hidden="true">⌕</span><input name="q" type="search" value="<?= e($query) ?>" placeholder="Search products..." aria-label="Search products"></label>
          <select name="category" aria-label="Filter by category"><option value="">All categories</option><?php foreach ($categories as $itemCategory): ?><option value="<?= e($itemCategory) ?>" <?= $category === $itemCategory ? 'selected' : '' ?>><?= e(readable($itemCategory)) ?></option><?php endforeach; ?></select>
          <select name="stock" aria-label="Filter by stock status">
            <option value="">All stock levels</option><option value="in" <?= $stockFilter === 'in' ? 'selected' : '' ?>>In stock</option><option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option><option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
          </select>
          <select name="sort" aria-label="Sort products">
            <option value="title-asc" <?= $sort === 'title-asc' ? 'selected' : '' ?>>Name: A to Z</option><option value="price-asc" <?= $sort === 'price-asc' ? 'selected' : '' ?>>Price: low to high</option><option value="price-desc" <?= $sort === 'price-desc' ? 'selected' : '' ?>>Price: high to low</option><option value="stock-asc" <?= $sort === 'stock-asc' ? 'selected' : '' ?>>Stock: low to high</option><option value="rating-desc" <?= $sort === 'rating-desc' ? 'selected' : '' ?>>Top rated</option>
          </select>
          <button class="primary" type="submit">Apply</button><a class="reset-link" href="<?= e(pageLink(['q' => null, 'category' => null, 'stock' => null, 'sort' => null, 'page' => null, 'product' => null])) ?>">Reset</a>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Product</th><th>Price</th><th>Stock</th><th>Status</th><th>Rating</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
              <?php if ($catalogError !== ''): ?><tr><td class="status-message error" colspan="6">Could not load the product catalog.</td></tr>
              <?php elseif ($pageProducts === []): ?><tr><td class="empty-state" colspan="6">No products match your filters.</td></tr>
              <?php else: foreach ($pageProducts as $product): $status = stockStatus($product); ?>
                <tr>
                  <td><div class="product-cell"><img class="product-thumb" src="<?= e($product['thumbnail'] ?? '') ?>" alt=""><div><div class="product-name"><?= e($product['title']) ?></div><div class="product-category"><?= e(readable((string)($product['category'] ?? ''))) ?></div></div></div></td>
                  <td class="price"><?= e(money((float)$product['price'])) ?></td><td class="stock-number"><?= (int)$product['stock'] ?></td><td><span class="status-pill <?= e($status['class']) ?>"><?= e($status['label']) ?></span></td><td class="rating"><span class="rating-star">★ </span><?= number_format((float)($product['rating'] ?? 0), 1) ?></td>
                  <td><a class="view-btn" href="<?= e(pageLink(['product' => (int)$product['id']])) ?>">View</a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="table-footer"><span>Showing <?= count($filteredProducts) ? (($currentPage - 1) * $pageSize + 1) . '–' . min($currentPage * $pageSize, count($filteredProducts)) . ' of ' . count($filteredProducts) : '0 products' ?></span><div class="pagination"><?php if ($currentPage > 1): ?><a href="<?= e(pageLink(['page' => $currentPage - 1])) ?>">Previous</a><?php else: ?><span>Previous</span><?php endif; ?><span>Page <?= $currentPage ?> of <?= $pageCount ?></span><?php if ($currentPage < $pageCount): ?><a href="<?= e(pageLink(['page' => $currentPage + 1])) ?>">Next</a><?php else: ?><span>Next</span><?php endif; ?></div></div>
      </section>

      <?php if ($selectedProduct !== null): ?>
      <section class="panel selected-product" aria-labelledby="selected-product-title">
        <div class="panel-heading"><div><h2 id="selected-product-title"><?= e($selectedProduct['title']) ?></h2><p><?= e(readable((string)($selectedProduct['category'] ?? ''))) ?></p></div><a class="close-link" href="<?= e(pageLink(['product' => null])) ?>">Close</a></div>
        <div class="detail-body"><p><?= e($selectedProduct['description'] ?? '') ?></p><p><strong>Price:</strong> <?= e(money((float)$selectedProduct['price'])) ?> &nbsp; <strong>Stock:</strong> <?= (int)$selectedProduct['stock'] ?> &nbsp; <strong>SKU:</strong> <?= e($selectedProduct['sku'] ?? '—') ?></p></div>
      </section>
      <?php endif; ?>

      <section class="panel" id="orders" aria-labelledby="orders-title">
        <div class="panel-heading"><div><h2 id="orders-title">Customer orders</h2><p>Purchases submitted through this PHP application.</p></div><span class="count-badge"><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?></span></div>
        <?php if ($databaseError !== ''): ?><p class="order-notice" role="alert"><?= e($databaseError) ?></p><?php endif; ?>
        <div class="order-table-wrap"><table class="order-table">
          <thead><tr><th>Order</th><th>Customer</th><th>Placed</th><th>Items</th><th>Total</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
            <?php if ($orders === []): ?><tr><td class="status-message" colspan="7">No customer orders yet. Completed purchases will appear here.</td></tr>
            <?php else: foreach ($orders as $order): $orderItems = $db instanceof PDO ? loadOrderItems($db, (int)$order['id']) : []; ?>
              <tr><td class="order-id"><?= e($order['order_code']) ?></td><td class="order-customer"><?= e($order['customer_name']) ?><small><?= e($order['customer_email']) ?></small></td><td><?= e(date('M j, Y H:i', strtotime($order['created_at']))) ?></td><td><?= array_sum(array_column($orderItems, 'quantity')) ?> items</td><td class="price"><?= e(money((float)$order['total'])) ?></td><td><span class="order-status <?= e($order['status']) ?>"><?= e(ucfirst($order['status'])) ?></span></td><td><a class="view-btn" href="<?= e(pageLink(['order_detail' => $order['order_code']])) ?>">View</a></td></tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
        <p class="admin-note">Orders are saved in the server-side SQLite database and are visible to other users of this PHP app.</p>
      </section>

      <?php if ($selectedOrder !== null): ?>
      <section class="panel selected-product" id="order-detail" aria-labelledby="order-detail-title">
        <div class="panel-heading"><div><h2 id="order-detail-title">Order <?= e($selectedOrder['order_code']) ?></h2><p><?= e($selectedOrder['customer_name']) ?> · <?= e($selectedOrder['customer_email']) ?> · <?= e(date('M j, Y H:i', strtotime($selectedOrder['created_at']))) ?></p></div><a class="close-link" href="<?= e(pageLink(['order_detail' => null])) ?>">Close</a></div>
        <div class="detail-body">
          <div class="order-items"><?php foreach ($selectedOrderItems as $item): ?><div class="order-item"><span><?= e($item['product_name']) ?><small><?= (int)$item['quantity'] ?> × <?= e(money((float)$item['unit_price'])) ?></small></span><strong><?= e(money((int)$item['quantity'] * (float)$item['unit_price'])) ?></strong></div><?php endforeach; ?></div>
          <p><strong>Total: <?= e(money((float)$selectedOrder['total'])) ?></strong> · <span class="order-status <?= e($selectedOrder['status']) ?>"><?= e(ucfirst($selectedOrder['status'])) ?></span></p>
          <?php if ($selectedOrder['status'] === 'new'): ?><form method="post" action="index.php"><input type="hidden" name="action" value="mark_reviewed"><input type="hidden" name="view" value="admin"><input type="hidden" name="order_code" value="<?= e($selectedOrder['order_code']) ?>"><?= csrfField() ?><button class="primary" type="submit">Mark reviewed</button></form><?php endif; ?>
        </div>
      </section>
      <?php endif; ?>
    </main>
    <?php else: ?>
    <main class="storefront" id="storefront">
      <section class="store-heading"><div><h1>Find your next favorite</h1><p class="subtitle">Explore our collection of everyday essentials and standout finds.</p></div><span class="store-count"><?= count($filteredProducts) ?> products</span></section>
      <?php if ($catalogError !== ''): ?><p class="order-notice" role="alert"><?= e($catalogError) ?></p><?php endif; ?>
      <?php if (is_array($flashMessage) && ($flashMessage['type'] ?? '') === 'error'): ?><p class="order-notice" role="alert"><?= e($flashMessage['message']) ?></p><?php endif; ?>

      <?php if ($successOrder !== null): ?><section class="panel checkout-confirmation"><span class="confirmation-icon" aria-hidden="true">✓</span><h2>Order confirmed</h2><p><?= e($flashMessage['message'] ?? ('Order ' . $successOrder['order_code'] . ' is recorded for admin review.')) ?></p><p>Order reference: <strong><?= e($successOrder['order_code']) ?></strong>. No payment was taken.</p></section><?php endif; ?>

      <?php if ($selectedProduct !== null): ?>
      <section class="panel selected-product" aria-labelledby="selected-product-title">
        <div class="panel-heading"><div><h2 id="selected-product-title"><?= e($selectedProduct['title']) ?></h2><p><?= e(readable((string)($selectedProduct['category'] ?? ''))) ?><?= !empty($selectedProduct['brand']) ? ' · ' . e($selectedProduct['brand']) : '' ?></p></div><a class="close-link" href="<?= e(pageLink(['product' => null])) ?>">Close</a></div>
        <div class="store-detail"><img class="detail-img" src="<?= e($selectedProduct['images'][0] ?? $selectedProduct['thumbnail'] ?? '') ?>" alt="<?= e($selectedProduct['title']) ?>"><div><p><?= e($selectedProduct['description'] ?? '') ?></p><p class="store-price"><?= e(money((float)$selectedProduct['price'])) ?> <span class="store-rating"><span>★</span> <?= number_format((float)($selectedProduct['rating'] ?? 0), 1) ?></span></p><p class="<?= (int)$selectedProduct['stock'] > 0 ? 'availability' : 'availability unavailable' ?>"><?= (int)$selectedProduct['stock'] > 0 ? 'In stock' : 'Currently unavailable' ?></p><?php if ((int)$selectedProduct['stock'] > 0): ?><form method="post" action="index.php"><input type="hidden" name="action" value="add_to_cart"><input type="hidden" name="view" value="store"><input type="hidden" name="product_id" value="<?= (int)$selectedProduct['id'] ?>"><?= csrfField() ?><button class="add-button" type="submit">Add to cart</button></form><?php endif; ?></div></div>
      </section>
      <?php endif; ?>

      <?php if (isset($_GET['checkout'])): ?>
      <section class="panel checkout-panel" aria-labelledby="checkout-title"><div class="panel-heading"><div><h2 id="checkout-title">Checkout</h2><p>Demo purchase only. No payment is processed.</p></div></div>
        <?php if ($cart === []): ?><div class="cart-empty">Your cart is empty. <a href="<?= e(pageLink(['checkout' => null, 'cart' => null])) ?>">Browse products</a>.</div>
        <?php else: ?><div class="checkout-panel-body"><div class="order-items"><?php foreach ($cart as $entry): ?><div class="order-item"><span><?= e($entry['product']['title']) ?><small><?= (int)$entry['quantity'] ?> × <?= e(money((float)$entry['product']['price'])) ?></small></span><strong><?= e(money((int)$entry['quantity'] * (float)$entry['product']['price'])) ?></strong></div><?php endforeach; ?></div><p class="checkout-total"><span>Order total</span><strong><?= e(money($cartTotal)) ?></strong></p><form class="checkout-form" method="post" action="index.php"><input type="hidden" name="action" value="checkout"><input type="hidden" name="view" value="store"><input type="hidden" name="checkout_token" value="<?= e($_SESSION['checkout_token']) ?>"><?= csrfField() ?><label for="customer-name">Name<input id="customer-name" name="customer_name" autocomplete="name" required maxlength="100"></label><label for="customer-email">Email<input id="customer-email" name="customer_email" type="email" autocomplete="email" required maxlength="254"></label><p class="checkout-note">Your order and contact details are saved on this server for admin review. No payment is taken.</p><button class="checkout-button" type="submit">Place demo order</button></form></div><?php endif; ?>
      </section>
      <?php elseif (isset($_GET['cart'])): ?>
      <section class="panel cart-panel" aria-labelledby="cart-title"><div class="panel-heading"><div><h2 id="cart-title">Your cart</h2><p><?= $cartQuantity ?> items</p></div><a class="close-link" href="<?= e(pageLink(['cart' => null])) ?>">Continue shopping</a></div>
        <?php if ($cart === []): ?><div class="cart-empty">Your cart is empty. Browse the products below to add items.</div>
        <?php else: ?><div class="cart-items"><?php foreach ($cart as $entry): $product = $entry['product']; $quantity = $entry['quantity']; ?>
          <article class="cart-item"><img src="<?= e($product['thumbnail'] ?? '') ?>" alt=""><div class="cart-item-info"><strong><?= e($product['title']) ?></strong><small><?= e(money((float)$product['price'])) ?> each</small><strong><?= e(money($quantity * (float)$product['price'])) ?></strong></div>
            <div class="cart-controls"><form method="post" action="index.php"><input type="hidden" name="action" value="update_cart"><input type="hidden" name="view" value="store"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><input type="hidden" name="quantity" value="<?= $quantity - 1 ?>"><?= csrfField() ?><button type="submit" aria-label="Decrease quantity">−</button></form><span><?= $quantity ?></span><form method="post" action="index.php"><input type="hidden" name="action" value="update_cart"><input type="hidden" name="view" value="store"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><input type="hidden" name="quantity" value="<?= $quantity + 1 ?>"><?= csrfField() ?><button type="submit" aria-label="Increase quantity" <?= $quantity >= (int)$product['stock'] ? 'disabled' : '' ?>>+</button></form>
              <form method="post" action="index.php"><input type="hidden" name="action" value="remove_from_cart"><input type="hidden" name="view" value="store"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><?= csrfField() ?><button class="remove-item" type="submit">Remove</button></form></div>
          </article>
        <?php endforeach; ?></div><div class="cart-summary"><div class="summary-row total"><span>Subtotal</span><strong><?= e(money($cartTotal)) ?></strong></div></div><div class="cart-footer"><a class="close-link" href="<?= e(pageLink(['cart' => null])) ?>">Continue shopping</a><a class="checkout-button" href="<?= e(pageLink(['checkout' => 1, 'cart' => null])) ?>">Checkout</a></div><?php endif; ?>
      </section>
      <?php endif; ?>

      <form class="store-controls" method="get" action="index.php" role="search">
        <input type="hidden" name="view" value="store">
        <input name="q" type="search" value="<?= e($query) ?>" placeholder="Search the collection..." aria-label="Search the collection">
        <select name="category" aria-label="Filter by category"><option value="">All categories</option><?php foreach ($categories as $itemCategory): ?><option value="<?= e($itemCategory) ?>" <?= $category === $itemCategory ? 'selected' : '' ?>><?= e(readable($itemCategory)) ?></option><?php endforeach; ?></select>
        <select name="sort" aria-label="Sort products"><option value="title-asc" <?= $storeSort === 'title-asc' ? 'selected' : '' ?>>Featured: A to Z</option><option value="price-asc" <?= $storeSort === 'price-asc' ? 'selected' : '' ?>>Price: low to high</option><option value="price-desc" <?= $storeSort === 'price-desc' ? 'selected' : '' ?>>Price: high to low</option><option value="rating-desc" <?= $storeSort === 'rating-desc' ? 'selected' : '' ?>>Top rated</option></select>
        <button class="primary" type="submit">Apply</button><a class="reset-link" href="<?= e(pageLink(['q' => null, 'category' => null, 'sort' => null, 'page' => null, 'product' => null])) ?>">Reset</a>
      </form>

      <?php if ($catalogError !== ''): ?><section class="store-grid"><div class="store-empty error">Products could not be loaded. Please try again later.</div></section>
      <?php elseif ($pageProducts === []): ?><section class="store-grid"><div class="store-empty">No products match your search. Try another term or category.</div></section>
      <?php else: ?><section class="store-grid" aria-label="Products"><?php foreach ($pageProducts as $product): ?>
        <article class="store-card"><div class="store-image-wrap"><img class="store-image" src="<?= e($product['thumbnail'] ?? '') ?>" alt="<?= e($product['title']) ?>" loading="lazy"><span class="store-tag"><?= e(readable((string)($product['category'] ?? ''))) ?></span></div>
          <div class="store-card-body"><h2 class="store-name"><?= e($product['title']) ?></h2><div class="store-meta"><span class="store-price"><?= e(money((float)$product['price'])) ?></span><span class="store-rating"><span>★</span> <?= number_format((float)($product['rating'] ?? 0), 1) ?></span></div><div class="store-card-footer"><span class="<?= (int)$product['stock'] > 0 ? 'availability' : 'availability unavailable' ?>"><?= (int)$product['stock'] > 0 ? 'In stock' : 'Unavailable' ?></span><div class="store-card-actions"><a class="store-details" href="<?= e(pageLink(['product' => (int)$product['id']])) ?>">View details</a><?php if ((int)$product['stock'] > 0): ?><form method="post" action="index.php"><input type="hidden" name="action" value="add_to_cart"><input type="hidden" name="view" value="store"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><?= csrfField() ?><button class="add-button" type="submit">Add to cart</button></form><?php endif; ?></div></div></div>
        </article>
      <?php endforeach; ?></section><?php endif; ?>

      <div class="store-pagination"><?php if ($currentPage > 1): ?><a href="<?= e(pageLink(['page' => $currentPage - 1])) ?>">Previous</a><?php else: ?><span>Previous</span><?php endif; ?><span>Page <?= $currentPage ?> of <?= $pageCount ?></span><?php if ($currentPage < $pageCount): ?><a href="<?= e(pageLink(['page' => $currentPage + 1])) ?>">Next</a><?php else: ?><span>Next</span><?php endif; ?></div>
    </main>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
