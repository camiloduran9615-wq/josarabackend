<?php

declare(strict_types=1);

namespace App\Services\Periodo;

class PreCierreFallidoException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly array $checklist,
    ) {
        parent::__construct($message);
    }
}
