<?php

declare(strict_types=1);

namespace NeonLib;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null
    ) {
        parent::__construct($message);
    }
}
