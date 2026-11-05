# Northstar inventory and storefront

The app is server-rendered with PHP; it does not use client-side JavaScript.
Purchases are written to a SQLite database on the PHP server, so signed-in
admins can review orders from other browsers that use the same deployment.
Checkout is a demo and does not process payments.

## Project structure

- `index.php` — front controller and request initialization
- `app/config.php` — session settings, response headers, and shared constants
- `app/helpers.php` — escaping, CSRF, flash messages, redirects, and links
- `app/catalog.php` — product catalog integration and stock labels
- `app/orders.php` — SQLite schema and order persistence
- `app/actions.php` — form actions for sign-in, cart, checkout, and order review
- `app/page-data.php` — filtering, sorting, pagination, and view data
- `views/layout.php` — shared HTML shell and view selection
- `views/admin/` — admin login, dashboard, inventory, and order templates
- `views/partials/` — shared navigation templates
- `views/storefront.php` — storefront, cart, and checkout templates
- `styles.css` — shared responsive styling

## Requirements

- PHP 8.1 or newer
- PHP cURL and PDO SQLite extensions
- Network access from the PHP server to `dummyjson.com`

## Run locally

Set a unique admin password of at least 12 characters and start PHP's built-in
development server from this directory:

```sh
ADMIN_PASSWORD='replace-with-a-long-unique-password' php -S 127.0.0.1:8000 -t .
```

Open `http://127.0.0.1:8000/`. Sign in with the configured password to view the
admin dashboard and customer orders. The storefront is available from the
Admin/Storefront switch.

By default, the SQLite database is created at
`../system-integration-data/orders.sqlite`, outside the public web directory.
Set `ORDER_DB_PATH` to an absolute path if the deployment needs a different
writable location. Keep the database private and back it up according to your
deployment's retention requirements.

Deploy this app to a PHP-capable web server; static hosting such as GitHub Pages
does not execute PHP. Configure `ADMIN_PASSWORD`, `ORDER_DB_PATH`, HTTPS, and
writable database storage in the server environment. The demo admin view is
password-protected, but this sample is not a payment system or a complete
production commerce platform.
