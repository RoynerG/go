<?php

declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function to(string $path): string
    {
        $base = rtrim(Env::get('APP_BASE_PATH', '') ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function redirect(string $path): void
    {
        header('Location: ' . self::to($path));
        exit;
    }
}
