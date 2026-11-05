const API = "https://dummyjson.com/products?limit=0";
const ORDERS_STORAGE_KEY = "northstar.orders.v1";
const PAGE_SIZE = 10;
const LOW_STOCK_THRESHOLD = 10;
let products = [];
let filteredProducts = [];
let page = 0;
let storeProducts = [];
let storePage = 0;
let currentView = "admin";
let catalogLoading = true;
let catalogError = "";
let orders = [];
let orderStorageError = "";
let activeOrderId = null;
const cart = new Map();
let activeDetailProduct = null;
const $ = id => document.getElementById(id);
const money = value => new Intl.NumberFormat("en-US", {style:"currency", currency:"USD", maximumFractionDigits:2}).format(value);
const readable = value => String(value || "—").replace(/-/g, " ");

function stockStatus(product) {
  if (product.stock <= 0) return {label:"Out of stock", className:"out-stock"};
  if (product.stock <= LOW_STOCK_THRESHOLD) return {label:"Low stock", className:"low-stock"};
  return {label:"In stock", className:"in-stock"};
}

function isValidOrder(order) {
  return order && typeof order.id === "string" &&
    typeof order.customerName === "string" &&
    typeof order.customerEmail === "string" &&
    typeof order.createdAt === "string" &&
    Number.isFinite(order.total) &&
    ["new", "reviewed"].includes(order.status) &&
    Array.isArray(order.items) &&
    order.items.every(item => item && typeof item.productName === "string" &&
      Number.isFinite(item.unitPrice) && Number.isInteger(item.quantity) && item.quantity > 0);
}

function readOrders() {
  orderStorageError = "";
  let serialized;
  try {
    serialized = localStorage.getItem(ORDERS_STORAGE_KEY);
  } catch (error) {
    orderStorageError = `Orders could not be read from this browser (${error.message}).`;
    return [];
  }
  if (!serialized) return [];
  try {
    const savedOrders = JSON.parse(serialized);
    if (!Array.isArray(savedOrders) || !savedOrders.every(isValidOrder)) {
      throw new Error("Saved order data has an invalid format.");
    }
    return savedOrders;
  } catch (error) {
    orderStorageError = `Saved orders could not be loaded (${error.message}).`;
    return [];
  }
}

function saveOrders(nextOrders) {
  try {
    localStorage.setItem(ORDERS_STORAGE_KEY, JSON.stringify(nextOrders));
    orders = nextOrders;
    orderStorageError = "";
    renderOrders();
    updateOrderSummary();
    return true;
  } catch (error) {
    orderStorageError = `Orders could not be saved in this browser (${error.message}).`;
    renderOrders();
    updateOrderSummary();
    return false;
  }
}

function updateOrderSummary() {
  const newCount = orders.filter(order => order.status === "new").length;
  $("stat-orders").textContent = newCount.toLocaleString("en-US");
  $("new-orders-badge").textContent = newCount.toLocaleString("en-US");
  $("new-orders-badge").hidden = newCount === 0;
  $("order-count").textContent = orderStorageError
    ? "Storage issue"
    : `${orders.length} ${orders.length === 1 ? "order" : "orders"}`;
}

