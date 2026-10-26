<?php
declare(strict_types=1);

/**
 * api.php — єдина точка входу API. Контракт описано в API.md (практикум №6).
 *
 * Маршрутизація: resource + метод HTTP + (для POST) необов'язковий action.
 * Формат відповіді: {"success": true, "data": ...} або {"success": false, "error": "..."}.
 *
 * Практикум №7: список товарів містить додаткове поле "meta" (час, кількість SQL-запитів, кеш).
 * Практикум №8: усі POST-запити вимагають CSRF-токен; помилки логуються, користувач бачить загальну 500.
 */

const APP_OUTPUT = 'json';

ob_start(); // страховка від стороннього виводу PHP перед json_encode()

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/QueryCounter.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/cache.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Єдине місце виводу відповіді — однаковий формат і коректний HTTP-код для будь-якої гілки.
 */
function respond(int $status, array $body): void
{
    ob_end_clean();
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? null;
    $action = $_GET['action'] ?? null;
    $mode = is_string($_GET['mode'] ?? null) ? $_GET['mode'] : 'optimized';

    // Видача CSRF-токена клієнтам API (сесія зберігається в cookie).
    if ($resource === 'csrf' && $method === 'GET') {
        respond(200, ['success' => true, 'data' => ['csrf_token' => csrfToken()]]);
    }

    // Маршрутизація за ресурсом.
    if ($resource !== 'products') {
        respond(404, ['success' => false, 'error' => 'Невідомий ресурс. Підтримується лише resource=products.']);
    }

    // ---------------------------------------------------------------- GET
    if ($method === 'GET') {
        QueryCounter::reset();
        $startTime = microtime(true);

        // Пошук за SKU: ...&sku=MAT-003 (значення лише як параметр підготовленого запиту).
        if (array_key_exists('sku', $_GET)) {
            if (!is_string($_GET['sku'])) {
                respond(400, ['success' => false, 'error' => 'Параметр sku має бути рядком.']);
            }

            $product = findBySku($pdo, trim($_GET['sku']));

            if ($product === null) {
                respond(404, ['success' => false, 'error' => 'Товар не знайдено.']);
            }

            respond(200, ['success' => true, 'data' => $product]);
        }

        // Один товар за id.
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

            if ($id === false) {
                respond(400, ['success' => false, 'error' => 'Параметр id має бути цілим числом.']);
            }

            $product = getProductById($pdo, $id);

            if ($product === null) {
                respond(404, ['success' => false, 'error' => "Товар з id {$id} не знайдено."]);
            }

            respond(200, ['success' => true, 'data' => $product]);
        }

        // Фільтр за ціною (індекс idx_products_price): ...&price_min=100&price_max=150.
        if (isset($_GET['price_min']) || isset($_GET['price_max'])) {
            $min = $_GET['price_min'] ?? null;
            $max = $_GET['price_max'] ?? null;

            if (($min !== null && !is_numeric($min)) || ($max !== null && !is_numeric($max))) {
                respond(400, ['success' => false, 'error' => 'price_min і price_max мають бути числами.']);
            }

            $filtered = getProductsByPriceRange(
                $pdo,
                $min !== null ? (float) $min : null,
                $max !== null ? (float) $max : null
            );

            respond(200, [
                'success' => true,
                'data'    => $filtered,
                'meta'    => [
                    'filter'      => 'price',
                    'count'       => count($filtered),
                    'query_count' => QueryCounter::get(),
                    'elapsed_ms'  => round((microtime(true) - $startTime) * 1000, 2),
                ],
            ]);
        }

        // Список усіх товарів (+ meta з практикуму №7).
        $products = getAllProducts($pdo);
        $cacheStatus = 'n/a';

        if ($mode === 'naive') {
            $totalStockValue = getTotalStockValueNaive($pdo, $products);
        } else {
            $cached = cachedValue(TOTAL_STOCK_VALUE_CACHE_KEY, 60, fn() => totalStockValue($pdo));
            $totalStockValue = $cached['value'];
            $cacheStatus = $cached['cache'];
        }

        respond(200, [
            'success' => true,
            'data'    => $products,
            'meta'    => [
                'mode'              => $mode,
                'query_count'       => QueryCounter::get(),
                'elapsed_ms'        => round((microtime(true) - $startTime) * 1000, 2),
                'memory_mb'         => round(memory_get_peak_usage(true) / 1024 / 1024, 3),
                'cache'             => $cacheStatus,
                'total_stock_value' => round($totalStockValue, 2),
            ],
        ]);
    }

    // ---------------------------------------------------------------- POST
    if ($method === 'POST') {
        // Будь-яка зміна даних — лише з дійсним CSRF-токеном (заголовок X-CSRF-Token або поле csrf_token).
        requireValidCsrf();

        $input = parseRequestInput();

        if ($input === null) {
            respond(400, ['success' => false, 'error' => 'Тіло запиту має бути коректним JSON-об\'єктом.']);
        }

        if ($action === null) {
            $result = createProduct($pdo, $input);
            respond($result['status'], $result['body']);
        }

        if ($action === 'restock') {
            $result = restockProduct($pdo, $input);
            respond($result['status'], $result['body']);
        }

        respond(400, ['success' => false, 'error' => 'Невідома дія action.']);
    }

    // Метод, що не підтримується для цього ресурсу (PUT, DELETE, ...).
    header('Allow: GET, POST');
    respond(405, ['success' => false, 'error' => 'Метод не підтримується для ресурсу products.']);
} catch (Throwable $e) {
    // Деталі — в лог, користувачу — загальне повідомлення.
    error_log(sprintf('[api] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    respond(500, ['success' => false, 'error' => 'Внутрішня помилка сервера.']);
}
