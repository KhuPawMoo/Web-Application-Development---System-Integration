<?php
declare(strict_types=1);

function catalogProducts(): array
{
    $curl = curl_init(CATALOG_URL);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the product catalog request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'NorthstarInventory/1.0',
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);

    if ($body === false) {
        throw new RuntimeException('Could not load products from the catalog: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('The product catalog returned HTTP ' . $status . '.');
    }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!isset($data['products']) || !is_array($data['products'])) {
        throw new RuntimeException('The product catalog returned an invalid response.');
    }

    return array_values(array_filter($data['products'], static fn($product): bool =>
        is_array($product) && isset($product['id'], $product['title'], $product['price'], $product['stock'])
    ));
}

function stockStatus(array $product): array
{
    if ((int)$product['stock'] <= 0) {
        return ['label' => 'Out of stock', 'class' => 'out-stock'];
    }
    if ((int)$product['stock'] <= LOW_STOCK_THRESHOLD) {
        return ['label' => 'Low stock', 'class' => 'low-stock'];
    }

    return ['label' => 'In stock', 'class' => 'in-stock'];
}