function renderOrders() {
  const tbody = $("order-rows");
  tbody.replaceChildren();
  const notice = $("order-storage-notice");
  notice.textContent = orderStorageError;
  notice.hidden = !orderStorageError;
  if (orderStorageError) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 7;
    cell.className = "status-message error";
    cell.textContent = "Order storage is unavailable. Existing saved orders may not be shown.";
    row.appendChild(cell);
    tbody.appendChild(row);
    $("order-count").textContent = "Storage issue";
    return;
  }
  if (!orders.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 7;
    cell.className = "status-message";
    cell.textContent = "No customer orders yet. Completed purchases will appear here.";
    row.appendChild(cell);
    tbody.appendChild(row);
    return;
  }

  [...orders].sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt)).forEach(order => {
    const row = document.createElement("tr");
    const idCell = document.createElement("td");
    const id = document.createElement("span");
    id.className = "order-id";
    id.textContent = order.id;
    idCell.appendChild(id);
    const customerCell = document.createElement("td");
    const customerName = document.createElement("span");
    customerName.className = "order-id";
    customerName.textContent = order.customerName;
    const customerEmail = document.createElement("small");
    customerEmail.textContent = order.customerEmail;
    customerCell.append(customerName, customerEmail);
    const dateCell = document.createElement("td");
    const date = document.createElement("span");
    date.className = "order-date";
    date.textContent = new Date(order.createdAt).toLocaleString();
    dateCell.appendChild(date);
    const itemsCell = document.createElement("td");
    itemsCell.textContent = `${order.items.reduce((sum, item) => sum + item.quantity, 0)} items`;
    const totalCell = document.createElement("td");
    totalCell.className = "price";
    totalCell.textContent = money(order.total);
    const statusCell = document.createElement("td");
    const status = document.createElement("span");
    status.className = `order-status ${order.status}`;
    status.textContent = order.status === "new" ? "New" : "Reviewed";
    statusCell.appendChild(status);
    const actionCell = document.createElement("td");
    const view = document.createElement("button");
    view.type = "button";
    view.className = "view-btn";
    view.textContent = "View";
    view.setAttribute("aria-label", `View order ${order.id}`);
    view.addEventListener("click", () => openOrder(order.id));
    actionCell.appendChild(view);
    row.append(idCell, customerCell, dateCell, itemsCell, totalCell, statusCell, actionCell);
    tbody.appendChild(row);
  });
}

function updateSummary() {
  $("stat-products").textContent = products.length.toLocaleString("en-US");
  $("stat-low").textContent = products.filter(product => product.stock > 0 && product.stock <= LOW_STOCK_THRESHOLD).length.toLocaleString("en-US");
  const inventoryValue = products.reduce((sum, product) => sum + product.price * product.stock, 0);
  $("stat-value").textContent = money(inventoryValue);
  const average = products.length ? products.reduce((sum, product) => sum + product.rating, 0) / products.length : 0;
  $("stat-rating").textContent = average.toFixed(1) + " / 5";
}

function addOrderDetailValue(container, label, value) {
  const item = document.createElement("div");
  item.className = "order-detail-value";
  const caption = document.createElement("span");
  caption.textContent = label;
  const content = document.createElement("strong");
  content.textContent = value;
  item.append(caption, content);
  container.appendChild(item);
}

function openOrder(orderId) {
  const order = orders.find(item => item.id === orderId);
  if (!order) return;
  activeOrderId = order.id;
  const summary = $("order-detail-summary");
  summary.replaceChildren();
  addOrderDetailValue(summary, "Order", order.id);
  addOrderDetailValue(summary, "Customer", order.customerName);
  addOrderDetailValue(summary, "Email", order.customerEmail);
  addOrderDetailValue(summary, "Placed", new Date(order.createdAt).toLocaleString());
  const items = $("order-detail-items");
  items.replaceChildren();
  order.items.forEach(orderItem => {
    const row = document.createElement("div");
    row.className = "order-item";
    const name = document.createElement("div");
    name.textContent = orderItem.productName;
    const quantity = document.createElement("small");
    quantity.textContent = `${orderItem.quantity} × ${money(orderItem.unitPrice)}`;
    name.appendChild(quantity);
    const lineTotal = document.createElement("strong");
    lineTotal.textContent = money(orderItem.quantity * orderItem.unitPrice);
    row.append(name, lineTotal);
    items.appendChild(row);
  });
  $("order-detail-total").textContent = money(order.total);
  $("order-detail-error").hidden = true;
  $("mark-reviewed").hidden = order.status === "reviewed";
  $("order-dialog").showModal();
}

function markActiveOrderReviewed() {
  if (!activeOrderId) return;
  const nextOrders = orders.map(order =>
    order.id === activeOrderId ? {...order, status:"reviewed"} : order
  );
  if (saveOrders(nextOrders)) {
    $("order-dialog").close();
    activeOrderId = null;
  } else {
    $("order-detail-error").textContent = orderStorageError;
    $("order-detail-error").hidden = false;
  }
}

function populateCategories() {
  const categories = [...new Set(products.map(product => product.category))].sort();
  ["category", "store-category"].forEach(id => {
    $(id).replaceChildren(new Option("All categories", ""));
    categories.forEach(category => {
      const option = document.createElement("option");
      option.value = category;
      option.textContent = readable(category);
      $(id).appendChild(option);
    });
  });
}

