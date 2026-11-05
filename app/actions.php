<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

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
    if (!$db instanceof PDO || $databaseError !== '') {
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
        $orderCode = createOrder($db, $_SESSION['checkout_token'], $name, $email, $cartItems, $total);
        $_SESSION['cart'] = [];
        $_SESSION['checkout_token'] = bin2hex(random_bytes(24));
        flash('Your demo order ' . $orderCode . ' was recorded. No payment was taken.');
        redirectTo(['view' => 'store', 'order' => $orderCode]);
    } catch (Throwable $error) {
        error_log('Order creation failed: ' . $error->getMessage());
        flash('Could not save your order. Please try again.', 'error');
        redirectTo(['view' => 'store', 'checkout' => 1]);
    }
}

if ($action === 'mark_reviewed') {
    if (($_SESSION['admin_authenticated'] ?? false) !== true) {
        flash('Sign in to manage customer orders.', 'error');
        redirectTo(['view' => 'admin']);
    }
    $orderCode = is_string($_POST['order_code'] ?? null) ? $_POST['order_code'] : '';
    if (!$db instanceof PDO || $databaseError !== '') {
        flash($databaseError !== '' ? $databaseError : 'Order storage is unavailable.', 'error');
    } else {
        flash(markOrderReviewed($db, $orderCode)
            ? 'Order marked as reviewed.'
            : 'Order was already reviewed or could not be found.');
    }
    redirectTo(['view' => 'admin', 'section' => 'orders']);
}

flash('Unknown action.', 'error');
redirectTo(['view' => $view]);
