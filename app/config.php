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