function setView(view) {
  currentView = view;
  $("app").dataset.view = view;
  document.querySelectorAll(".view-option").forEach(button => {
    button.setAttribute("aria-pressed", String(button.dataset.view === view));
  });
  $("breadcrumb").innerHTML = view === "admin"
    ? 'Workspace <span aria-hidden="true">/</span> <strong>Dashboard</strong>'
    : 'Store <span aria-hidden="true">/</span> <strong>Shop</strong>';
  if (view === "store") filterStoreProducts();
}

function filterStoreProducts() {
  const query = $("store-q").value.trim().toLowerCase();
  const category = $("store-category").value;
  const [sortBy, order] = $("store-sort").value.split("-");
  storeProducts = products.filter(product => {
    const matchesText = !query || [product.title, product.brand, product.category, product.description]
      .some(value => String(value || "").toLowerCase().includes(query));
    return matchesText && (!category || product.category === category);
  });
  storeProducts.sort((a, b) => {
    const comparison = sortBy === "title" ? a.title.localeCompare(b.title) : a[sortBy] - b[sortBy];
    return order === "desc" ? -comparison : comparison;
  });
  storePage = 0;
  renderStorefront();
}

function renderStorefront() {
  const grid = $("store-grid");
  grid.replaceChildren();
  $("store-count").textContent = `${storeProducts.length} ${storeProducts.length === 1 ? "product" : "products"}`;
  if (catalogError) {
    const empty = document.createElement("div");
    empty.className = "store-empty error";
    empty.append(document.createTextNode(catalogError));
    const retry = document.createElement("button");
    retry.className = "store-retry";
    retry.type = "button";
    retry.textContent = "Retry";
    retry.addEventListener("click", loadProducts);
    empty.appendChild(retry);
    grid.appendChild(empty);
    $("store-page").textContent = "Page 1 of 1";
    $("store-prev").disabled = true;
    $("store-next").disabled = true;
    return;
  }
  if (!storeProducts.length) {
    const empty = document.createElement("div");
    empty.className = "store-empty";
    empty.textContent = catalogLoading ? "Loading products..." : products.length ? "No products match your search. Try another term or category." : "No products are available right now.";
    grid.appendChild(empty);
    $("store-page").textContent = "Page 1 of 1";
    $("store-prev").disabled = true;
    $("store-next").disabled = true;
    return;
  }
  const pageCount = Math.ceil(storeProducts.length / 12);
  storeProducts.slice(storePage * 12, (storePage + 1) * 12).forEach(product => {
    const card = document.createElement("article");
    card.className = "store-card";
    const imageWrap = document.createElement("div");
    imageWrap.className = "store-image-wrap";
    const image = document.createElement("img");
    image.className = "store-image";
    image.src = product.thumbnail;
    image.alt = product.title;
    image.loading = "lazy";
    const tag = document.createElement("span");
    tag.className = "store-tag";
    tag.textContent = readable(product.category);
    imageWrap.append(image, tag);
    const body = document.createElement("div");
    body.className = "store-card-body";
    const name = document.createElement("h2");
    name.className = "store-name";
    name.textContent = product.title;
    const meta = document.createElement("div");
    meta.className = "store-meta";
    const price = document.createElement("span");
    price.className = "store-price";
    price.textContent = money(product.price);
    const rating = document.createElement("span");
    rating.className = "store-rating";
    const star = document.createElement("span");
    star.textContent = "★ ";
    rating.append(star, document.createTextNode(Number(product.rating).toFixed(1)));
    meta.append(price, rating);
    const footer = document.createElement("div");
    footer.className = "store-card-footer";
    const availability = document.createElement("span");
    availability.className = product.stock > 0 ? "availability" : "availability unavailable";
    availability.textContent = product.stock > 0 ? "In stock" : "Currently unavailable";
    const details = document.createElement("button");
    details.type = "button";
    details.className = "store-details";
    details.textContent = "View details";
    details.addEventListener("click", () => openDetails(product));
    const actions = document.createElement("div");
    actions.className = "store-card-actions";
    const add = document.createElement("button");
    add.type = "button";
    add.className = "add-button";
    add.dataset.productId = String(product.id);
    const inCart = cart.get(product.id) || 0;
    const canAdd = product.stock > inCart;
    add.textContent = product.stock <= 0 ? "Unavailable" : canAdd ? "Add to cart" : "Max in cart";
    add.disabled = !canAdd;
    add.setAttribute("aria-label", `${add.textContent}: ${product.title}`);
    add.addEventListener("click", () => addToCart(product));
    actions.append(details, add);
    footer.append(availability, actions);
    body.append(name, meta, footer);
    card.append(imageWrap, body);
    grid.appendChild(card);
  });
  $("store-page").textContent = `Page ${storePage + 1} of ${pageCount}`;
  $("store-prev").disabled = storePage === 0;
  $("store-next").disabled = storePage + 1 >= pageCount;
}

