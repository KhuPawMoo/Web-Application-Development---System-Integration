<section class="panel" id="inventory" aria-labelledby="inventory-title">
  <div class="panel-heading"><div><h2 id="inventory-title">Product inventory</h2><p>Search and review products in your catalog.</p></div><span class="count-badge"><?= count($filteredProducts) ?> products</span></div>
  <form class="filters" action="index.php" method="get" role="search">
    <input type="hidden" name="view" value="admin">
    <label class="search-wrap"><span class="search-icon" aria-hidden="true">⌕</span><input name="q" type="search" value="<?= e($query) ?>" placeholder="Search products..." aria-label="Search products"></label>
    <select name="category" aria-label="Filter by category"><option value="">All categories</option><?php foreach ($categories as $itemCategory): ?><option value="<?= e($itemCategory) ?>" <?= $category === $itemCategory ? 'selected' : '' ?>><?= e(readable($itemCategory)) ?></option><?php endforeach; ?></select>
    <select name="stock" aria-label="Filter by stock status"><option value="">All stock levels</option><option value="in" <?= $stockFilter === 'in' ? 'selected' : '' ?>>In stock</option><option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option><option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option></select>
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
