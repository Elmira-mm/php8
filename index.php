<?php
declare(strict_types=1);

const APP_OUTPUT = 'html';

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/products.php';

$products = getAllProducts($pdo);
$totalValue = totalStockValue($pdo);

$searchSku = inputString($_GET, 'sku');
$foundProduct = $searchSku !== '' ? findBySku($pdo, $searchSku) : null;

$flash = inputString($_GET, 'flash');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Каталог товарів — Wellness Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="hero">
        <span class="eyebrow">Практична робота №8 · Wellness Edit</span>
        <h1>Каталог <em>під захистом</em></h1>
        <p class="subtitle">Підготовлені запити, екранування виводу, CSRF-токени та POST для всіх змін даних.</p>
    </div>

    <?php if ($flash === 'added'): ?>
        <div class="alert alert-success"><strong>Товар додано.</strong></div>
    <?php elseif ($flash === 'updated'): ?>
        <div class="alert alert-success"><strong>Товар оновлено.</strong></div>
    <?php elseif ($flash === 'deleted'): ?>
        <div class="alert alert-success"><strong>Товар видалено.</strong></div>
    <?php elseif ($flash === 'restocked'): ?>
        <div class="alert alert-success"><strong>Залишок поповнено.</strong></div>
    <?php endif; ?>

    <div class="card form-card">
        <form method="get" action="index.php" class="search-form">
            <div class="field">
                <label for="sku">Пошук за SKU (findBySku)</label>
                <input type="text" id="sku" name="sku" value="<?= h($searchSku) ?>" placeholder="Напр. MAT-003">
            </div>
            <button type="submit" class="btn-primary">Знайти</button>
            <?php if ($searchSku !== ''): ?>
                <a href="index.php" class="btn-secondary-link">Скинути</a>
            <?php endif; ?>
        </form>

        <?php if ($searchSku !== ''): ?>
            <?php if ($foundProduct !== null): ?>
                <div class="alert alert-success">
                    <strong>Знайдено:</strong>
                    <span><?= h($foundProduct['name']) ?> — <?= number_format((float) $foundProduct['price'], 2, '.', ' ') ?> грн, залишок <?= (int) $foundProduct['stock'] ?> шт.</span>
                </div>
            <?php else: ?>
                <div class="alert alert-empty">
                    Товар з SKU «<?= h($searchSku) ?>» не знайдено.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>SKU</th>
                    <th>Ціна</th>
                    <th>Залишок, шт.</th>
                    <th>Статус</th>
                    <th>Дії</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <?php $inStock = (int) $product['stock'] > 0; ?>
                    <tr class="<?= $inStock ? 'row-in' : 'row-out' ?>">
                        <td><span class="product-name"><?= h($product['name']) ?></span></td>
                        <td class="stock"><?= h($product['sku']) ?></td>
                        <td>
                            <span class="price"><?= number_format((float) $product['price'], 2, '.', ' ') ?></span><span class="price-currency">грн</span>
                        </td>
                        <td class="stock"><?= (int) $product['stock'] ?></td>
                        <td>
                            <span class="badge <?= $inStock ? 'badge-in' : 'badge-out' ?>">
                                <?= $inStock ? 'В наявності' : 'Немає в наявності' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <a href="edit.php?id=<?= (int) $product['id'] ?>" class="action-link">Редагувати</a>
                            <a href="restock.php?id=<?= (int) $product['id'] ?>" class="action-link">Поповнити</a>
                            <form method="post" action="delete.php" class="inline-form"
                                  data-confirm="Видалити «<?= h($product['name']) ?>»?">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button type="submit" class="action-link action-link--danger">Видалити</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="6" class="empty-row">Товарів поки немає — додайте перший нижче.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="summary">
        <div class="summary-item">
            <div class="label">Сумарна вартість складу (SQL SUM)</div>
            <div class="value"><?= number_format($totalValue, 2, '.', ' ') ?> <span class="accent">грн</span></div>
        </div>
        <div class="summary-item">
            <div class="label">Товарів у каталозі</div>
            <div class="value"><?= count($products) ?></div>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="section-title">Додати товар</h2>
        <a href="add.php" class="btn-primary btn-block-link">Перейти до форми додавання</a>
        <a href="ajax.php" class="btn-secondary-link">AJAX-каталог (практикум №5)</a>
    </div>

    <script src="confirm.js"></script>
</body>
</html>
