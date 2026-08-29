<?php
declare(strict_types=1);
namespace NeonLib;

final class RequestContext
{
    private static string $id;

    public static function initialize(?string $candidate): void
    {
        self::$id = is_string($candidate) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate)
            ? $candidate : bin2hex(random_bytes(16));
        header('X-Request-Id: ' . self::$id);
    }

    public static function id(): string
    {
        return self::$id ??= bin2hex(random_bytes(16));
    }
}
