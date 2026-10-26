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

$existing = getProductById($pdo, $id);

if ($existing === null) {
    header('Location: index.php');
    exit;
}

$errors = [];
$values = [
    'name'  => (string) $existing['name'],
    'price' => (string) $existing['price'],
    'sku'   => (string) $existing['sku'],
    'stock' => (string) $existing['stock'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    foreach ($values as $field => $_) {
        $values[$field] = inputString($_POST, $field);
    }

    $errors = validateProductInput($values['name'], $values['price'], $values['sku'], $values['stock']);

    if (empty($errors)) {
        $errors = updateProduct($pdo, $id, $values['name'], (float) $values['price'], $values['sku'], (int) $values['stock']);

        if (empty($errors)) {
            header('Location: index.php?flash=updated');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Редагувати товар — Wellness Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="hero">
        <span class="eyebrow">Практична робота №8 · Wellness Edit</span>
        <h1>Редагувати <em>товар</em></h1>
        <p class="subtitle">Зміни зберігаються через підготовлений UPDATE; форма захищена CSRF-токеном.</p>
    </div>

    <div class="card form-card">
        <form method="post" action="edit.php" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="field">
                <label for="name">Назва товару</label>
                <input type="text" id="name" name="name" value="<?= h($values['name']) ?>" required minlength="2" maxlength="255">
                <?php if (isset($errors['name'])): ?><span class="error"><?= h($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="price">Ціна, грн</label>
                    <input type="number" id="price" name="price" value="<?= h($values['price']) ?>" required min="0.01" max="99999999.99" step="0.01">
                    <?php if (isset($errors['price'])): ?><span class="error"><?= h($errors['price']) ?></span><?php endif; ?>
                </div>

                <div class="field">
                    <label for="stock">Залишок, шт.</label>
                    <input type="number" id="stock" name="stock" value="<?= h($values['stock']) ?>" required min="0" max="2147483647" step="1">
                    <?php if (isset($errors['stock'])): ?><span class="error"><?= h($errors['stock']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label for="sku">SKU (формат ABC-123)</label>
                <input type="text" id="sku" name="sku" value="<?= h($values['sku']) ?>" required pattern="[A-Za-z]+-\d+" maxlength="20">
                <?php if (isset($errors['sku'])): ?><span class="error"><?= h($errors['sku']) ?></span><?php endif; ?>
            </div>

            <button type="submit" class="btn-primary">Оновити товар</button>
            <a href="index.php" class="btn-secondary-link">Назад до каталогу</a>
        </form>
    </div>
</body>
</html>
