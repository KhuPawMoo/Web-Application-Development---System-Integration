<?php
declare(strict_types=1);

function orderDatabase(): PDO
{
    $configuredPath = getenv('ORDER_DB_PATH');
    $databasePath = $configuredPath !== false && $configuredPath !== ''
        ? $configuredPath
        : dirname(__DIR__, 2) . '/system-integration-data/orders.sqlite';
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

function findOrder(PDO $db, string $orderCode): ?array
{
    $statement = $db->prepare('SELECT * FROM orders WHERE order_code = ?');
    $statement->execute([$orderCode]);
    $order = $statement->fetch();

    return $order === false ? null : $order;
}

function createOrder(PDO $db, string $checkoutToken, string $name, string $email, array $items, float $total): string
{
    $existing = $db->prepare('SELECT order_code FROM orders WHERE checkout_token = ?');
    $existing->execute([$checkoutToken]);
    $existingCode = $existing->fetchColumn();
    if (is_string($existingCode)) {
        return $existingCode;
    }

    $db->beginTransaction();
    try {
        $orderCode = 'ORD-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $insertOrder = $db->prepare(
            'INSERT INTO orders (order_code, checkout_token, customer_name, customer_email, created_at, total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertOrder->execute([$orderCode, $checkoutToken, $name, $email, gmdate('c'), $total]);
        $orderId = (int)$db->lastInsertId();
        $insertItem = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, thumbnail, unit_price, quantity)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
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

        return $orderCode;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function markOrderReviewed(PDO $db, string $orderCode): bool
{
    $statement = $db->prepare('UPDATE orders SET status = \'reviewed\' WHERE order_code = ? AND status = \'new\'');
    $statement->execute([$orderCode]);

    return $statement->rowCount() > 0;
}
