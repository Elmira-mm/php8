<?php
declare(strict_types=1);

require_once __DIR__ . '/QueryCounter.php';
require_once __DIR__ . '/cache.php';

/** Ключ файлового кешу для агрегату «сумарна вартість складу». */
const TOTAL_STOCK_VALUE_CACHE_KEY = 'total_stock_value';

/** Межі, що відповідають схемі БД (VARCHAR(255), VARCHAR(20), DECIMAL(10,2), INT). */
const MAX_NAME_LENGTH = 255;
const MAX_SKU_LENGTH = 20;
const MAX_PRICE = 99999999.99;
const MAX_STOCK = 2147483647;
/** Максимум одного поповнення залишку. */
const MAX_RESTOCK_QUANTITY = 100000;

function getAllProducts(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll();
    QueryCounter::increment();

    return $rows;
}

function getProductById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    QueryCounter::increment();

    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Пошук за SKU — значення передається лише як параметр підготовленого запиту.
 */
function findBySku(PDO $pdo, string $sku): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE sku = :sku');
    $stmt->execute([':sku' => $sku]);
    QueryCounter::increment();

    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Фільтр за діапазоном ціни (обслуговується індексом idx_products_price).
 * У текст запиту потрапляють лише фіксовані фрагменти; значення — тільки параметри.
 */
function getProductsByPriceRange(PDO $pdo, ?float $min, ?float $max): array
{
    $conditions = [];
    $params = [];

    if ($min !== null) {
        $conditions[] = 'price >= :min';
        $params[':min'] = $min;
    }

    if ($max !== null) {
        $conditions[] = 'price <= :max';
        $params[':max'] = $max;
    }

    $sql = 'SELECT * FROM products'
        . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '')
        . ' ORDER BY price';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    QueryCounter::increment();

    return $stmt->fetchAll();
}

/**
 * ПОВІЛЬНО (N+1) — залишено для демонстрації з практикуму №7.
 */
function getTotalStockValueNaive(PDO $pdo, array $products): float
{
    $total = 0.0;

    foreach ($products as $product) {
        $stmt = $pdo->prepare('SELECT price * stock AS value FROM products WHERE id = :id');
        $stmt->execute([':id' => $product['id']]);
        QueryCounter::increment();

        $total += (float) $stmt->fetchColumn();
    }

    return $total;
}

function totalStockValue(PDO $pdo): float
{
    $row = $pdo->query('SELECT SUM(price * stock) AS total FROM products')->fetch();
    QueryCounter::increment();

    return (float) ($row['total'] ?? 0);
}

function addProduct(PDO $pdo, string $name, float $price, string $sku, int $stock): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO products (name, price, sku, stock) VALUES (:name, :price, :sku, :stock)'
    );

    try {
        $stmt->execute([
            ':name'  => $name,
            ':price' => $price,
            ':sku'   => strtoupper($sku),
            ':stock' => $stock,
        ]);
        QueryCounter::increment();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['sku' => 'Товар з таким SKU вже існує. Оберіть інший артикул.'];
        }

        throw $e;
    }

    invalidateCache(TOTAL_STOCK_VALUE_CACHE_KEY);

    return [];
}

function updateProduct(PDO $pdo, int $id, string $name, float $price, string $sku, int $stock): array
{
    $stmt = $pdo->prepare(
        'UPDATE products SET name = :name, price = :price, sku = :sku, stock = :stock WHERE id = :id'
    );

    try {
        $stmt->execute([
            ':name'  => $name,
            ':price' => $price,
            ':sku'   => strtoupper($sku),
            ':stock' => $stock,
            ':id'    => $id,
        ]);
        QueryCounter::increment();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['sku' => 'Товар з таким SKU вже існує. Оберіть інший артикул.'];
        }

        throw $e;
    }

    invalidateCache(TOTAL_STOCK_VALUE_CACHE_KEY);

    return [];
}

function deleteProduct(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    QueryCounter::increment();

    $deleted = $stmt->rowCount() > 0;

    if ($deleted) {
        invalidateCache(TOTAL_STOCK_VALUE_CACHE_KEY);
    }

    return $deleted;
}

/**
 * Збільшення залишку: атомарно на стороні СУБД (stock = stock + :qty), з перевіркою,
 * що результат не вийде за межі INT. Повертає масив помилок (порожній — успіх).
 * Використовується і формою restock.php, і API-дією restock.
 */
function increaseStock(PDO $pdo, int $id, int $quantity): array
{
    $stmt = $pdo->prepare(
        'UPDATE products SET stock = stock + :qty WHERE id = :id AND stock <= :limit'
    );
    $stmt->execute([
        ':qty'   => $quantity,
        ':id'    => $id,
        ':limit' => MAX_STOCK - $quantity,
    ]);
    QueryCounter::increment();

    if ($stmt->rowCount() === 0) {
        return getProductById($pdo, $id) === null
            ? ['product' => 'Товар не знайдено.']
            : ['quantity' => 'Залишок після поповнення перевищить допустиму межу.'];
    }

    invalidateCache(TOTAL_STOCK_VALUE_CACHE_KEY);

    return [];
}

