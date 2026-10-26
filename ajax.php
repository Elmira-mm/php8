<?php
declare(strict_types=1);

const APP_OUTPUT = 'html';

require_once __DIR__ . '/lib/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>AJAX-каталог — Wellness Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="hero">
        <span class="eyebrow">Практична робота №8 · Wellness Edit</span>
        <h1>Каталог <em>без перезавантаження</em></h1>
        <p class="subtitle">Список завантажується через fetch() у форматі JSON, пошук фільтрує вміст на льоту, нові товари додаються без reload. Додавання захищене CSRF-токеном.</p>
    </div>

    <div id="add-success" class="alert alert-success" style="display:none;">
        <strong>Товар додано.</strong>
    </div>

    <div class="card form-card">
        <div class="field">
            <label for="search-input">Пошук за назвою (жива фільтрація)</label>
            <input type="text" id="search-input" placeholder="Почніть вводити назву товару…">
        </div>
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
                </tr>
            </thead>
            <tbody id="catalog-body">
                <tr><td colspan="5" class="empty-row">Завантаження…</td></tr>
            </tbody>
        </table>
    </div>

    <div class="summary">
        <div class="summary-item">
            <div class="label">Сумарна вартість складу</div>
            <div class="value"><span id="total-value">0,00 грн</span></div>
        </div>
    </div>

    <div class="card form-card">
        <h2 class="section-title">Додати товар (AJAX)</h2>
        <form id="add-form" novalidate>
            <?= csrfField() ?>

            <div class="field">
                <label for="name">Назва товару</label>
                <input type="text" id="name" name="name" required minlength="2" maxlength="255">
                <span class="error" data-error-for="name"></span>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="price">Ціна, грн</label>
                    <input type="number" id="price" name="price" required min="0.01" max="99999999.99" step="0.01">
                    <span class="error" data-error-for="price"></span>
                </div>

                <div class="field">
                    <label for="stock">Залишок, шт.</label>
                    <input type="number" id="stock" name="stock" required min="0" max="2147483647" step="1">
                    <span class="error" data-error-for="stock"></span>
                </div>
            </div>

            <div class="field">
                <label for="sku">SKU (формат ABC-123)</label>
                <input type="text" id="sku" name="sku" required pattern="[A-Za-z]+-\d+" maxlength="20" placeholder="MAT-045">
                <span class="error" data-error-for="sku"></span>
            </div>

            <button type="submit" class="btn-primary">Зберегти товар (без перезавантаження)</button>
            <a href="index.php" class="btn-secondary-link">Назад до каталогу</a>
        </form>
    </div>

    <script src="app.js"></script>
</body>
</html>
