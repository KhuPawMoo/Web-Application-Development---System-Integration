# Northstar inventory and storefront

The app is server-rendered with PHP; it does not use client-side JavaScript.
Purchases are written to a SQLite database on the PHP server, so signed-in
admins can review orders from other browsers that use the same deployment.
Checkout is a demo and does not process payments.

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
