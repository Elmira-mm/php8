<?php
declare(strict_types=1);

function cacheFilePath(string $key): string
{
    return sys_get_temp_dir() . '/practicum07_cache_' . md5($key) . '.json';
}

/**
 * Файловий кеш із TTL. Повертає ['value' => ..., 'cache' => 'hit'|'miss'].
 */
function cachedValue(string $key, int $ttlSeconds, callable $compute): array
{
    $file = cacheFilePath($key);

    if (is_file($file) && (time() - filemtime($file)) < $ttlSeconds) {
        // Файл міг зникнути між is_file() і читанням (паралельна інвалідація) —
        // тоді просто вважаємо це промахом.
        $raw = @file_get_contents($file);

        if ($raw !== false) {
            $cached = json_decode($raw, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return ['value' => $cached, 'cache' => 'hit'];
            }
        }
    }

    $value = $compute();

    // Атомарний запис: спочатку у тимчасовий файл, потім rename().
    // Так паралельний запит ніколи не прочитає наполовину записаний JSON.
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($value));
    rename($tmp, $file);

    return ['value' => $value, 'cache' => 'miss'];
}

/**
 * Явна інвалідація: викликається після кожного запису (INSERT/UPDATE/DELETE),
 * щоб кеш не показував застарілі дані до завершення TTL.
 */
function invalidateCache(string $key): void
{
    $file = cacheFilePath($key);

    if (is_file($file)) {
        @unlink($file);
    }
}
