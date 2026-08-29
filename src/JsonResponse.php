<?php

declare(strict_types=1);

namespace NeonLib;

final class JsonResponse
{
    public static function noContent(): never
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        exit;
    }

    public static function send(mixed $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function error(int $statusCode, string $code, string $message, ?array $details = null): never
    {
        $payload = [
            'requestId' => RequestContext::id(),
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if ($details !== null) {
            $payload['error']['details'] = $details;
        }
        self::send($payload, $statusCode);
    }
}
