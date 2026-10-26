# Звіт з практичної роботи №8

## Безпека PHP-застосунку: аудит та усунення вразливостей

| | |
|---|---|
| **Тема** | Безпека веб-застосунку на PHP + MySQL |
| **Варіант** | 2 — інтернет-магазин («Wellness Store», домен `products`) |
| **Виконавець** | _[ПІБ студента, група]_ |
| **Дата** | 26.10.2026 |
| **Середовище** | macOS, PHP 8.5.10 (вбудований сервер `php -S`), MySQL, `curl`, `bash`, `python3` |

---

## Зміст

1. [Мета роботи](#1-мета-роботи)
2. [Завдання варіанта](#2-завдання-варіанта)
3. [Методика перевірки](#3-методика-перевірки)
4. [Підсумкові результати](#4-підсумкові-результати)
5. [Хід виконання](#5-хід-виконання)
   - [5.1 SQL-ін'єкції](#51-sql-інєкції)
   - [5.2 XSS](#52-xss-поле-name)
   - [5.3 CSRF](#53-csrf)
   - [5.4 Валідація вхідних даних](#54-валідація-вхідних-даних)
   - [5.5 Дії, що змінюють дані, лише через POST](#55-дії-що-змінюють-дані-лише-через-post)
   - [5.6 Обробка помилок](#56-обробка-помилок)
   - [5.7 Додаткові заходи](#57-додаткові-заходи-заголовки-безпеки)
6. [Сумісність з практикумами №6 і №7](#6-сумісність-з-практикумами-6-і-7)
7. [Висновки](#7-висновки)
8. [Обмеження](#8-обмеження)
9. [Запуск і відтворення результатів](#9-запуск-і-відтворення-результатів)
10. [Структура проєкту](#10-структура-проєкту)
11. [Додаток А. Питання до захисту](#додаток-а-питання-до-захисту)

---

## 1. Мета роботи

Провести аудит безпеки застосунку, зібраного з практикумів №4–7 (класичні сторінки, AJAX-каталог,
власний JSON API, оптимізації), виявити вразливості за обраним варіантом, усунути їх і підтвердити
виправлення відтворюваними перевірками «до / після».

## 2. Завдання варіанта

| № | Клас вразливості | Об'єкт перевірки |
|---|---|---|
| 1 | SQL-ін'єкція | параметр пошуку за `sku` |
| 2 | XSS | поле `name` товару |
| 3 | CSRF | поповнення залишку (`stock`): дія `restock` в API (практикум №6) і форма `restock.php` |
| 4 | Незахищена дія | видалення товару через GET-посилання без підтвердження |

Додатково, за загальними вимогами практикуму: валідація вхідних даних на сервері та обробка помилок.

## 3. Методика перевірки

1. Статичний аналіз коду: перелік усіх місць звернення до БД і виводу даних.
2. Ручні запити `curl` із шкідливими payload-ами (в обхід HTML-форм, щоб не залежати від клієнтських обмежень).
3. Автоматичний набір `tests/audit.sh` (38 перевірок + службові): запускається на старому коді («до»)
   й на виправленому («після»), результати збережено у `tests/audit_before.txt` і `tests/audit_after.txt`.
4. Демонстрація CSRF з іншого джерела: `tests/csrf_attack_demo.html` (сторінка «зловмисника» на іншому порту).

«До» — код практикумів 4–5 (класичні сторінки, AJAX) і 6 (`api.php`, `lib/products.php`) без змін.

## 4. Підсумкові результати

### 4.1 Зведена таблиця

| Клас | До | Після |
|---|---|---|
| SQL-ін'єкція (`sku`) | **Вразливості немає:** усі запити підготовлені | Без змін; додатково `ATTR_EMULATE_PREPARES=false` |
| XSS (`name`) | **Знайдено:** inline-обробник `onsubmit` у `index.php` | Обробник прибрано (`data-confirm` + `confirm.js`), `h()` з `ENT_QUOTES`, CSP `script-src 'self'` |
| CSRF | **Знайдено:** `add`, `edit`, `delete`, `api_add.php`, `api.php` (створення та `restock`) без токена | CSRF-токен у сесії, перевірка в усіх діях, що змінюють дані (`403` без токена) |
| Дія через GET | GET не видаляв, але POST працював без токена, підтвердження лише в JS | GET → `405`; POST лише з CSRF-токеном |
| Валідація | Без верхніх меж і перевірки типів: `Fatal error`, `500`, запис «Array» у базу | Межі за схемою БД, перевірка типів і UTF-8, коректні `400` |
| Обробка помилок | `display_errors=1`, «сирі» `PDOException`; API без запису в лог | Деталі в `error_log()`, користувач бачить загальне повідомлення |

### 4.2 Автоматичний аудит

| Етап | PASS | FAIL | SKIP |
|---|---|---|---|
| До виправлень (`audit_before.txt`) | 13 | 21 | 4 |
| Після виправлень (`audit_after.txt`) | **40** | **0** | **0** |

У «до» чотири перевірки пропущено, бо відповідних функцій ще не існувало: форма `restock.php`,
пошук за `sku` в API, видача й використання CSRF-токена в API.

---

## 5. Хід виконання

### 5.1 SQL-ін'єкції

**Об'єкт:** пошук за `sku` (`index.php?sku=...`, `api.php?resource=products&sku=...`).

**Статичний аналіз.** Усі звернення до БД:

```
lib/products.php: $pdo->query('SELECT * FROM products ORDER BY id')           — константа, без вхідних даних
lib/products.php: $pdo->query('SELECT SUM(price * stock) ...')                — константа
lib/products.php: $pdo->prepare(...) × 8                                      — значення лише через :параметри
```

Пошук запитів, що будуються з `$_GET/$_POST` (у zsh `--include` потрібно брати в лапки, інакше shell
розкриває `*.php` як шаблон і повертає `zsh: no matches found`):

```bash
grep -rnE 'SELECT|INSERT|UPDATE|DELETE' --include='*.php' . | grep -E '\$_(GET|POST|REQUEST)'
```

Очікуваний результат — **збігів немає**. Єдине місце з динамічним текстом запиту —
`getProductsByPriceRange()`: туди потрапляють лише фіксовані фрагменти (`price >= :min`),
а значення передаються параметрами.

**Payload-и та результат:**

| Payload | Результат |
|---|---|
| `' OR '1'='1` | «не знайдено» (до і після) |
| `' UNION SELECT 1,2,3,4,5-- -` | «не знайдено» |
| `'; DROP TABLE products;-- ` | «не знайдено», таблиця ціла |

Ручна перевірка:

```
$ curl -s -G --data-urlencode "sku=' OR '1'='1" "http://localhost:8000/index.php" | grep -o "не знайдено"
не знайдено

$ curl -s -g "http://localhost:8000/api.php?resource=products&sku=%27%20OR%20%271%27%3D%271"
{"success":false,"error":"Товар не знайдено."}
```

**Висновок.** Вразливість **не виявлена**, виправлення коду не потрібні. Додатково в `db.php` вимкнено
емуляцію підготовлених запитів, щоб значення ніколи не підставлялися в текст SQL на стороні PHP:

```php
PDO::ATTR_EMULATE_PREPARES => false,
```

### 5.2 XSS (поле `name`)

**Payload-и** (вводилися як назва товару через форму додавання):

```
');alert('XSSMARK');//
<img src=x onerror=alert('XSSMARK')>
<script>alert('XSSMARK')</script>
```

**Виявлена вразливість.** Вивід у текст сторінки був екранований (`htmlspecialchars`), форма редагування
також безпечна. Проте в `index.php` назва товару вставлялася **у JavaScript усередині HTML-атрибута**:

```php
<form ... onsubmit="return confirm('Видалити «<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>»?');">
```

Браузер спочатку розкодовує HTML-сутності в атрибуті (`&#039;` → `'`) і лише потім виконує JavaScript,
тому HTML-екранування тут не захищає. Фактичний HTML для першого payload:

```html
<form method="post" action="delete.php" class="inline-form"
      onsubmit="return confirm('Видалити «&#039;);alert(&#039;XSSMARK&#039;);//»?');">
```

Після розкодування атрибут містить `confirm('Видалити «');alert('XSSMARK');//»?')`: ін'єкція виконується
під час спроби видалити такий товар. Автоматична перевірка (розбір HTML парсером) підтвердила це:
`в атрибуті onsubmit після розкодування HTML-сутностей: return confirm('Видалити «');alert('XSSMARK');//»?');`

**Виправлення.**

1. Текст підтвердження перенесено в `data-`атрибут (звичайний текст), а обробник — у зовнішній `confirm.js`:

   ```php
   <form method="post" action="delete.php" class="inline-form" data-confirm="Видалити «<?= h($product['name']) ?>»?">
   ```
   ```js
   document.addEventListener('submit', function (event) {
       var message = event.target.getAttribute && event.target.getAttribute('data-confirm');
       if (message && !window.confirm(message)) { event.preventDefault(); }
   });
   ```

2. Єдина функція екранування для всього виводу (`ENT_QUOTES` екранує і подвійні, і одинарні лапки):

   ```php
   function h($value): string
   {
       return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   }
   ```

3. У `app.js` функція `escapeHtml` тепер екранує також `"` і `'` (раніше лише `& < >`).

4. Другий рубіж захисту: `Content-Security-Policy: ... script-src 'self'` забороняє inline-скрипти та
   обробники подій (на `index.php` їх тепер 0).

**Перевірка після виправлення.**

```
$ curl -s -b jar.txt -o /dev/null -w "%{http_code}\n" -X POST --data-urlencode "name=');alert('XSS');//" \
       -d "price=10&sku=XSS-1&stock=1" --data-urlencode "csrf_token=$TOKEN" "http://localhost:8000/add.php"
302

$ curl -s "http://localhost:8000/index.php" | grep "data-confirm" | tail -1
data-confirm="Видалити «&#039;);alert(&#039;XSS&#039;);//»?">
```

Payload потрапляє у звичайний текст атрибута й не виконується. `tests/audit.sh` додатково перевіряє
`index.php` та `edit.php` розбором HTML (відсутність `<img>`, inline-`<script>`, обробників подій).
API повертає `Content-Type: application/json` з `X-Content-Type-Options: nosniff`.

### 5.3 CSRF

**Виявлена вразливість.** Жодна дія, що змінює дані, не перевіряла походження запиту: `add.php`, `edit.php`,
`delete.php`, `api_add.php` та `api.php` (створення й **`action=restock`**). Застосунок не має автентифікації,
тому реальна загроза — сторінка зловмисника, відкрита в браузері користувача, яка надсилає запит
на адресу застосунку (`localhost`, інтранет). Демонстрація: `tests/csrf_attack_demo.html`.

**Доменна дія варіанта (`restock`).** HTML-форма на чужому сайті може надіслати
`application/x-www-form-urlencoded` запит на `api.php?resource=products&action=restock`, а
`parseRequestInput()` читає такі поля з `$_POST`. Перевірка «до» (товар із залишком 5):

```
curl -X POST -d "id=7&quantity=1000" "http://localhost:8000/api.php?resource=products&action=restock"
→ HTTP 200, "stock": 1005
```

**Результат автоматичної перевірки «до»:**

```
[FAIL] delete.php без токена і без сесії      -> HTTP 302 — товар видалено сторонньою формою
[FAIL] delete.php з хибним токеном            -> HTTP 302 — товар видалено
[FAIL] add.php без токена                     -> HTTP 302 — товар створено сторонньою формою
[FAIL] edit.php без токена                    -> HTTP 302 — товар змінено сторонньою формою
[FAIL] API POST без токена                    -> HTTP 201 — API приймає запис без CSRF-токена
[FAIL] api_add.php без токена                 -> HTTP 200 — AJAX-ендпоінт приймає запис без CSRF-токена
[FAIL] API restock «з чужої форми» без токена -> HTTP 200, залишок тепер 1005 (було 5)
```

**Виправлення** (`lib/security.php`). Токен створюється для сесії, вставляється в кожну форму
(`<?= csrfField() ?>`) і в AJAX-форму (поле потрапляє у `FormData`); клієнти API передають його заголовком
`X-CSRF-Token` (токен видає `GET /api.php?resource=csrf`). В `api.php` перевірка стоїть на вході в гілку
`POST`, тому покриває і створення, і `restock`:

```php
function csrfToken(): string
{
    startSecureSession();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfIsValid(): bool
{
    startSecureSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    $submitted = csrfSubmittedToken();   // заголовок X-CSRF-Token або поле csrf_token

    return is_string($expected) && $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);
}

// api.php
if ($method === 'POST') {
    requireValidCsrf();      // 403 {"success":false,"error":"Недійсний CSRF-токен."} без дійсного токена
    ...
}
```

> **Важливий нюанс.** Перевірка `$expected !== ''` обов'язкова: у типовому навчальному прикладі
> `hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')` запит **без сесії й без токена**
> дає `hash_equals('', '')` = `true` і проходить перевірку. Цей випадок має окремий тест
> («порожній токен без сесії»). Сесійна cookie має прапорці `HttpOnly` і `SameSite=Lax`.

Форма `restock.php` і API-дія `restock` використовують одну функцію `increaseStock()`: кількість 1…100000,
атомарне `UPDATE products SET stock = stock + :qty WHERE id = :id AND stock <= :limit` (захист від
переповнення `INT`), після запису кеш агрегату інвалідується.

**Ручна перевірка «після»:**

```
# запит «з чужого сайту»: без cookie і без токена
$ curl -i -X POST -d "id=1&quantity=5" "http://localhost:8000/api.php?resource=products&action=restock" | head -1
HTTP/1.1 403 Forbidden

# легітимний клієнт: спершу токен (cookie сесії зберігається в jar.txt), потім запит із заголовком
$ TOKEN=$(curl -s -c jar.txt "http://localhost:8000/api.php?resource=csrf" | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['csrf_token'])")
$ curl -s -b jar.txt -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" -X POST \
       -d '{"id":1,"quantity":5}' "http://localhost:8000/api.php?resource=products&action=restock"
{"success":true,"data":{"id":1,"name":"Ceremonial Matcha Kyoto","price":"899.00","sku":"MAT-003","stock":19}}
```

Залишок змінився лише в запиті з токеном (14 → 19); запит без токена відхилено.

**Результат автоматичної перевірки «після»:**

```
[PASS] delete.php без токена і без сесії -> 403, товар на місці
[PASS] delete.php з хибним токеном -> 403, товар на місці
[PASS] delete.php з порожнім токеном без сесії -> 403, товар на місці
[PASS] add.php без токена -> 403, товар не створено
[PASS] edit.php без токена -> 403, товар не змінено
[PASS] restock.php без токена -> 403
[PASS] restock.php зі старим/хибним токеном -> 403
[PASS] restock.php з дійсним токеном -> 302 (поповнення працює)
[PASS] API POST без токена -> 403
[PASS] api_add.php (AJAX-форма) без токена -> 403
[PASS] API POST з дійсним X-CSRF-Token -> 201
[PASS] API restock «з чужої форми» (urlencoded, без cookie і токена) -> 403, залишок не змінено
[PASS] API restock з дійсним токеном -> 200, залишок 5 -> 10
```

### 5.4 Валідація вхідних даних

**Виявлені недоліки.** Обов'язкові поля й раніше перевірялися на сервері (порожні поля та від'ємна ціна
відхиляються — перевірено прямим `curl` в обхід форми). Але бракувало верхніх меж і перевірки типів, тому
значення, коректні за форматом, але некоректні за змістом, призводили до помилок або «сміття» в базі.

| Вхідні дані (надсилалися напряму) | До | Після |
|---|---|---|
| форма: назва 300 символів | `Fatal error: Uncaught PDOException ... Data too long for column 'name'` | помилка валідації у формі |
| форма: ціна `1000000000000`, залишок `99999999999`, SKU > 20 символів, назва з некоректним UTF-8 (`%FF%FE`) | `Fatal error` | помилка валідації |
| API: `{"name":["x"],...}` (масив замість рядка) | `201`, у базі товар «**Array**» | `400`, нічого не записано |
| API: назва 300 символів | `500` | `400` з `fields` |
| API restock: `quantity` = `99999999999` (переповнення `INT`) | `500` | `400` |
| API: тіло — JSON-скаляр (`5`) | `500` (TypeError) | `400` |

**Виправлення** (`lib/products.php`; межі відповідають схемі БД):

```php
const MAX_NAME_LENGTH = 255;  const MAX_SKU_LENGTH = 20;
const MAX_PRICE = 99999999.99; const MAX_STOCK = 2147483647;

} elseif (!preg_match('//u', $name)) {
    $errors['name'] = 'Назва містить недопустимі символи.';
} elseif (textLength(trim($name)) > MAX_NAME_LENGTH) {
    $errors['name'] = 'Назва товару не може бути довшою за ' . MAX_NAME_LENGTH . ' символів.';
}
```

Усі вхідні значення читаються через `inputString()`: масив, `null`, булеве чи вкладений об'єкт дають порожній
рядок (поле вважається відсутнім), а числа з JSON приймаються як рядки — тому `name: ["x"]` більше не
перетворюється на «Array». `parseRequestInput()` повертає `null` для битого JSON чи скаляра, і `api.php`
відповідає `400` замість падіння. Атрибути `maxlength`/`max` у HTML-формах — лише зручність для користувача;
рішення ухвалює сервер.

**Ручна перевірка «після»** (`$API="http://localhost:8000/api.php?resource=products"`, запити з токеном):

```
$ curl -s "${H[@]}" -X POST -d '{"name":["x"],"price":10,"sku":"TST-901","stock":1}' "$API"
{"success":false,"error":"Некоректні дані.","fields":{"name":"Назва товару обов'язкова."}}

$ curl -s "${H[@]}" -X POST -d '{"id":1,"quantity":99999999999}' "$API&action=restock"
{"success":false,"error":"Кількість має бути цілим числом від 1 до 100000."}

$ curl -s "${H[@]}" -X POST -d '5' "$API"
{"success":false,"error":"Тіло запиту має бути коректним JSON-об'єктом."}
```

### 5.5 Дії, що змінюють дані, лише через POST

**Стан «до».** Видалення вже виконувалося формою `POST`, а `delete.php` ігнорував GET: перехід за
`delete.php?id=N` товар **не видаляв**. Проте POST приймався без токена (див. 5.3), а підтвердження
існувало лише на клієнті.

**Виправлення** (`delete.php`):

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    renderSimplePage(405, 'Метод не підтримується', 'Видалення можливе лише через форму (метод POST).');
}
requireValidCsrf();
```

**Результат:**

```
$ curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8000/delete.php?id=1"
405
```

`GET delete.php?id=N` → **405**, товар не видалено; POST без токена → **403**. Підтвердження `confirm()`
(через `data-confirm`) залишено як зручність, але воно не є захистом: його легко обійти прямим запитом,
тому справжній захист — POST + CSRF-токен на сервері. API за контрактом практикуму №6 видалення не
підтримує (`DELETE` → `405` із заголовком `Allow: GET, POST`).

### 5.6 Обробка помилок

**Стан «до».** У `index.php`, `add.php`, `edit.php` було `ini_set('display_errors', '1')`, тож користувач бачив:

```
Fatal error:  Uncaught PDOException: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 in .../lib/products.php:47
Warning:  Array to string conversion in .../index.php on line 13
```

Це розкриває структуру бази (назви стовпців, шляхи файлів, фрагменти SQL). В `api.php` `display_errors` був
вимкнений, але блок `catch` **нічого не записував у лог**, тож причину помилки з'ясувати було неможливо.
Також параметр `action[]=x` повертав у відповіді «action=Array».

**Виправлення** (`lib/security.php`, підключається першим у кожній точці входу; `api.php`):

```php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $e): void {
    error_log(sprintf('[uncaught] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    renderFatal(500);          // користувач бачить лише загальне повідомлення
});

// api.php
} catch (Throwable $e) {
    error_log(sprintf('[api] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    respond(500, ['success' => false, 'error' => 'Внутрішня помилка сервера.']);
}
```

**Перевірка з недоступною базою** (окремий сервер на порту 8004 з неіснуючою БД):

```
$ DB_NAME=nonexistent_db php -S localhost:8004
[Mon Oct 26 22:12:25 2026] [db] SQLSTATE[HY000] [1049] Unknown database 'nonexistent_db'
[Mon Oct 26 22:12:25 2026] [::1]:58843 [500]: GET /

$ curl -s "http://localhost:8004/api.php?resource=products"
{"success":false,"error":"Внутрішня помилка сервера."}
```

Користувач бачить лише загальне повідомлення (HTTP 500), а деталі (`Unknown database 'nonexistent_db'`)
потрапляють тільки в лог сервера. Для HTML-сторінок показується «Щось пішло не так. Сталася внутрішня
помилка». У `db.php` текст помилки підключення не виводиться, а пишеться в лог.

### 5.7 Додаткові заходи: заголовки безпеки

- Усі відповіді: `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`.
- HTML-сторінки додатково: `Content-Security-Policy: default-src 'self'; script-src 'self'; ... frame-ancestors 'none'`.

---

## 6. Сумісність з практикумами №6 і №7

Контракт `API.md` збережено (`GET`, `GET ?id=`, `POST`, `POST ?action=restock`, коди `200/201/400/404/405/409`).
Додано лише: код `403` для POST без токена, `GET ?resource=csrf`, пошук `&sku=` та поле `meta` у списку
(практикум №7: `mode=naive`, кеш із TTL, що скидається після створення й поповнення).

Перевірено: режим `naive` робить 1 + N запитів, `optimized` — 2 при `miss` і 1 при `hit`; після `POST`
і `restock` наступний запит — `miss` з оновленою сумою.

## 7. Висновки

1. Проведено аудит за чотирма класами вразливостей варіанта 2 та двома загальними вимогами (валідація, помилки).
2. **SQL-ін'єкції:** вразливості не виявлено — усі запити підготовлені; захист посилено вимкненням емуляції.
3. **XSS:** виявлено нетривіальну вразливість — HTML-екранування не захищає, коли значення потрапляє
   в JavaScript усередині атрибута. Усунено винесенням обробника в `confirm.js`, `data-confirm`, `h()` з
   `ENT_QUOTES` і CSP.
4. **CSRF:** токенів не було ніде, зокрема на `restock` в API (чужа форма збільшила залишок з 5 до 1005).
   Додано токен у сесії з перевіркою `hash_equals` і непорожності в усіх POST-діях.
5. **Дія через GET:** GET не видаляв, але POST був без токена; тепер GET → `405`, POST лише з токеном.
6. **Валідація й помилки:** додано межі за схемою БД, перевірку типів і UTF-8; помилки логуються,
   користувач бачить загальне повідомлення.
7. Результат автоматичного аудиту: **до — 21 FAIL (13 PASS, 4 SKIP), після — 0 FAIL із 40**.

## 8. Обмеження

- У застосунку немає автентифікації користувачів: CSRF-токен прив'язано до сесії браузера, а не до облікового запису.
- Токен один на сесію (не змінюється після кожного запиту) — для навчального застосунку достатньо.
- CSP дозволяє `'unsafe-inline'` лише для стилів (є inline-атрибути `style`); скрипти — тільки з власного домену.
- Не реалізовано обмеження частоти запитів (rate limiting) і HTTPS (прапорець `secure` для cookie
  вмикається автоматично за HTTPS).

---

## 9. Запуск і відтворення результатів

```bash
mysql -u root < schema.sql          # лише на порожній базі; на існуючій practicum4 схему не запускати
php -S localhost:8000               # з папки lab8
```

Підключення до БД: за замовчуванням `localhost`, база `practicum4`, користувач `root` без пароля;
перевизначається змінними оточення `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

Автоматичний аудит (створює тимчасові товари `AUDIT*` і видаляє їх):

```bash
BASE=http://localhost:8000 bash tests/audit.sh | tee tests/audit_after.txt
```

Демонстрація CSRF: у папці `tests` виконати `python3 -m http.server 9000`, відкрити
`http://localhost:9000/csrf_attack_demo.html` і натиснути кнопку (застосунок має працювати на порту 8000).

Очищення після ручних перевірок:

```bash
mysql -u root practicum4 -e "DELETE FROM products WHERE sku IN ('XSS-1','TST-901');"
rm -f jar.txt
```

## 10. Структура проєкту

```
lab8/
├── index.php, add.php, edit.php, delete.php   — каталог і CRUD (практикум №4), тепер з CSRF, h(), POST
├── restock.php                                 — форма поповнення залишку (практикум №8)
├── ajax.php, app.js, api_list.php, api_add.php — AJAX-каталог (практикум №5)
├── api.php, API.md                             — власний API (№6) + meta/кеш (№7) + CSRF (№8)
├── confirm.js                                  — підтвердження дій без inline-JavaScript
├── db.php                                      — підключення PDO без витоку деталей помилки
├── style.css, schema.sql
├── lib/
│   ├── products.php                            — робота з БД і функції API (createProduct, restockProduct, parseRequestInput)
│   ├── cache.php, QueryCounter.php             — кеш і лічильник запитів (практикум №7)
│   ├── security.php                            — h(), CSRF, заголовки, обробка помилок
│   └── bootstrap.php                           — підключається першим у кожній точці входу
└── tests/
    ├── audit.sh, audit_before.txt, audit_after.txt
    └── csrf_attack_demo.html                   — «шкідлива» сторінка для демонстрації CSRF
```

---

## Додаток А. Питання до захисту

| Питання | Коротка відповідь |
|---|---|
| Чому `htmlspecialchars` не захистив в `onsubmit`? | Браузер розкодовує HTML-сутності в атрибуті перед виконанням JavaScript. |
| Чому `confirm()` — не захист? | Його обходять прямим запитом; захист — POST + CSRF-токен на сервері. |
| Чому перевіряти, що токен не порожній? | `hash_equals('', '')` повертає `true`: запит без сесії й токена пройшов би перевірку. |
| Чому API без логіну потребує CSRF? | Чужий сайт у браузері користувача може звертатися до `localhost` / інтранету. |
| Чому SQL-ін'єкції не було? | Усі запити з користувацькими значеннями підготовлені, значення передаються лише параметрами. |
| Що не покрито? | Автентифікація, rate limiting, HTTPS. |