/**
 * Довжина рядка в символах UTF-8 без залежності від розширення mbstring.
 */
function textLength(string $value): int
{
    $count = preg_match_all('/./su', $value);

    return $count === false ? strlen($value) : $count;
}

function validateProductInput(string $name, string $price, string $sku, string $stock): array
{
    $errors = [];

    if (trim($name) === '') {
        $errors['name'] = 'Назва товару обов\'язкова.';
    } elseif (!preg_match('//u', $name)) {
        $errors['name'] = 'Назва містить недопустимі символи.';
    } elseif (textLength(trim($name)) > MAX_NAME_LENGTH) {
        $errors['name'] = 'Назва товару не може бути довшою за ' . MAX_NAME_LENGTH . ' символів.';
    }

    if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
        $errors['price'] = 'Ціна має бути числом більшим за 0.';
    } elseif ((float) $price > MAX_PRICE) {
        $errors['price'] = 'Ціна не може перевищувати 99 999 999,99.';
    }

    if ($sku === '' || !preg_match('/^[A-Za-z]+-\d+$/', $sku)) {
        $errors['sku'] = 'Формат SKU: літери-дефіс-цифри, наприклад MAT-045.';
    } elseif (strlen($sku) > MAX_SKU_LENGTH) {
        $errors['sku'] = 'SKU не може бути довшим за ' . MAX_SKU_LENGTH . ' символів.';
    }

    if ($stock === '' || filter_var($stock, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => MAX_STOCK]]) === false) {
        $errors['stock'] = 'Залишок має бути цілим числом від 0 до ' . MAX_STOCK . '.';
    }

    return $errors;
}

function validateRestockQuantity(string $quantity): array
{
    $options = ['options' => ['min_range' => 1, 'max_range' => MAX_RESTOCK_QUANTITY]];

    if ($quantity === '' || filter_var($quantity, FILTER_VALIDATE_INT, $options) === false) {
        return ['quantity' => 'Кількість має бути цілим числом від 1 до ' . MAX_RESTOCK_QUANTITY . '.'];
    }

    return [];
}

// ====================================================================
// Функції API (практикум №6): контракт описано в API.md.
// Повертають ['status' => int, 'body' => array], серіалізацію в JSON виконує api.php.
// ====================================================================

/**
 * Валідація полів для створення товару через API — ті самі правила, що й для форм.
 * Значення читаються через inputString(): масиви, null, булеві та вкладені об'єкти дають '' і відхиляються.
 */
function validateCreateInput(array $input): array
{
    return validateProductInput(
        inputString($input, 'name'),
        inputString($input, 'price'),
        inputString($input, 'sku'),
        inputString($input, 'stock')
    );
}

function createProduct(PDO $pdo, array $input): array
{
    $errors = validateCreateInput($input);

    if (!empty($errors)) {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => 'Некоректні дані.', 'fields' => $errors],
        ];
    }

    $errors = addProduct(
        $pdo,
        inputString($input, 'name'),
        (float) inputString($input, 'price'),
        inputString($input, 'sku'),
        (int) inputString($input, 'stock')
    );

    if (!empty($errors)) {
        return [
            'status' => 409,
            'body' => ['success' => false, 'error' => 'Товар з таким SKU вже існує.'],
        ];
    }

    return [
        'status' => 201,
        'body' => ['success' => true, 'data' => getProductById($pdo, (int) $pdo->lastInsertId())],
    ];
}

/**
 * Доменна дія — поповнення залишку (action=restock): {"id": 2, "quantity": 10}.
 */
function restockProduct(PDO $pdo, array $input): array
{
    $idRaw = inputString($input, 'id');
    $id = $idRaw === '' ? false : filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false) {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => 'Поле id обов\'язкове і має бути цілим числом.'],
        ];
    }

    $errors = validateRestockQuantity(inputString($input, 'quantity'));

    if (!empty($errors)) {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => $errors['quantity']],
        ];
    }

    $errors = increaseStock($pdo, (int) $id, (int) inputString($input, 'quantity'));

    if (isset($errors['product'])) {
        return [
            'status' => 404,
            'body' => ['success' => false, 'error' => "Товар з id {$id} не знайдено."],
        ];
    }

    if (!empty($errors)) {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => $errors['quantity']],
        ];
    }

    return [
        'status' => 200,
        'body' => ['success' => true, 'data' => getProductById($pdo, (int) $id)],
    ];
}

/**
 * Читає тіло POST-запиту: JSON (php://input) або звичайну форму ($_POST).
 * Повертає null, якщо тіло заявлено як JSON, але це не коректний JSON-об'єкт/масив
 * (скаляр, битий JSON) — api.php відповідає 400 замість падіння з 500.
 */
function parseRequestInput(): ?array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (is_string($contentType) && stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw === false ? '' : $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    return $_POST;
}
