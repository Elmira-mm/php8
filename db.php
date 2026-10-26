<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';

// Параметри можна перевизначити змінними оточення (DB_HOST, DB_NAME, DB_USER, DB_PASS).
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'practicum4';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Справжні підготовлені запити на стороні СУБД: значення ніколи не стають частиною тексту SQL.
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // Деталі — в лог, користувачу — загальне повідомлення (без імені БД, хоста, логіна).
    error_log('[db] ' . $e->getMessage());
    renderFatal(500);
}
