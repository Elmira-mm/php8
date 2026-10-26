<?php
declare(strict_types=1);

/**
 * Спільні засоби безпеки: екранування виводу, CSRF-токен, заголовки, обробка помилок.
 * Точка входу перед підключенням цього файлу може оголосити const APP_OUTPUT = 'json'
 * (для API); за замовчуванням — 'html'.
 */

function appOutputMode(): string
{
    return defined('APP_OUTPUT') ? (string) APP_OUTPUT : 'html';
}

/**
 * Екранування для виводу в HTML — і в тексті, і у значеннях атрибутів у лапках.
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Безпечне читання скалярного параметра як рядка: масив (?name[]=x), null чи об'єкт дають ''.
 * Числа з JSON-тіла (price: 10, stock: 5) приймаються й перетворюються на рядок.
 */
function inputString(array $source, string $key): string
{
    $value = $source[$key] ?? '';

    if (is_string($value)) {
        return trim($value);
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return '';
}

// ---------------------------------------------------------------- заголовки

function sendSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');

    if (appOutputMode() === 'html') {
        // Другий рубіж проти XSS: виконуються лише скрипти з нашого домену (жодних inline-скриптів).
        header(
            "Content-Security-Policy: default-src 'self'; script-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; img-src 'self' data:; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
        );
    }
}

// ---------------------------------------------------------------- відповіді-помилки

function renderSimplePage(int $status, string $title, string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">'
        . '<title>' . h($title) . '</title><link rel="stylesheet" href="style.css"></head><body>'
        . '<div class="hero"><span class="eyebrow">Wellness Store</span><h1>' . h($title) . '</h1></div>'
        . '<div class="card form-card"><p>' . h($message) . '</p>'
        . '<a href="index.php" class="btn-primary">До каталогу</a></div></body></html>';
    exit;
}

function renderJsonError(int $status, string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Загальна відповідь користувачу замість технічних деталей.
 */
function renderFatal(int $status = 500): void
{
    if (appOutputMode() === 'json') {
        renderJsonError($status, 'Внутрішня помилка сервера.');
    }

    renderSimplePage($status, 'Щось пішло не так', 'Сталася внутрішня помилка. Спробуйте пізніше.');
}

/**
 * Крок 6: деталі — лише в error_log, користувач бачить загальне повідомлення.
 */
function registerErrorHandling(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(function (Throwable $e): void {
        error_log(sprintf('[uncaught] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        renderFatal(500);
    });

    register_shutdown_function(function (): void {
        $error = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

        if ($error !== null && in_array($error['type'], $fatal, true) && !headers_sent()) {
            renderFatal(500);
        }
    });
}

// ---------------------------------------------------------------- сесія і CSRF

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function csrfToken(): string
{
    startSecureSession();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

/**
 * Токен із запиту: заголовок X-CSRF-Token (fetch/API) або поле форми csrf_token.
 */
function csrfSubmittedToken(): string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $field = $_POST['csrf_token'] ?? '';

    return is_string($field) ? $field : '';
}

/**
 * Увага: порожній токен у сесії НЕ вважається валідним. Без цієї перевірки
 * hash_equals('', '') повертає true, і запит без сесії та без токена пройшов би.
 */
function csrfIsValid(): bool
{
    startSecureSession();

    $expected = $_SESSION['csrf_token'] ?? '';
    $submitted = csrfSubmittedToken();

    return is_string($expected)
        && $expected !== ''
        && $submitted !== ''
        && hash_equals($expected, $submitted);
}

function requireValidCsrf(): void
{
    if (csrfIsValid()) {
        return;
    }

    error_log('[csrf] відхилено запит ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));

    if (appOutputMode() === 'json') {
        renderJsonError(403, 'Недійсний CSRF-токен.');
    }

    renderSimplePage(403, 'Запит відхилено', 'Недійсний CSRF-токен. Поверніться до форми, оновіть сторінку й спробуйте ще раз.');
}
