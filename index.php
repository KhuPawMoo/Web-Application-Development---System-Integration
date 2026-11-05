<?php
declare(strict_types=1);

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/catalog.php';
require_once __DIR__ . '/app/orders.php';

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
        error_log('Could not read customer orders: ' . $error->getMessage());
        $databaseError = 'Could not read customer orders from the configured database.';
    }
}

require __DIR__ . '/app/actions.php';
require __DIR__ . '/app/page-data.php';
require __DIR__ . '/views/layout.php';