function filterProducts() {
  const query = $("q").value.trim().toLowerCase();
  const category = $("category").value;
  const stockFilter = $("stock-filter").value;
  const sort = $("sort").value;
  filteredProducts = products.filter(product => {
    const matchesText = !query || [product.title, product.brand, product.category].some(value => String(value || "").toLowerCase().includes(query));
    const matchesCategory = !category || product.category === category;
    const isLow = product.stock > 0 && product.stock <= LOW_STOCK_THRESHOLD;
    const matchesStock = !stockFilter || (stockFilter === "in" && product.stock > LOW_STOCK_THRESHOLD) || (stockFilter === "low" && isLow) || (stockFilter === "out" && product.stock <= 0);
    return matchesText && matchesCategory && matchesStock;
  });

  const [sortBy, order] = sort.split("-");
  filteredProducts.sort((a, b) => {
    let comparison;
    if (sortBy === "title") comparison = a.title.localeCompare(b.title);
    else comparison = a[sortBy] - b[sortBy];
    return order === "desc" ? -comparison : comparison;
  });
  page = 0;
  renderTable();
}

function renderTable() {
  const tbody = $("product-rows");
  tbody.replaceChildren();
  $("result-count").textContent = `${filteredProducts.length} ${filteredProducts.length === 1 ? "product" : "products"}`;

  if (!filteredProducts.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 6;
    cell.className = "empty-state";
    const title = document.createElement("strong");
    title.textContent = products.length ? "No products found" : "No products available";
    const message = document.createElement("span");
    message.textContent = products.length ? "Try adjusting your search or filters." : "The catalog is currently empty.";
    cell.append(title, message);
    row.appendChild(cell);
    tbody.appendChild(row);
    $("range").textContent = "Showing 0 products";
    $("page").textContent = "Page 1 of 1";
    $("prev").disabled = true;
    $("next").disabled = true;
    return;
  }

  const pageCount = Math.ceil(filteredProducts.length / PAGE_SIZE);
  const start = page * PAGE_SIZE;
  const pageProducts = filteredProducts.slice(start, start + PAGE_SIZE);
  pageProducts.forEach(product => tbody.appendChild(createRow(product)));
  $("range").textContent = `Showing ${start + 1}–${Math.min(start + PAGE_SIZE, filteredProducts.length)} of ${filteredProducts.length}`;
  $("page").textContent = `Page ${page + 1} of ${pageCount}`;
  $("prev").disabled = page === 0;
  $("next").disabled = page + 1 >= pageCount;
}

function createRow(product) {
  const row = document.createElement("tr");
  const productCell = document.createElement("td");
  const productInfo = document.createElement("div");
  productInfo.className = "product-cell";
  const image = document.createElement("img");
  image.className = "product-thumb";
  image.src = product.thumbnail;
  image.alt = "";
  image.loading = "lazy";
  const text = document.createElement("div");
  const name = document.createElement("div");
  name.className = "product-name";
  name.textContent = product.title;
  const category = document.createElement("div");
  category.className = "product-category";
  category.textContent = readable(product.category);
  text.append(name, category);
  productInfo.append(image, text);
  productCell.appendChild(productInfo);

  const price = document.createElement("td");
  price.className = "price";
  price.textContent = money(product.price);
  const stock = document.createElement("td");
  stock.className = "stock-number";
  stock.textContent = product.stock.toLocaleString("en-US");
  const statusCell = document.createElement("td");
  const status = stockStatus(product);
  const pill = document.createElement("span");
  pill.className = `status-pill ${status.className}`;
  pill.textContent = status.label;
  statusCell.appendChild(pill);
  const rating = document.createElement("td");
  rating.className = "rating";
  const star = document.createElement("span");
  star.className = "rating-star";
  star.textContent = "★ ";
  rating.append(star, document.createTextNode(Number(product.rating).toFixed(1)));
  const actionCell = document.createElement("td");
  const view = document.createElement("button");
  view.className = "view-btn";
  view.type = "button";
  view.textContent = "View";
  view.setAttribute("aria-label", `View ${product.title} details`);
  view.addEventListener("click", () => openDetails(product));
  actionCell.appendChild(view);
  row.append(productCell, price, stock, statusCell, rating, actionCell);
  return row;
}

