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
