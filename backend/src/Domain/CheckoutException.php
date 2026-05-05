<?php

declare(strict_types=1);

namespace Locally\Domain;

use Exception;

final class CheckoutException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