function openDetails(product) {
  activeDetailProduct = product;
  $("detail-img").src = product.images?.[0] || product.thumbnail;
  $("detail-img").alt = product.title;
  $("detail-name").textContent = product.title;
  $("detail-category").textContent = [product.brand, readable(product.category)].filter(Boolean).join(" · ");
  $("detail-description").textContent = product.description;
  const status = stockStatus(product);
  const details = currentView === "admin" ? [
    ["Price", money(product.price)],
    ["Stock", product.stock.toLocaleString("en-US") + " units"],
    ["Availability", status.label],
    ["Rating", Number(product.rating).toFixed(1) + " / 5"],
    ["SKU", product.sku || "—"],
    ["Discount", product.discountPercentage ? `${product.discountPercentage}%` : "—"]
  ] : [
    ["Price", money(product.price)],
    ["Availability", product.stock > 0 ? "In stock" : "Currently unavailable"],
    ["Rating", Number(product.rating).toFixed(1) + " / 5"],
    ["Brand", product.brand || "—"]
  ];
  const grid = $("detail-grid");
  grid.replaceChildren();
  details.forEach(([label, value]) => {
    const item = document.createElement("div");
    item.className = "detail-item";
    const name = document.createElement("span");
    name.textContent = label;
    const content = document.createElement("strong");
    content.textContent = value;
    item.append(name, content);
    grid.appendChild(item);
  });
  const canAdd = currentView === "store" && product.stock > (cart.get(product.id) || 0);
  $("detail-add").hidden = currentView !== "store";
  $("detail-add").disabled = !canAdd;
  $("detail-add").textContent = product.stock <= 0 ? "Unavailable" : canAdd ? "Add to cart" : "Max in cart";
  $("detail-dialog").showModal();
}

function cartQuantity() {
  return [...cart.values()].reduce((sum, quantity) => sum + quantity, 0);
}

function updateCartCount() {
  $("cart-count").textContent = cartQuantity().toLocaleString("en-US");
}

function addToCart(product) {
  const quantity = cart.get(product.id) || 0;
  if (quantity >= product.stock) return;
  cart.set(product.id, quantity + 1);
  updateCartCount();
  renderStorefront();
  if (activeDetailProduct?.id === product.id && $("detail-dialog").open) {
    const canAdd = product.stock > (cart.get(product.id) || 0);
    $("detail-add").disabled = !canAdd;
    $("detail-add").textContent = canAdd ? "Add to cart" : "Max in cart";
  }
}

function changeCartQuantity(productId, delta) {
  const product = products.find(item => item.id === productId);
  const quantity = cart.get(productId) || 0;
  if (!product) {
    cart.delete(productId);
  } else {
    const nextQuantity = quantity + delta;
    if (nextQuantity <= 0) cart.delete(productId);
    else if (nextQuantity <= product.stock) cart.set(productId, nextQuantity);
  }
  updateCartCount();
  renderCart();
  renderStorefront();
}

