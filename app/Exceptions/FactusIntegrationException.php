<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class FactusIntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $clientStatus = 502,
        private readonly ?int $externalStatus = null,
    ) {
        parent::__construct($message);
    }

    public function clientStatus(): int
    {
        return $this->clientStatus;
    }

    public function externalStatus(): ?int
    {
        return $this->externalStatus;
    }
}
