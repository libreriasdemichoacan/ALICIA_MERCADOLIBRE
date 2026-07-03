<?php

declare(strict_types=1);

namespace App;

final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null || $value === '' ? Config::get($key, $default) : (string) $value;
    }

    public static function set(string $key, ?string $value, bool $secret = false): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, is_secret) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)'
        );
        $stmt->execute([$key, $value, $secret ? 1 : 0]);
    }

    /** @return array<string,string> */
    public static function allPublic(): array
    {
        $rows = Database::connection()->query('SELECT setting_key, setting_value FROM app_settings WHERE is_secret = 0')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }
}