function renderCart() {
  const container = $("cart-items");
  container.replaceChildren();
  let subtotal = 0;
  const entries = [...cart.entries()].map(([id, quantity]) => ({
    product: products.find(product => product.id === id),
    quantity
  })).filter(entry => entry.product);

  if (!entries.length) {
    const empty = document.createElement("div");
    empty.className = "cart-empty";
    empty.textContent = "Your cart is empty. Browse the storefront to add products.";
    container.appendChild(empty);
  }

  entries.forEach(({product, quantity}) => {
    subtotal += product.price * quantity;
    const item = document.createElement("article");
    item.className = "cart-item";
    const image = document.createElement("img");
    image.src = product.thumbnail;
    image.alt = "";
    const info = document.createElement("div");
    const name = document.createElement("div");
    name.className = "cart-item-name";
    name.textContent = product.title;
    const price = document.createElement("div");
    price.className = "cart-item-price";
    price.textContent = `${money(product.price)} each`;
    const lineBottom = document.createElement("div");
    lineBottom.className = "cart-line-bottom";
    const lineTotal = document.createElement("strong");
    lineTotal.textContent = money(product.price * quantity);
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "remove-item";
    remove.textContent = "Remove";
    remove.setAttribute("aria-label", `Remove ${product.title} from cart`);
    remove.addEventListener("click", () => changeCartQuantity(product.id, -quantity));
    lineBottom.append(lineTotal, remove);
    info.append(name, price, lineBottom);

    const controls = document.createElement("div");
    controls.className = "cart-qty";
    const decrement = document.createElement("button");
    decrement.type = "button";
    decrement.className = "qty-button";
    decrement.textContent = "−";
    decrement.setAttribute("aria-label", `Decrease ${product.title} quantity`);
    decrement.addEventListener("click", () => changeCartQuantity(product.id, -1));
    const count = document.createElement("span");
    count.className = "qty-value";
    count.textContent = String(quantity);
    const increment = document.createElement("button");
    increment.type = "button";
    increment.className = "qty-button";
    increment.textContent = "+";
    increment.disabled = quantity >= product.stock;
    increment.setAttribute("aria-label", `Increase ${product.title} quantity`);
    increment.addEventListener("click", () => changeCartQuantity(product.id, 1));
    controls.append(decrement, count, increment);
    item.append(image, info, controls);
    container.appendChild(item);
  });

  const summary = $("cart-summary");
  summary.replaceChildren();
  if (entries.length) {
    const row = document.createElement("div");
    row.className = "summary-row total";
    const label = document.createElement("span");
    label.textContent = "Subtotal";
    const value = document.createElement("strong");
    value.textContent = money(subtotal);
    row.append(label, value);
    summary.appendChild(row);
  }
  $("checkout-open").disabled = !entries.length;
  $("checkout-total").textContent = money(subtotal);
}

function openCheckout() {
  if (!cart.size) return;
  $("cart-dialog").close();
  $("checkout-form").reset();
  $("checkout-error").hidden = true;
  $("checkout-form").hidden = false;
  $("checkout-confirmation").hidden = true;
  $("checkout-total").textContent = money(cartSubtotal());
  $("checkout-dialog").showModal();
}

function cartSubtotal() {
  return [...cart.entries()].reduce((sum, [id, quantity]) => {
    const product = products.find(item => item.id === id);
    return sum + (product ? product.price * quantity : 0);
  }, 0);
}

function placeDemoOrder(event) {
  event.preventDefault();
  const errorMessage = $("checkout-error");
  errorMessage.hidden = true;
  if (!$("checkout-form").reportValidity()) return;
  if (!cart.size) {
    errorMessage.textContent = "Your cart is empty. Add a product before checking out.";
    errorMessage.hidden = false;
    return;
  }
  if (orderStorageError) {
    errorMessage.textContent = orderStorageError + " The purchase was not completed.";
    errorMessage.hidden = false;
    return;
  }
  const items = [...cart.entries()].map(([id, quantity]) => {
    const product = products.find(item => item.id === id);
    if (!product || quantity > product.stock) return null;
    return {
      productId: product.id,
      productName: product.title,
      thumbnail: product.thumbnail,
      unitPrice: product.price,
      quantity
    };
  });
  if (items.some(item => !item)) {
    errorMessage.textContent = "One or more products are no longer available. Review your cart and try again.";
    errorMessage.hidden = false;
    return;
  }
  const customerName = $("customer-name").value.trim();
  const customerEmail = $("customer-email").value.trim();
  const orderNumber = "DEMO-" + Date.now().toString(36).toUpperCase() + "-" + Math.random().toString(36).slice(2, 6).toUpperCase();
  const order = {
    id: orderNumber,
    customerName,
    customerEmail,
    createdAt: new Date().toISOString(),
    items,
    total: items.reduce((sum, item) => sum + item.unitPrice * item.quantity, 0),
    status: "new"
  };
  if (!saveOrders([order, ...orders])) {
    errorMessage.textContent = orderStorageError + " The purchase was not completed.";
    errorMessage.hidden = false;
    return;
  }
  cart.clear();
  updateCartCount();
  renderCart();
  renderStorefront();
  $("checkout-form").hidden = true;
  $("checkout-confirmation").hidden = false;
  $("confirmation-message").textContent = `Thanks, ${customerName}. Your demo order ${orderNumber} is saved in this browser for admin review. No payment was taken.`;
}

