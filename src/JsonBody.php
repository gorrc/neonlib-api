<?php
declare(strict_types=1);
namespace NeonLib;

use JsonException;

final class JsonBody
{
    public static function decode(string $body): array
    {
        try {
            $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'invalid_json', 'Request body must be valid JSON.');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ApiException(400, 'invalid_request', 'Request body must be a JSON object.');
        }
        return $value;
    }
}
