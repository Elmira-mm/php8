<?php
declare(strict_types=1);

const APP_OUTPUT = 'json';

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/products.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    renderJsonError(405, 'Метод не підтримується. Використовуйте POST.');
}

requireValidCsrf();

$name  = inputString($_POST, 'name');
$price = inputString($_POST, 'price');
$sku   = inputString($_POST, 'sku');
$stock = inputString($_POST, 'stock');

$errors = validateProductInput($name, $price, $sku, $stock);

if (empty($errors)) {
    $errors = addProduct($pdo, $name, (float) $price, $sku, (int) $stock);
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'product' => getProductById($pdo, (int) $pdo->lastInsertId()),
    'total'   => totalStockValue($pdo),
], JSON_UNESCAPED_UNICODE);
