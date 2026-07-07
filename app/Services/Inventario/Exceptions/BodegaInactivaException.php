<?php

declare(strict_types=1);

namespace App\Services\Inventario\Exceptions;

use RuntimeException;

class BodegaInactivaException extends RuntimeException
{
    public function __construct(string $bodegaId)
    {
        parent::__construct("La bodega {$bodegaId} está inactiva y no acepta movimientos.");
    }
}
