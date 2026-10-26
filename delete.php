<?php
declare(strict_types=1);

const APP_OUTPUT = 'html';

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/products.php';

// Змінювати дані можна лише методом POST: перехід за посиланням (GET) нічого не видаляє.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    renderSimplePage(405, 'Метод не підтримується', 'Видалення можливе лише через форму (метод POST).');
}

requireValidCsrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id !== null && $id !== false) {
    deleteProduct($pdo, $id);
    header('Location: index.php?flash=deleted');
    exit;
}

header('Location: index.php');
exit;
