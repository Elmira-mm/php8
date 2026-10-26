<?php
declare(strict_types=1);

/**
 * Підключається першим у кожній точці входу (сторінка або API).
 * HTML-сторінки одразу стартують сесію (до будь-якого виводу), щоб форми могли містити CSRF-токен;
 * API стартує сесію лише коли вона потрібна (запис / видача токена), тож читання не сповільнюється.
 */
require_once __DIR__ . '/security.php';

registerErrorHandling();
sendSecurityHeaders();

if (appOutputMode() === 'html') {
    startSecureSession();
}
