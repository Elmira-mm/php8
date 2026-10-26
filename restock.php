<?php
declare(strict_types=1);

const APP_OUTPUT = 'html';

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/products.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === null || $id === false) {
    header('Location: index.php');
    exit;
}

$product = getProductById($pdo, $id);

if ($product === null) {
    header('Location: index.php');
    exit;
}

$errors = [];
$quantity = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $quantity = inputString($_POST, 'quantity');
    $errors = validateRestockQuantity($quantity);

    if (empty($errors)) {
        $errors = increaseStock($pdo, $id, (int) $quantity);

        if (empty($errors)) {
            header('Location: index.php?flash=restocked');
            exit;
        }
    }

    // дані для відображення беремо заново: залишок міг змінитися
    $product = getProductById($pdo, $id) ?? $product;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Поповнити залишок — Wellness Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="hero">
        <span class="eyebrow">Практична робота №8 · Wellness Edit</span>
        <h1>Поповнити <em>залишок</em></h1>
        <p class="subtitle"><?= h($product['name']) ?> (<?= h($product['sku']) ?>) — зараз на складі <?= (int) $product['stock'] ?> шт.</p>
    </div>

    <div class="card form-card">
        <form method="post" action="restock.php" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="field">
                <label for="quantity">Скільки одиниць додати</label>
                <input type="number" id="quantity" name="quantity" value="<?= h($quantity) ?>" required min="1" max="100000" step="1">
                <?php if (isset($errors['quantity'])): ?><span class="error"><?= h($errors['quantity']) ?></span><?php endif; ?>
                <?php if (isset($errors['product'])): ?><span class="error"><?= h($errors['product']) ?></span><?php endif; ?>
            </div>

            <button type="submit" class="btn-primary">Поповнити</button>
            <a href="index.php" class="btn-secondary-link">Назад до каталогу</a>
        </form>
    </div>
</body>
</html>