async function loadProducts() {
  catalogLoading = true;
  catalogError = "";
  const tbody = $("product-rows");
  tbody.innerHTML = '<tr><td class="status-message" colspan="6">Loading inventory...</td></tr>';
  try {
    const response = await fetch(API);
    if (!response.ok) throw new Error("Server returned " + response.status);
    const data = await response.json();
    if (!Array.isArray(data.products)) throw new Error("The catalog response was invalid.");
    products = data.products;
    catalogLoading = false;
    updateSummary();
    populateCategories();
    $("updated").textContent = `${products.length} products loaded`;
    filterProducts();
  } catch (error) {
    catalogLoading = false;
    catalogError = "Could not load products (" + error.message + "). Check your connection.";
    $("updated").textContent = "Catalog unavailable";
    $("result-count").textContent = "Unavailable";
    tbody.replaceChildren();
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 6;
    cell.className = "status-message error";
    cell.textContent = "Could not load inventory (" + error.message + "). Check your connection and try again.";
    const retry = document.createElement("button");
    retry.className = "retry";
    retry.type = "button";
    retry.textContent = "Retry";
    retry.addEventListener("click", loadProducts);
    cell.appendChild(retry);
    row.appendChild(cell);
    tbody.appendChild(row);
    renderStorefront();
  }
}

$("filters").addEventListener("submit", event => event.preventDefault());
["q", "category", "stock-filter", "sort"].forEach(id => {
  $(id).addEventListener(id === "q" ? "input" : "change", filterProducts);
});
$("reset").addEventListener("click", () => {
  $("q").value = "";
  $("category").value = "";
  $("stock-filter").value = "";
  $("sort").value = "title-asc";
  filterProducts();
});
$("prev").addEventListener("click", () => { if (page > 0) { page--; renderTable(); } });
$("next").addEventListener("click", () => {
  if ((page + 1) * PAGE_SIZE < filteredProducts.length) { page++; renderTable(); }
});
$("dialog-x").addEventListener("click", () => $("detail-dialog").close());
$("dialog-close").addEventListener("click", () => $("detail-dialog").close());
$("detail-dialog").addEventListener("click", event => {
  if (event.target === $("detail-dialog")) $("detail-dialog").close();
});
$("detail-add").addEventListener("click", () => {
  if (activeDetailProduct) addToCart(activeDetailProduct);
});
document.querySelectorAll(".view-option").forEach(button => {
  button.addEventListener("click", () => setView(button.dataset.view));
});
["store-q", "store-category", "store-sort"].forEach(id => {
  $(id).addEventListener(id === "store-q" ? "input" : "change", filterStoreProducts);
});
$("store-prev").addEventListener("click", () => {
  if (storePage > 0) { storePage--; renderStorefront(); }
});
$("store-next").addEventListener("click", () => {
  if ((storePage + 1) * 12 < storeProducts.length) { storePage++; renderStorefront(); }
});
$("cart-open").addEventListener("click", () => {
  renderCart();
  $("cart-dialog").showModal();
});
$("cart-x").addEventListener("click", () => $("cart-dialog").close());
$("cart-continue").addEventListener("click", () => $("cart-dialog").close());
$("cart-dialog").addEventListener("click", event => {
  if (event.target === $("cart-dialog")) $("cart-dialog").close();
});
$("checkout-open").addEventListener("click", openCheckout);
$("checkout-form").addEventListener("submit", placeDemoOrder);
["checkout-x", "checkout-close"].forEach(id => {
  $(id).addEventListener("click", () => $("checkout-dialog").close());
});
$("checkout-dialog").addEventListener("click", event => {
  if (event.target === $("checkout-dialog")) $("checkout-dialog").close();
});
$("order-dialog-x").addEventListener("click", () => $("order-dialog").close());
$("order-dialog-close").addEventListener("click", () => $("order-dialog").close());
$("order-dialog").addEventListener("click", event => {
  if (event.target === $("order-dialog")) $("order-dialog").close();
});
$("mark-reviewed").addEventListener("click", markActiveOrderReviewed);
window.addEventListener("storage", event => {
  if (event.key === ORDERS_STORAGE_KEY || event.key === null) {
    orders = readOrders();
    renderOrders();
    updateOrderSummary();
  }
});

orders = readOrders();
renderOrders();
updateOrderSummary();
loadProducts();
