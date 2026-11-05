<?php
declare(strict_types=1);

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
if (!in_array($sort, ['title-asc', 'price-asc', 'price-desc', 'stock-asc', 'rating-desc'], true)) {
    $sort = 'title-asc';
}
$storeSort = is_string($_GET['sort'] ?? null) &&
    in_array($_GET['sort'], ['title-asc', 'price-asc', 'price-desc', 'rating-desc'], true)
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

$activeSort = $view === 'store' ? $storeSort : $sort;
$sortBy = explode('-', $activeSort)[0];
$sortOrder = str_ends_with($activeSort, '-desc') ? -1 : 1;
usort($filteredProducts, static function (array $a, array $b) use ($sortBy, $sortOrder): int {
    $comparison = $sortBy === 'title'
        ? strcasecmp((string)$a['title'], (string)$b['title'])
        : ((float)($a[$sortBy] ?? 0) <=> (float)($b[$sortBy] ?? 0));

    return $comparison * $sortOrder;
});

$pageSize = $view === 'store' ? STORE_PAGE_SIZE : PAGE_SIZE;
$pageCount = max(1, (int)ceil(count($filteredProducts) / $pageSize));
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$currentPage = max(1, min($pageCount, $requestedPage === false ? 1 : $requestedPage));
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
$selectedOrder = $db instanceof PDO && $selectedOrderCode !== '' ? findOrder($db, $selectedOrderCode) : null;
$selectedOrderItems = $selectedOrder !== null && $db instanceof PDO
    ? loadOrderItems($db, (int)$selectedOrder['id'])
    : [];
$successOrderCode = is_string($_GET['order'] ?? null) ? $_GET['order'] : '';
$successOrder = $db instanceof PDO && $successOrderCode !== '' ? findOrder($db, $successOrderCode) : null;
$cartQuantity = array_sum(array_column($cart, 'quantity'));
