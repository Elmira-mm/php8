<?php
declare(strict_types=1);

const APP_OUTPUT = 'json';

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/products.php';

header('Content-Type: application/json; charset=utf-8');

// Будь-який виняток обробляє set_exception_handler (лог + загальна відповідь 500).
echo json_encode([
    'success'  => true,
    'products' => getAllProducts($pdo),
    'total'    => totalStockValue($pdo),
], JSON_UNESCAPED_UNICODE);
