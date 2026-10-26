<?php
declare(strict_types=1);

final class QueryCounter
{
    private static int $count = 0;

    public static function increment(): void
    {
        self::$count++;
    }

    public static function get(): int
    {
        return self::$count;
    }

    public static function reset(): void
    {
        self::$count = 0;
    }
}
