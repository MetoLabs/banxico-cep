<?php

declare(strict_types=1);

namespace MetoLabs\Banxico\Cep\Exception;

use MetoLabs\Banxico\Cep\ErrorCode;
use RuntimeException;
use Throwable;

class CepException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
