<?php

declare(strict_types=1);

namespace App;

final class Flash
{
    public static function add(string $type, string $message): void
    {
        $_SESSION['flash'][] = compact('type', 'message');
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function all(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $messages;
    }
}
