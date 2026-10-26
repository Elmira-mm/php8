# API каталогу товарів (`resource=products`)

Єдина точка входу: `api.php`. Усі запити йдуть на цей файл з параметрами
в рядку запиту (`resource`, `id`, `action`).

Узгоджений формат відповіді:
- Успіх: `{"success": true, "data": ...}`
- Помилка: `{"success": false, "error": "..."}`

**Безпека (практикум №8).** Кожен POST-запит вимагає CSRF-токен: заголовок `X-CSRF-Token: <токен>`
(або поле форми `csrf_token`). Без дійсного токена відповідь — `403 Forbidden`. Токен видає
`GET /api.php?resource=csrf` (потрібно зберігати cookie сесії, наприклад `curl -c jar -b jar`).

---

## GET /api.php?resource=csrf

Повертає CSRF-токен поточної сесії: `{"success": true, "data": {"csrf_token": "..."}}`.

---

## GET /api.php?resource=products

Повертає список усіх товарів.

**Код відповіді:** `200 OK`

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Ceremonial Matcha Kyoto", "price": "899.00", "sku": "MAT-003", "stock": 14 },
    { "id": 2, "name": "Resistance Bands Set", "price": "699.00", "sku": "GEAR-014", "stock": 18 }
  ],
  "meta": { "mode": "optimized", "query_count": 2, "elapsed_ms": 2.02, "memory_mb": 2, "cache": "miss", "total_stock_value": 12345.67 }
}
```

Поле `meta` (практикум №7) — профілювання: `mode=naive` вмикає N+1-режим для порівняння,
за замовчуванням сума складу береться одним агрегатним запитом із файловим кешем (TTL 60 с),
який скидається після кожного створення товару чи поповнення залишку.

Додаткові параметри читання:
- `&sku=MAT-003` — пошук за SKU (`200`, або `404`, якщо немає; значення передається лише параметром підготовленого запиту);
- `&price_min=100&price_max=150` — фільтр за діапазоном ціни (`400`, якщо значення не числа).

---

## GET /api.php?resource=products&id=1

Повертає один товар за `id`.

**Коди відповіді:**
- `200 OK` — товар знайдено
- `400 Bad Request` — `id` не є цілим числом
- `404 Not Found` — товару з таким `id` немає в таблиці

```json
{ "success": true, "data": { "id": 1, "name": "Ceremonial Matcha Kyoto", "price": "899.00", "sku": "MAT-003", "stock": 14 } }
```

```json
{ "success": false, "error": "Товар з id 999 не знайдено." }
```

---

## POST /api.php?resource=products

Створює новий товар. Тіло запиту — JSON (`Content-Type: application/json`)
або звичайна форма (`$_POST`). Обов'язкові поля: `name`, `price`, `sku`, `stock`.

**Приклад тіла запиту:**
```json
{ "name": "Matcha Latte Blend 150g", "price": 489, "sku": "MAT-004", "stock": 20 }
```

**Коди відповіді:**
- `201 Created` — товар створено
- `400 Bad Request` — не вистачає обов'язкового поля, поле некоректного типу/формату або виходить за межі (name ≤ 255 символів, sku ≤ 20, price ≤ 99 999 999.99, stock ≤ 2 147 483 647), або тіло JSON некоректне (у відповіді є `fields` з переліком помилок за кожним полем)
- `403 Forbidden` — відсутній або недійсний CSRF-токен
- `409 Conflict` — товар з таким `sku` вже існує

```json
{ "success": true, "data": { "id": 7, "name": "Matcha Latte Blend 150g", "price": "489.00", "sku": "MAT-004", "stock": 20 } }
```

```json
{ "success": false, "error": "Некоректні дані.", "fields": { "price": "Ціна має бути числом більшим за 0." } }
```

---

## POST /api.php?resource=products&action=restock

Доменна дія — поповнює залишок (`stock`) наявного товару на вказану кількість.

**Приклад тіла запиту:**
```json
{ "id": 2, "quantity": 10 }
```

**Коди відповіді:**
- `200 OK` — залишок оновлено, повертається товар з новим `stock`
- `400 Bad Request` — `id` або `quantity` відсутні/некоректні (quantity — ціле від 1 до 100000; результат не може перевищити межу INT)
- `403 Forbidden` — відсутній або недійсний CSRF-токен
- `404 Not Found` — товару з таким `id` не існує

```json
{ "success": true, "data": { "id": 2, "name": "Resistance Bands Set", "price": "699.00", "sku": "GEAR-014", "stock": 28 } }
```

---

## Інші випадки

- Будь-який ресурс, відмінний від `products` → `404 Not Found`
- Метод, не підтримуваний для `products` (напр. `DELETE`, `PUT`) → `405 Method Not Allowed` (заголовок `Allow: GET, POST`)
- Невідоме значення `action` у POST-запиті → `400 Bad Request`
- Внутрішня помилка → `500` із загальним повідомленням; деталі пишуться лише в `error_log` сервера
