<?php

declare(strict_types=1);

const ROOT_PATH = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = ROOT_PATH . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

App\Config::load(ROOT_PATH . '/.env');
date_default_timezone_set(App\Config::appTimezone());

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(?float $amount, ?string $currency = 'MXN'): string
{
    return sprintf('%s %0.2f', $currency ?: 'MXN', $amount ?? 0);
}
