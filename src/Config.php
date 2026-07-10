<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $value = trim($value, "\"'");
                self::$values[$key] = $value;
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key) ?: self::$values[$key] ?? null;
        return $value === null || $value === '' ? $default : (string) $value;
    }


    public static function appTimezone(): string
    {
        return self::get('APP_TIMEZONE', 'America/Mexico_City') ?: 'America/Mexico_City';
    }

    public static function dbTimezoneOffset(): string
    {
        $timezone = new \DateTimeZone(self::appTimezone());
        $now = new \DateTimeImmutable('now', $timezone);
        $offset = $timezone->getOffset($now);
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);
        $hours = intdiv($offset, 3600);
        $minutes = intdiv($offset % 3600, 60);

        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }

    public static function dbDsn(): string
    {
        $host = self::get('DB_HOST', '127.0.0.1');
        $port = self::get('DB_PORT', '3306');
        $database = self::get('DB_DATABASE', 'mercadolibre_alicia');

        return "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    }
}
